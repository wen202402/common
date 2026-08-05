<?php

namespace wen202402\common\swoole;

use Swoole\Timer;
use yii\helpers\ArrayHelper;


//\Swoole\Runtime::enableCoroutine();


abstract class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;
    public $appName = 'backend';
    public $console = [];

    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void{
        parent::onWorkerStart($server, $workerId);
        if ($workerId !== 0) return;
        $document_root=$this->document_root.DIRECTORY_SEPARATOR;
        $console =  array_merge(
            require_once $document_root . 'common/config/main.php',
            require_once $document_root . 'common/config/main-local.php',
            require_once $document_root . 'console/config/main.php',
            require_once $document_root . 'console/config/main-local.php'
        );

        Timer::tick(1*60 * 1000, function () use ($console) {$this->actionRibao($console);});
        Timer::tick(1 * 1000, function () use ($console) {$this->actionFund($console); });

    }
   abstract public function actionFund($console);



    abstract public function actionRibao($console);




}
