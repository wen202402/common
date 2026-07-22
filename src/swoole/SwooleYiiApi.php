<?php

namespace wen202402\common\swoole;

use Throwable;
use wen202402\common\helper\FileHelper;
use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;
class SwooleYiiApi extends BaseObject{
    public $host     = '0.0.0.0';
    public $port     = 48000;
    public $mode     = SWOOLE_PROCESS;
    public $sockType = SWOOLE_SOCK_TCP;
    public $document_root ;


    public $options = [
        'pid_file' =>  'api/runtime/swoole.pid',
        'log_file' =>    'api/runtime/swoole.log',
        'worker_num' => 2,                                                           //建议：部署后用监控工具（如 Prometheus + Grafana）观测数据库连接数和 Redis 内存占用，再根据实际情况调整 Worker 数量。
        'daemonize' => false,                                                                                           //开启进程守护避免代码崩溃后退出
        'enable_static_handler' => true,                                                                               //后端一定要开启
        'document_root' =>   '',
        'enable_coroutine' => false,
        'open_mqtt_protocol' => false,
        'open_tcp_nodelay' => true,
        'max_request' => 10000,
        'dispatch_mode' => 2,
        'log_level' => 0,
        'user' => 'www',
        'group' => 'www',
    ];


    public $app = [];

    /**
     * @var \Swoole\Http\Server
     */
    public $server;

    public function init(){
        parent::init();
        if (empty($app=$this->app)) throw new InvalidConfigException('The "app" property must be set.');
        FileHelper::chmod755($app['aliases']['@webroot'] . DIRECTORY_SEPARATOR . 'assets');
        FileHelper::chmod755($app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'modules');
        FileHelper::chmod755($app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'runtime');
        FileHelper::chmod755($app['aliases']['@console'] . DIRECTORY_SEPARATOR . 'runtime');
        $this->options['pid_file']=  ($docroot = $this->document_root . DIRECTORY_SEPARATOR) . 'api/runtime/swoole.pid';
        $this->options['log_file']= $docroot . 'api/runtime/swoole.log';
        $this->options['worker_num']=   (int)(swoole_cpu_num() * 0.6) ?: 2;                                                          //建议：部署后用监控工具（如 Prometheus + Grafana）观测数据库连接数和 Redis 内存占用，再根据实际情况调整 Worker 数量。
        $this->options['document_root']= $docroot . 'api' . DIRECTORY_SEPARATOR . 'web';


        if (!$this->server instanceof \Swoole\Http\Server) {
            $this->server = new \Swoole\Http\Server($this->host, $this->port, $this->mode, $this->sockType);
            $this->server->set($this->options);
        }

        foreach ($this->events() as $event => $callback) $this->server->on($event, $callback);

    }

    public function events()
    {
        return [
            'start'       => [$this, 'onStart'],
            'workerStart' => [$this, 'onWorkerStart'],
            'workerError' => [$this, 'onWorkerError'],
            'request'     => [$this, 'onRequest'],
            'task'        => [$this, 'onTask']
        ];
    }

    public function start()
    {
        return $this->server->start();
    }

    public function onStart(\Swoole\Http\Server $server){

        printf("listen on http://%s:%d\n", $server->host, $server->port);




    }

    public function onWorkerStart(\Swoole\Http\Server $server, $workerId){



        // if ($workerId === 0) Swoole\Timer::tick(10000, function(){});         //定时

    }



    public function onWorkerError(\Swoole\Http\Server $server, $workerId, $workerPid, $exitCode, $signal){
        fprintf(STDERR, "worker error. id=%d pid=%d code=%d signal=%d\n", $workerId, $workerPid, $exitCode, $signal);
    }





    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response){
        try {
            $_GET    = $request->get ?? [];
            $_POST   = $request->post ?? [];
            $_FILES  = $request->files ?? [];
            $_COOKIE = $request->cookie ?? [];

            if (isset($request->server)) foreach ($request->server as $k => $v) $_SERVER[strtoupper($k)] = $v;
            if (isset($request->header)) foreach ($request->header as $k => $v) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;

            foreach ($request->server as $key => $value) $_SERVER[strtoupper($key)] = $value;

            $_SERVER['SCRIPT_NAME']     =   $scriptName = $request->server['script_name'] ?? '/index.php';
            $_SERVER['SCRIPT_FILENAME'] = Yii::getAlias('@webroot'.$scriptName, false) ?: ($this->document_root . $scriptName);
            $application = new \yii\web\Application($this->app);
            $application->init();

            if (method_exists(Yii::$app->request, 'setRequest')) Yii::$app->request->setRequest($request);
            if (method_exists(Yii::$app->response, 'setResponse')) Yii::$app->response->setResponse($response);
            $application->run();
            if (Yii::$app && Yii::$app->has('session') && Yii::$app->session->getIsActive()) Yii::$app->session->close();
        } catch (\Throwable $e) {
            if (isset(\Yii::$app->errorHandler))  \Yii::$app->errorHandler->handleException($e);
        }

    }




    public function onTask(\Swoole\Http\Server $server, $taskId, $workerId, $data){
        try {
            $handler = $data[0];
            $params  = $data[1] ?? [];
            list($class, $action) = $handler;
            $obj = new $class();
            return call_user_func_array([$obj, $action], $params);
        } catch (Throwable $e) {
            if (Yii::$app && Yii::$app->errorHandler) Yii::$app->errorHandler->handleException($e);
            return 1;
        }
    }
}
