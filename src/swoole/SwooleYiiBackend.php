<?php

namespace wen202402\common\swoole;

use Swoole\Timer;
use yii\base\InvalidConfigException;



//\Swoole\Runtime::enableCoroutine();


class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;
    public $appName = 'backend';

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        parent::onWorkerStart($server, $workerId);



            $workerNum  = (int)($server->setting['worker_num'] ?? 0);
            $workerType = $workerId >= $workerNum ? 'task-worker' : 'worker';

            $this->log(sprintf('%s started. id=%d pid=%d', $workerType, $workerId, getmypid()), 'info');


            if ($workerId !== 0) return;

            Timer::tick(15 * 60 * 1000, function () {

                \Swoole\Coroutine::create(function () {
                    try {
                        error_log(__FUNCTION__.' start variable: ' . date('Y-m-d H:i:s'));

                        $exitCode = $this->app->runAction('cron/contrab/variable');
                        error_log(__FUNCTION__.' end variable: ' . $exitCode);
                    } catch (\Throwable $e) {
                        error_log('variable error: ' . $e->getMessage());
                    }
                });
            });


            Timer::tick(60 * 1000, function () {
                \Swoole\Coroutine::create(function () {
                    try {
                        error_log(__FUNCTION__.' start ribao: ' . date('Y-m-d H:i:s'));
                        $exitCode = $this->app->runAction('cron/contrab/ribao', ['order']);
                        error_log(__FUNCTION__.' end ribao: ' . $exitCode);
                    } catch (\Throwable $e) {
                        error_log('ribao error: ' . $e->getMessage());
                    }
                });
            });



    }


}
