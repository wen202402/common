<?php

namespace wen202402\common\di;


use Swoole\Coroutine;
use yii\base\ExitException;


class WxApplication extends \yii\web\Application{

    private static $_contextStore = [];

    public function __construct($config = []){

        parent::__construct($config);

        if (($cid = Coroutine::getCid()) > 0) {

            self::$_contextStore[$cid] = $this;

            Yii::$app = $this;
        } else     Yii::$app = $this;

    }


    public static function getCurrentApp(){
        $cid = Coroutine::getCid();
        if ($cid > 0 && isset(self::$_contextStore[$cid])) return self::$_contextStore[$cid];
        return Yii::$app;
    }

    public function run(){
        try {return parent::run();} catch (ExitException $e) {return $e->statusCode;} finally {
            if (($cid = Coroutine::getCid()) > 0) unset(self::$_contextStore[$cid]);
        }
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


    public function reset(){

        if ($this->has('user', true)) $this->get('user')->logout(false);



        if ($this->has('session', true)) $this->get('session')->close();



        if ($this->has('request', true)) {
            $this->request->setBodyParams([]);
            $this->request->setQueryParams([]);
        }

        if ($this->has('response', true)) $this->response->clear();



        // $this->log->flush();
    }
}
