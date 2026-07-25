<?php
namespace wen202402\common\helper;

use Yii;

class I8n{



    public static function common($message, $params = [], $language = null){
       return Yii::t("common",$message,$params,$language);
    }



    public static function app($message, $params = [], $language = null){
        return Yii::t("app",$message,$params,$language);
    }








    public static function model($message, $params = [], $language = null){
        return Yii::t("model",$message,$params,$language);
    }









// I8n::api($this->message,['before'=>$this->before,]);
    public static function api($message, $params = [], $language = null){
        return Yii::t("api",$message,$params,$language);
    }








    public static function admin($message, $params = [], $language = null){
        return Yii::t("admin",$message,$params,$language);
    }





}