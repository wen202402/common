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


    /**
     * 可信代理地址/IP。建议改为网段或反代的固定 IP。
     * 例：['127.0.0.1', '10.0.0.0/8', '192.168.1.0/24']
     */

    public static  $trustedProxies = ['127.0.0.1'];
     public static function isTrustedIp(string $ip)   {
            foreach (static::$trustedProxies as $tp) {
                if (!is_string($tp) || $tp === '') continue;
                $tp = trim($tp);

                if ($tp === $ip) return true;
                if (!str_contains($tp, '/')) continue;
                [$net, $mask] = explode('/', $tp, 2);
                if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                if (!ctype_digit((string)$mask)) continue;
                $mask = (int)$mask;
                if ($mask < 0 || $mask > 32) continue;
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;

                $ipLong  = ip2long($ip);
                $netLong = ip2long($net);
                if ($ipLong === false || $netLong === false) continue;

                $netMask = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1));
                if (($ipLong & $netMask) === ($netLong & $netMask)) return true;

               }
             return false;
            }








}

