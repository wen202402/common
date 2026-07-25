<?php

namespace wen202402\common\di;

use Yii;
use yii\web\Cookie;
use yii\web\Response;

class SwooleApiResponse extends Response {
    private $_response;
    public function getResponse() { return $this->_response; }
    public function setResponse($response) { $this->_response = $response; }

    protected function sendHeaders(){
        foreach ($this->getHeaders() as $name => $values) {
            $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
            foreach ($values as $value) $this->_response->header($name, $value);
        }
        $this->_response->status($this->getStatusCode());

    }

    protected function sendCookies(){

        if (Yii::$app && Yii::$app->has('session')) {
            $session = Yii::$app->getSession();
            if ($session->getIsActive() && $session->getId() !== '') {
                $params = $session->getCookieParams();
                $this->getCookies()->add(new Cookie([
                    'name' => $session->name,
                    'value' => $session->getId(),
                    'expire' => isset($params['lifetime']) && $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => $params['secure'] ?? false,
                    'httpOnly' => $params['httpOnly'] ?? true,
                ]));
            }
        }


        foreach ($this->getCookies() as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && Yii::$app->getRequest()->enableCookieValidation) $value = Yii::$app->getSecurity()->hashData(serialize([$cookie->name, $value]), Yii::$app->getRequest()->cookieValidationKey);

            $this->_response->cookie($cookie->name, $value, $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
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

        set_time_limit(0);
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
            while (!feof($this->stream)) {
                $this->_response->write(fread($this->stream, $chunkSize));
            }
            fclose($this->stream);
        }
        $this->_response->end();
    }
    public static $httpStatuses = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        118 => 'Connection timed out',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        210 => 'Content Different',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Reserved',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        310 => 'Too many Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested range unsatisfiable',
        417 => 'Expectation failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable entity',
        423 => 'Locked',
        424 => 'Method failure',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        449 => 'Retry With',
        450 => 'Blocked by Windows Parental Controls',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway or Proxy Error',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        507 => 'Insufficient storage',
        508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
      //  512 => 'Validator Error',
    ];





}