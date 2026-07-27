<?php

namespace wen202402\common\helper;

use Yii;

class CacheHelper{



    public static function getServerIp(){
        return Yii::$app->cache->get('serverip')?:'';
    }




    public static function setServerIpToCache($ip){
        return   Yii::$app->cache->set('serverip',$ip);
    }
}