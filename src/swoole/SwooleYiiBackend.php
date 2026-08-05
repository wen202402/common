<?php

namespace wen202402\common\swoole;

use Swoole\Timer;
use yii\base\InvalidConfigException;



//\Swoole\Runtime::enableCoroutine();


abstract class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;
    public $appName = 'backend';
    public $console = [];

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        parent::onWorkerStart($server, $workerId);
        if ($workerId !== 0) return;

        Timer::tick(1*60 * 1000, function () {$this->actionRibao('order');});
        Timer::tick(1 * 1000, function () {$this->actionFund(); });

    }
   abstract public function actionFund();



    abstract public function actionRibao();




}
