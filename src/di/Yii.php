<?php

namespace wen202402\common\di;

use Swoole\Coroutine;
use yii\BaseYii;

class Yii extends BaseYii {



        public static function getApp(){
            return Coroutine::getCid()>0?( Coroutine::getContext()->app??null): self::$app;
        }



    public static function setApp($value){
         Coroutine::getCid()>0? Coroutine::getContext()->app=$value: self::$app=$value;
    }




}
