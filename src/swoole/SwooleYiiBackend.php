<?php

namespace wen202402\common\swoole;

use Swoole\Timer;
use yii\console\Application;


//\Swoole\Runtime::enableCoroutine();


class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;
    public $appName = 'backend';
    public $console = [];

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        parent::onWorkerStart($server, $workerId);
        if ($workerId !== 0) return;
        error_log(__FUNCTION__."------------------ start----{$workerId}". date('Y-m-d H:i:s').PHP_EOL);
        $app = new Application($this->console);
        Timer::tick(15*60 * 1000, function ()use($app)  {$this->actionRibao($app);});
        Timer::tick(6*60* 1000, function ()use($app) {$this->actionFund($app); });

    }






    public function actionFund(Application $app){
         error_log(__FUNCTION__.'------------------ start----'. date('Y-m-d H:i:s').PHP_EOL);
         $app->runAction('cron/contrab/variable');
    }

    public function actionRibao(Application $app){
        error_log(__FUNCTION__.'------------------ start----'. date('Y-m-d H:i:s').PHP_EOL);
        $app->runAction('cron/contrab/ribao', ['order']);

    }





}
