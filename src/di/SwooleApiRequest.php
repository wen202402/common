<?php
namespace wen202402\common\di;




class SwooleApiRequest extends BaseRequest {
    public $csrfParam='_csrf-api';

    public $enableCsrfCookie=false;
    public $enableCsrfValidation=false;
    public $enableCookieValidation = false;

  //  public $cookieValidationKey="0NKw4OTRRY-z7ygTqXKshbPzJNj14psV";                                                      //   echo Yii::$app->security->generateRandomString(32);
    public $parsers=['application/json' => \yii\web\JsonParser::class,];  //   'text/json' => \yii\web\JsonParser::class,


}