<?php

namespace wen202402\common\di;

use wen202402\common\helper\I8n;
use Yii;
use yii\web\ForbiddenHttpException;

class BaseRequest  extends \yii\web\Request{
    /**
     * @var \Swoole\Http\Request
     */
    private $_request;

    public $enableCookieValidation = true;

    public $parsers = ['application/json' => \yii\web\JsonParser::class,];


    /**
     * @return \Swoole\Http\Request
     */
    public function getRequest(){
        return $this->_request;
    }





    /**
     * @param \Swoole\Http\Request $request
     */
    public function setRequest($request,$document_root){
        $this->_request = $request;
        $this->setupHeaders();
        $this->setupGlobalVars($request,$document_root);
    }


    protected function setupHeaders(){
        $this->headers->removeAll();
        foreach ($this->_request->header as $name => $value) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
            $this->headers->add($name, $value);
        }
    }




    protected function setupGlobalVars(\Swoole\Http\Request $request,$document_root): void{
        $server = is_array($request->server ?? null) ? $request->server : [];
        $headers = is_array($request->header ?? null) ? $request->header : [];

        $get = is_array($request->get ?? null) ? $request->get : [];
        $post = is_array($request->post ?? null) ? $request->post : [];
        $files = is_array($request->files ?? null) ? $request->files : [];
        $cookies = is_array($request->cookie ?? null) ? $request->cookie : [];

        $queryString = (string)($server['query_string'] ?? '');

        if ($get === [] && $queryString !== '') parse_str($queryString, $get);
        if ($queryString === '' && $get !== []) $queryString = http_build_query($get);

        $requestPath = (string)($server['request_uri'] ?? '/');
        $requestPath = parse_url($requestPath, PHP_URL_PATH) ?: '/';
        $requestUri = $requestPath . ($queryString !== '' ? '?' . $queryString : '');



        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        $_COOKIE = $cookies;
        $_SERVER = [];

        foreach ($server as $name => $value) $_SERVER[strtoupper($name)] = $value;

        foreach ($headers as $name => $value) {
            $serverName = strtoupper(str_replace('-', '_', $name));

            if ($serverName === 'CONTENT_TYPE' || $serverName === 'CONTENT_LENGTH') {
                $_SERVER[$serverName] = $value;
                continue;
            }

            $_SERVER['HTTP_' . $serverName] = $value;
        }


        $forwardedProto = strtolower((string)($headers['x-forwarded-proto'] ?? ''));
        $https = $forwardedProto === 'https' || (int)($server['server_port'] ?? 0) === 443;

        $_SERVER['REQUEST_METHOD'] = strtoupper((string)($server['request_method'] ?? 'GET'));
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] =   $scriptFilename = ($documentRoot = rtrim((string)$document_root, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'index.php';;
        $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
        $_SERVER['HTTP_HOST'] = $host = (string)($headers['host'] ?? $server['server_name'] ?? '');
        $_SERVER['SERVER_NAME'] = preg_replace('/:\d+$/', '', $host);
        $_SERVER['SERVER_PORT'] = (string)($server['server_port'] ?? ($https ? 443 : 80));
        $_SERVER['SERVER_PROTOCOL'] = (string)($server['server_protocol'] ?? 'HTTP/1.1');
        $_SERVER['REMOTE_ADDR'] = (string)($server['remote_addr'] ?? '');
        $_SERVER['REMOTE_PORT'] = (string)($server['remote_port'] ?? '');
        $_SERVER['REQUEST_SCHEME'] = $https ? 'https' : 'http';
        $_SERVER['HTTPS'] = $https ? 'on' : 'off';

        //    unset($_SERVER['PATH_INFO']);
        $this->getSecureForwardedHeaderParts();
        $this->getCookies();
        $this->getAbsoluteUrl();

        $this->getBodyParams();
        $this->setRawBody($this->_request->rawContent() ?: '');



        $this->getPathInfo();
        $this->resetCounter();
        Yii::$app->response->clear();



    }

    private function resetCounter(){
        $ref = new \ReflectionClass(\yii\data\BaseDataProvider::class);
        $prop = $ref->getProperty('counter');
        $prop->setValue(0);

    }


    public function handleFailure(){

        header("http/1.1 403 Forbidden");
        http_response_code(403);
        throw new ForbiddenHttpException(I8n::api('forbidden') );
        //   exit();
    }


    public function postX($name = null,$expect=[], $defaultValue = null){
        $posts= $name === null? $this->getBodyParams():  $this->getBodyParam($name, $defaultValue);
        if (empty($expect))return $posts;
        foreach ($expect as $v) if (isset($posts[$v])) unset($posts[$v]);
        return $posts;
    }

    public function postJson($name = null,$expect=[], $defaultValue = null){
        $posts= $name === null? $this->getBodyParams():  $this->getBodyParam($name, $defaultValue);
        if (empty($expect))return empty($posts)?'':json_encode($posts);
        foreach ($expect as $v) if (isset($posts[$v])) unset($posts[$v]);
        return empty($posts)?'':json_encode($posts);
    }

}