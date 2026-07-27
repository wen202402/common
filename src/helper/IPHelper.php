<?php

namespace wen202402\common\helper;

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
}