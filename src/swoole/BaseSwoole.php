<?php

namespace wen202402\common\swoole;

use Throwable;
use wen202402\common\di\Application;
use wen202402\common\helper\FileHelper;
use wen202402\common\helper\IPHelper;
use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;

class BaseSwoole extends BaseObject{
    public $mode     = SWOOLE_PROCESS;
    public $sockType = SWOOLE_SOCK_TCP;
    public $document_root ;
    public $host     = '0.0.0.0';
    public $cpu =0.6;
    public $options = [
        'pid_file' =>  'backend/runtime/swoole.pid',
        'log_file' =>    'backend/runtime/swoole.log',
        'worker_num' => 1,                                                           //建议：部署后用监控工具（如 Prometheus + Grafana）观测数据库连接数和 Redis 内存占用，再根据实际情况调整 Worker 数量。
        'daemonize' => false,                                                                                           //开启进程守护避免代码崩溃后退出
        'enable_static_handler' => true,                                                                               //后端一定要开启
        'document_root' =>   '',
        'enable_coroutine' => true,
        'open_mqtt_protocol' => false,
        'open_tcp_nodelay' => true,
        'max_request' => 20000,                                                                                               //偶然重启释放点内存
        'dispatch_mode' => 2,                                                                                               //模式1（固定/负载相关的分发） 模式2（轮询/均衡分发） 模式 3（自定义/更严格的映射分发，取决于版本）
        'log_level' => SWOOLE_LOG_WARNING,
        'user' => 'www',
        'group' => 'www',
    ];

    public $app = [];

    /**
     * @var \Swoole\Http\Server
     */
    public $server;

    /*   public function events(){
           return [
               'start'       => [$this, 'onStart'],
               'workerStart' => [$this, 'onWorkerStart'],
               'workerError' => [$this, 'onWorkerError'],
               'request'     => [$this, 'onRequest'],
               'task'        => [$this, 'onTask']
           ];
       }*/


    public function events(): array
    {
        return [
            'start' => [$this, 'onStart'],
            'managerStart' => [$this, 'onManagerStart'],
            'workerStart' => [$this, 'onWorkerStart'],
            'request' => [$this, 'onRequest'],
            'Close' => [$this, 'onClose'],
            'task' => [$this, 'onTask'],
            'finish' => [$this, 'onFinish'],
            'pipeMessage' => [$this, 'onPipeMessage'],
            'beforeReload' => [$this, 'onBeforeReload'],
            'afterReload' => [$this, 'onAfterReload'],
            'workerStop' => [$this, 'onWorkerStop'],
            'workerError' => [$this, 'onWorkerError'],
            'managerStop' => [$this, 'onManagerStop'],
            'shutdown' => [$this, 'onShutdown'],
        ];
    }




    public function start(){
        return $this->server->start();
    }




    public function onStart(\Swoole\Http\Server $server){
        $app=$this->app;
        FileHelper::chmod755($app['aliases']['@webroot'] . DIRECTORY_SEPARATOR . 'assets');
        FileHelper::chmod755($app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'modules');
        FileHelper::chmod755($app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'runtime');
        FileHelper::chmod755($app['aliases']['@console'] . DIRECTORY_SEPARATOR . 'runtime');
        // $this->getIP();
        printf("listen on http://%s:%d\n", trim(IPHelper::getServerIp())?: $server->host, $server->port);

    }




    public function init(){
        parent::init();
        if (empty($app=$this->app)) throw new InvalidConfigException('The "app" property must be set.');
        $this->setOption();
        if (!$this->server instanceof \Swoole\Http\Server) {
            $this->server = new \Swoole\Http\Server($this->host, $this->port, $this->mode, $this->sockType);
            $this->server->set($this->options);
        }

        foreach ($this->events() as $event => $callback) {
            if (!is_callable($callback))continue;
            if (!$this->server->on($event, $callback)) throw new InvalidConfigException(sprintf('Swoole event "%s" bind failed.', $event));

        }
    }





    public function setOption(){
        $this->options['pid_file']=  ($docroot = $this->document_root . DIRECTORY_SEPARATOR) . 'api/runtime/swoole.pid';
        $this->options['log_file']= $docroot . 'api/runtime/swoole.log';
        $this->options['worker_num']=   (int)(swoole_cpu_num() *$this->cpu) ?: 2;                                                          //建议：部署后用监控工具（如 Prometheus + Grafana）观测数据库连接数和 Redis 内存占用，再根据实际情况调整 Worker 数量。
        $this->options['document_root']= $docroot . 'api' . DIRECTORY_SEPARATOR . 'web';
    }


    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response){
        $_SERVER['SCRIPT_NAME']     = $scriptName = $request->server['script_name'] ?? '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = Yii::getAlias('@webroot'.$scriptName, false) ?: ($this->document_root . $scriptName);
        $application = new Application($this->app);
        if (method_exists(Yii::$app->request, 'setRequest')) Yii::$app->request->setRequest($request);
        if (method_exists(Yii::$app->response, 'setResponse')) Yii::$app->response->setResponse($response);
        $application->run();
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

    public function onManagerStart(\Swoole\Http\Server $server): void{
      //  printf("manager started. pid=%d\n", getmypid());
    }

    public function onManagerStop(\Swoole\Http\Server $server): void{
      //  printf("manager stopped. pid=%d\n", getmypid());
    }


    public function onWorkerError(\Swoole\Http\Server $server, $workerId, $workerPid, $exitCode, $signal){
      //  fprintf(STDERR, "worker error. id=%d pid=%d code=%d signal=%d\n", $workerId, $workerPid, $exitCode, $signal);
    }



    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{

       // printf("%s started. id=%d pid=%d\n", $workerType = $workerId >= ($workerNum = (int)($server->setting['worker_num'] ?? 0)) ? 'task-worker' : 'worker', $workerId, getmypid());
    }

    public function onWorkerStop(\Swoole\Http\Server $server, int $workerId): void{
        try {
            if (Yii::$app !== null && Yii::$app->has('db') && Yii::$app->db->isActive) Yii::$app->db->close();


            if (Yii::$app !== null && Yii::$app->has('redis')) Yii::$app->redis->close();

        } catch (Throwable $e) {
        //    fprintf(STDERR, "worker cleanup error. id=%d message=%s\n", $workerId, $e->getMessage());
        }

      //  printf("worker stopped. id=%d pid=%d\n", $workerId, getmypid());
    }

    public function onShutdown(\Swoole\Http\Server $server): void{
      //  printf("server shutdown. pid=%d\n", getmypid());
    }




    public function onBeforeReload(\Swoole\Http\Server $server): void{
     //   printf("server before reload. pid=%d\n", getmypid());
    }



    public function onAfterReload(\Swoole\Http\Server $server): void{
    //    printf("server after reload. pid=%d\n", getmypid());
    }

    public function onClose(\Swoole\Http\Server $server, int $fd, int $reactorId): void{
    //    printf("connection closed. fd=%d reactorId=%d\n", $fd, $reactorId);
    }

    public function onFinish(\Swoole\Http\Server $server, int $taskId, mixed $data): void{
       // printf("task finished. taskId=%d result=%s\n", $taskId, var_export($data, true));
    }

    public function onPipeMessage(\Swoole\Http\Server $server, int $srcWorkerId, mixed $data): void{
      //  printf("pipe message received. currentWorkerId=%d srcWorkerId=%d data=%s\n", $server->worker_id, $srcWorkerId, var_export($data, true));
    }
}