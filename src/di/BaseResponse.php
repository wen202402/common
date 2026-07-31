<?php

namespace wen202402\common\di;

use Yii;
use yii\web\Cookie;
use yii\web\Response;

class BaseResponse extends Response {

    /**
     * @var \Swoole\Http\Response
     */
    private $_response;

    /**
     * @return \Swoole\Http\Response
     */
    public function getResponse() { return $this->_response; }



    public function setResponse(\Swoole\Http\Response $response) { $this->_response = $response; }




    protected function sendHeaders(){
        foreach ($this->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ($values as $value) $this->getResponse()->header($name, $value);
        }
        $this->getResponse()->status($this->getStatusCode());
        $this->sendCookies();
    }









    protected function sendCookies(){

        if (Yii::$app && Yii::$app->has('session')) {
            $session = Yii::$app->getSession();
            if ($session->getIsActive() && $session->getId() !== '') {
                $params = $session->getCookieParams();
                $this->getCookies()->add(new Cookie(['name' => $session->name, 'value' => $session->getId(), 'expire' => isset($params['lifetime']) && $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0, 'path' => $params['path'] ?? '/', 'domain' => $params['domain'] ?? '', 'secure' => $params['secure'] ?? false, 'httpOnly' => $params['httpOnly'] ?? true,]));
            }
        }


        foreach ($this->getCookies() as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && Yii::$app->getRequest()->enableCookieValidation) $value = Yii::$app->getSecurity()->hashData(serialize([$cookie->name, $value]), Yii::$app->getRequest()->cookieValidationKey);

            $this->getResponse()->cookie($cookie->name, $value, $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
        }
    }








    public function send(){
        if ($this->isSent) return;
        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_PREPARE);
        $this->sendHeaders();
        $this->sendContent();
        $this->trigger(self::EVENT_AFTER_SEND);
        $this->isSent = true;
    }

    protected function sendContent(){
        if ($this->stream === null) {
            $this->getResponse()->end($this->content);
            return;
        }

        set_time_limit(0);                                                                //todo
        $chunkSize = 8 * 1024 * 1024;

        if (is_array($this->stream)) {
            list($handle, $begin, $end) = $this->stream;
            fseek($handle, $begin);
            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                if ($pos + $chunkSize > $end) $chunkSize = $end - $pos + 1;
                $this->getResponse()->write(fread($handle, $chunkSize));
            }
            fclose($handle);
        } else {
            while (!feof($this->stream)) $this->getResponse()->write(fread($this->stream, $chunkSize));

            fclose($this->stream);
        }
        $this->getResponse()->end();
    }














}
