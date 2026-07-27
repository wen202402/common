<?php

namespace wen202402\common\helper;

use wen202402\common\di\Application;
use Yii;
use yii\httpclient\Client;

class IPHelper
{

    public static function getServerHttp(){
        $client = new Client();

        $response = $client->createRequest()
            ->setMethod('GET')
            ->setUrl('http://ip.sb')
            ->setOptions(['timeout' => 10])
            ->addHeaders(['User-Agent' => 'curl/7.68.0'])
            ->send();

        return $response->isOk? $response->content: false;
    }


    public static function getIP($app,$document_root){
        $_SERVER['SCRIPT_NAME']     =   $scriptName = $request->server['script_name'] ?? '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = Yii::getAlias('@webroot'.$scriptName, false) ?: ($document_root . $scriptName);
        $application = new Application($app);
        $application->init();
        if (!Yii::$app || !Yii::$app->has('cache')) return;

        if (!empty(CacheHelper::getServerIp())) return ;
            try {

                if ($ip = IPHelper::getServerHttp()) CacheHelper::setServerIpToCache($ip);

            } catch (\Throwable $e) {

            }

    }
}