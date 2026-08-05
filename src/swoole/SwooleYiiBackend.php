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
        $document_root=$this->document_root.DIRECTORY_SEPARATOR;
        $main=    require_once $document_root . 'common/config/main.php';
        $main_local=   require_once $document_root . 'common/config/main-local-swoole.php';
        $backend=      require_once $document_root . 'backend/config/main.php';
        $backend_local=    require_once $document_root . 'backend/config/main-local.php';
        $console = $this->merge($main,$main_local,$backend,$backend_local);
        Timer::tick(1*60 * 1000, function () use ($console) {$this->actionRibao($console);});
        Timer::tick(1 * 1000, function () use ($console) {$this->actionFund($console); });
        unset($main,$main_local,$backend,$backend_local);

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


abstract public function actionFund($console);



    abstract public function actionRibao($console);




}
