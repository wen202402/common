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

        Timer::tick(1*60 * 1000, function ()  {$this->actionRibao();});
        Timer::tick(6*60* 1000, function () {$this->actionFund(); });

    }






    public function actionFund(){
         error_log(__FUNCTION__.'------------------ start----'. date('Y-m-d H:i:s').PHP_EOL);
         \Yii::$app->runAction('login/login/variable');
    }

    public function actionRibao(){
        error_log(__FUNCTION__.'------------------ start----'. date('Y-m-d H:i:s').PHP_EOL);
        \Yii::$app->runAction('login/login/ribao', ['order']);

    }





}
