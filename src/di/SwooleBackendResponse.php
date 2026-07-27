<?php
namespace wen202402\common\di;

use Yii;
use yii\web\Cookie;

class SwooleBackendResponse extends \yii\web\Response{
    private $_response;

    public function getResponse() { return $this->_response; }
    public function setResponse($response) { $this->_response = $response; }

    protected function sendHeaders(){
        foreach ($this->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ($values as $value) $this->_response->header($name, $value);
        }
        $this->_response->status($this->getStatusCode());
        $this->sendCookies();
    }

    protected function sendCookies(){
        try {
            if (Yii::$app && Yii::$app->has('session')) {
                $session = Yii::$app->getSession();
                if ($session && $session->getIsActive() && $session->getId() !== '') {
                    $params = $session->getCookieParams();
                    $this->getCookies()->add(new Cookie(['name' => $session->name,'value' => $session->getId(), 'expire' => isset($params['lifetime']) && $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0,
                        'path' => $params['path'] ?? '/', 'domain' => $params['domain'] ?? '', 'secure' => $params['secure'] ?? false,'httpOnly' => $params['httpOnly'] ?? true,]));
                }
            }
        } catch (\Throwable $e) {
            // 发送阶段兜底：session 不存在/已被清理时不要让它影响输出
            // 这里可以选择不打印，避免刷屏
        }

        foreach ($this->getCookies() as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && Yii::$app->getRequest()->enableCookieValidation) {
                $value = Yii::$app->getSecurity()->hashData(
                    serialize([$cookie->name, $value]),
                    Yii::$app->getRequest()->cookieValidationKey
                );
            }
            $this->_response->cookie(
                $cookie->name, $value, $cookie->expire,
                $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly
            );
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
            $this->_response->end($this->content);
            return;
        }

        set_time_limit(0);                                                                //todo
        $chunkSize = 8 * 1024 * 1024;

        if (is_array($this->stream)) {
            list($handle, $begin, $end) = $this->stream;
            fseek($handle, $begin);
            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                if ($pos + $chunkSize > $end) $chunkSize = $end - $pos + 1;
                $this->_response->write(fread($handle, $chunkSize));
            }
            fclose($handle);
        } else {
            while (!feof($this->stream)) $this->_response->write(fread($this->stream, $chunkSize));

            fclose($this->stream);
        }
        $this->_response->end();
    }
}