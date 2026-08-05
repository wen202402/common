<?php

namespace wen202402\common\swoole;

use Swoole\Timer;



//\Swoole\Runtime::enableCoroutine();


abstract class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;
    public $appName = 'backend';
    public $console = [];

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        parent::onWorkerStart($server, $workerId);
        if ($workerId !== 0) return;
        
        Timer::tick(1*60 * 1000, function ()  {$this->actionRibao();});
        Timer::tick(1 * 1000, function () {$this->actionFund(); });


    }





    public  function merge(array ...$arrays): array{
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $k => $v) {
                if (is_int($k)) $result[] = $v;
                else   $result[$k] = $v;
            }
        }
        return $result;
    }


    abstract public function actionFund();



    abstract public function actionRibao();




}
