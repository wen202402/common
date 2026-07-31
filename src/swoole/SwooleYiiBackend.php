<?php

namespace wen202402\common\swoole;

use Throwable;




//\Swoole\Runtime::enableCoroutine();
class SwooleYiiBackend extends BaseSwoole{

    public $port     = 58000;







    public function setOption(){
        $this->options['pid_file']=  ($docroot = $this->document_root . DIRECTORY_SEPARATOR) . 'backend/runtime/swoole.pid';
        $this->options['log_file']= $docroot . 'backend/runtime/swoole.log';
        $this->options['worker_num']=   (int)(swoole_cpu_num() *$this->cpu) ?: 2;                                                          //建议：部署后用监控工具（如 Prometheus + Grafana）观测数据库连接数和 Redis 内存占用，再根据实际情况调整 Worker 数量。
        $this->options['document_root']= $docroot . 'backend' . DIRECTORY_SEPARATOR . 'web';


    }







}
