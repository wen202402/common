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
    public $document_root;
    public $appName = 'backend';
    public $host     = '0.0.0.0';
    public $cpu =0.6;
    public $options  = [
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



    public  $logLevel='warning';


    public function events(): array {
        return [
            'start'          => [$this, 'onStart'],
            'managerStart'   => [$this, 'onManagerStart'],
            'workerStart'    => [$this, 'onWorkerStart'],
            'request'        => [$this, 'onRequest'],
            'close'          => [$this, 'onClose'],
            'task'           => [$this, 'onTask'],
            'finish'         => [$this, 'onFinish'],
            'pipeMessage'    => [$this, 'onPipeMessage'],
            'beforeReload'   => [$this, 'onBeforeReload'],
            'afterReload'    => [$this, 'onAfterReload'],
            'workerStop'     => [$this, 'onWorkerStop'],
            'workerError'    => [$this, 'onWorkerError'],
            'managerStop'    => [$this, 'onManagerStop'],
            'shutdown'       => [$this, 'onShutdown'],
        ];
    }



    public function start(){
        return $this->server->start();
    }





    public function startQueue(){
    }






    public function onStart(\Swoole\Http\Server $server){
        $this->makeDir();

        $this->log(sprintf('listen on http://%s:%d', trim(IPHelper::getServerIp())?: $server->host, $server->port));
        $this->log(sprintf('listen on http://%s:%d', $server->host, $server->port));
    }


    public function makeDir(){
        FileHelper::chmod755($this->app['aliases']['@webroot'] . DIRECTORY_SEPARATOR . 'assets');
        FileHelper::chmod755($this->app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'modules');
        FileHelper::chmod755($this->app['aliases']['@app'] . DIRECTORY_SEPARATOR . 'runtime');
        FileHelper::chmod755($this->app['aliases']['@console'] . DIRECTORY_SEPARATOR . 'runtime');
    }

    public function init(){
        parent::init();
        if (empty($app=$this->app)) throw new InvalidConfigException('The "app" property must be set.');
        $this->setOption();
        $this->startQueue();


        if (!$this->server instanceof \Swoole\Http\Server) {
            $this->server = new \Swoole\Http\Server($this->host, $this->port, $this->mode, $this->sockType);
            $this->server->set($this->options);
        }


        foreach ($this->events() as $event => $callback) {
            if (!is_callable($callback))continue;
            if (!$this->server->on($event, $callback)) throw new InvalidConfigException(sprintf('Swoole event "%s" bind failed.', $event));

        }
    }









    public function setOption(): void {
        $appRoot                        = ($docroot = rtrim($this->document_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) . $this->appName . DIRECTORY_SEPARATOR;
        $this->options['pid_file']      = $appRoot . 'runtime/swoole.pid';
        $this->options['log_file']      = $appRoot . 'runtime/swoole.log';
        $this->options['worker_num']    = (int)(swoole_cpu_num() * $this->cpu) ?: 2;
        $this->options['document_root'] = $appRoot . 'web';
    }







//SCRIPT_NAME=/index.php  SCRIPT_FILENAME=/home/ubuntux/Desktop/all/yii-docker/php/backend/web/index.php

    public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response){
        $_SERVER['SCRIPT_NAME']     = $scriptName = $request->server['script_name'] ?? '/index.php';
        $document_root=$this->document_root.DIRECTORY_SEPARATOR.$this->appName;
        $_SERVER['SCRIPT_FILENAME'] = Yii::getAlias('@webroot'.$scriptName, false) ?: ($document_root. $scriptName);
        $application = new Application($this->app);
        if (method_exists($application->request, 'setRequest')) $application->request->setRequest($request,$document_root);
        if (method_exists($application->response, 'setResponse')) $application->response->setResponse($response);
        $application->run();
        Yii::$app=null;
        unset($application);

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


    public function onWorkerStop(\Swoole\Http\Server $server, int $workerId): void{
        try {
            if (Yii::$app !== null && Yii::$app->has('db') && Yii::$app->db->isActive) Yii::$app->db->close();
            if (Yii::$app !== null && Yii::$app->has('redis')) Yii::$app->redis->close();
        } catch (Throwable $e) { $this->log(sprintf('worker cleanup error. id=%d message=%s', $workerId, $e->getMessage()));}

        $this->log(sprintf('worker stopped. id=%d pid=%d', $workerId, getmypid()));
    }

    public function onClose(\Swoole\Http\Server $server, int $fd, int $reactorId): void{
        $this->log(sprintf('connection closed. fd=%d reactorId=%d', $fd, $reactorId),'info');
    }









    public function onFinish(\Swoole\Http\Server $server, int $taskId, mixed $data): void{
        $this->log(sprintf('task finished. taskId=%d result=%s', $taskId, var_export($data, true)));
    }

    public function onBeforeReload(\Swoole\Http\Server $server): void{
        $this->log(sprintf('server before reload. pid=%d', getmypid()));
    }

    public function onAfterReload(\Swoole\Http\Server $server): void{
        $this->log(sprintf('server after reload. pid=%d', getmypid()));
    }




    public function onShutdown(\Swoole\Http\Server $server): void{
        $this->log(sprintf('server shutdown. pid=%d', getmypid()));
    }





    public function startProcess($cmd,$jobs,$procs,$pids){
        $procs = ['queue' => null, 'cron'  => null,];
        $pids  = ['queue' => null, 'cron'  => null,];
        $startJob = function(string $name) use ($cmd, $jobs, &$procs, &$pids) {
            $args = $jobs[$name]['args'];
            $proc = new \Swoole\Process(function(\Swoole\Process $p) use ($cmd, $args) {$p->exec($cmd, $args);});
            $proc->useQueue();
            $procs[$name] = $proc;
            $pid = $proc->start();
            $pids[$name] = $pid;
            error_log(ucfirst($name) . " 已启动，PID=" . $pid . "\n");
        };


        foreach (array_keys($jobs) as $name) $startJob($name);
        foreach (array_keys($jobs) as $name) {
            if ($procs[$name] === null) {
                $startJob($name);
                continue;
            }


            $pid = $pids[$name];
            if (!$pid) {
                $startJob($name);
                continue;
            }

            $alive = false;
            if (function_exists('posix_kill')) {
                // 0 表示不发送信号，只做存在性检查
                $alive = (posix_kill((int)$pid, 0) === true);
            }

            if (!$alive) {
                error_log(ucfirst($name) . " 已退出，准备重启\n");
                try {  $procs[$name]->kill(); } catch (\Throwable $e) { }

                $procs[$name] = null;
                $pids[$name]  = null;
                $startJob($name);
            }
        }


    }


    public function onManagerStart(\Swoole\Http\Server $server): void{
        $this->log(sprintf('manager started. pid=%d', getmypid()).'info');

    }

    public function onManagerStop(\Swoole\Http\Server $server): void{
        $this->log(sprintf('manager stopped. pid=%d', getmypid()),'warning');
    }







    public function onWorkerError(\Swoole\Http\Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void{
        $this->log(sprintf('worker error. id=%d pid=%d code=%d signal=%d', $workerId, $workerPid, $exitCode, $signal), 'warning');
    }


    public function log(string $message, string $clevel = 'warning'): void{
        $levels = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5,];

        $clevel = strtolower($clevel);
        $minLevel = strtolower($this->logLevel);

        if (!isset($levels[$clevel])) $clevel = 'info';
        if (!isset($levels[$minLevel])) $minLevel = 'info';
        if ($levels[$clevel] < $levels[$minLevel]) return;

        $workerId = $this->server instanceof \Swoole\Http\Server && isset($this->server->worker_id) ? $this->server->worker_id : '-';

        $content = sprintf("[%s] %s  -------------[%s]--[pid:%d]--[worker:%s]  \n", strtoupper($clevel), $message, date('Y-m-d H:i:s'), getmypid(),$workerId);

        $logFile = $this->options['log_file'] ?? '';

        if ($logFile !== '') error_log($content, 3, $logFile);
        error_log(rtrim($content));
    }












    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        $workerNum = (int)($server->setting['worker_num'] ?? 0);
        $workerType = $workerId >= $workerNum ? 'task-worker' : 'worker';

        $this->log(sprintf('%s started. id=%d pid=%d', $workerType, $workerId, getmypid()),'info');
    }






    public function onPipeMessage(\Swoole\Http\Server $server, int $srcWorkerId, mixed $data): void{
        $this->log(sprintf('pipe message received. currentWorkerId=%d srcWorkerId=%d data=%s', $server->worker_id, $srcWorkerId, var_export($data, true)),'info');
    }













}