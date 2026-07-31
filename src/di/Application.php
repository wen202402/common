<?php

namespace wen202402\common\di;

use Swoole\Coroutine;
use Yii;
use yii\base\ExitException;

class Application extends \yii\web\Application{


    public function __construct($config = []){
        parent::__construct($config);
        Coroutine::getCid()>0? Coroutine::getContext()->app=$this: Yii::$app=$this;
    }

    public function run(){
        try { return parent::run();  } finally {if (Coroutine::getCid()>0)Coroutine::getContext()->app=null;}

    }




    public function end($status = 0, $response = null){
        if ($this->state === self::STATE_BEFORE_REQUEST || $this->state === self::STATE_HANDLING_REQUEST) {
            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);
        }

        if ($this->state !== self::STATE_SENDING_RESPONSE && $this->state !== self::STATE_END) {
            $this->state = self::STATE_END;
            $response = $response ?: $this->getResponse();
            $response->send();
        }

        if (YII_ENV_TEST) throw new ExitException($status);


      return $status;
    }
}