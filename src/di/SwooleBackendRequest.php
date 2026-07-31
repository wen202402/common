<?php
namespace wen202402\common\di;


use wen202402\common\helper\I8n;

use Yii;
use yii\web\ForbiddenHttpException;


class SwooleBackendRequest extends BaseRequest{
    /**
     * @var \Swoole\Http\Request
     */
    private $_request;

    public $enableCookieValidation = false;
    public $csrfParam = '_csrf-backend';

    //  public $parsers = ['application/json' => \yii\web\JsonParser::class,];







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


        if ($get === [] && ($queryString = (string)($server['query_string'] ?? '')) !== '') parse_str($queryString, $get);
        if ($queryString === '' && $get !== []) $queryString = http_build_query($get);


        $requestUri = ($requestPath = parse_url( (string)($server['request_uri'] ?? '/'), PHP_URL_PATH) ?: '/') . ($queryString !== '' ? '?' . $queryString : '');

        $scriptFilename = ($documentRoot = rtrim((string)$document_root, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'index.php';

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

        $host = (string)($headers['host'] ?? $server['server_name'] ?? $this->host);
        $forwardedProto = strtolower((string)($headers['x-forwarded-proto'] ?? ''));
        $https = $forwardedProto === 'https' || (int)($server['server_port'] ?? 0) === 443;

        $_SERVER['REQUEST_METHOD'] = strtoupper((string)($server['request_method'] ?? 'GET'));
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $scriptFilename;
        $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
        $_SERVER['HTTP_HOST'] = $host;
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

   /* protected function setupGlobalVars(): void{
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_SERVER = [];
        $_SERVER['SCRIPT_NAME']     = $scriptName = $request->server['script_name'] ?? '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = Yii::getAlias('@webroot'.$scriptName, false);
        $server = $this->_request->server ?? [];
        $headers = $this->_request->header ?? [];

        $get = $this->_request->get ?? [];
        $post = $this->_request->post ?? [];
        $files = $this->_request->files ?? [];
        $cookies = $this->_request->cookie ?? [];
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        $_COOKIE = $cookies;


        foreach ($server as $key => $value) $_SERVER[strtoupper($key)] = $value;
        foreach ($headers as $key => $value) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;


        $this->getSecureForwardedHeaderParts();
        $this->getCookies();
        $this->setQueryParams($get);
        $this->getAbsoluteUrl();

        $this->getBodyParams();
        $this->setRawBody($this->_request->rawContent() ?: '');



        $this->getPathInfo();
        $this->resetCounter();
        Yii::$app->response->clear();
    }*/


    private function resetCounter(){
        $ref = new \ReflectionClass(\yii\data\BaseDataProvider::class);
        $prop = $ref->getProperty('counter');
        $prop->setValue(0);

    }



    const refuse = [
        'CensysInspect',
    ];


    const waf = [
        'select([\s\S]*?)(from|limit)',
        '(?:(union([\s\S]*?)select))',
        'having|updatexml|extractvalue',
        '(?:from\W+information_schema\W)',
        '(?:(?:current_)user|database|schema|connection_id)\s*\(',
        '\.\./',                                                                  //禁用包含 ../ 的参数
        '\<\?',                                                                  //禁止php脚本出现
        '\s*or\s+.*=.*',                                                    //匹配' or 1=1 ,防止sql注入
        'sleep\((\s*)(\d*)(\s*)\)',                                        //防止sql盲注
        'benchmark\((.*)\,(.*)\)',                                           //防止sql盲注
        'base64_decode\(',                                                    //防止sql变种注入
        '(?:etc\/\W*passwd)',                                                //防止窥探linux用户信息
        'into(\s+)+(?:dump|out)file\s*',                                  //禁用mysql导出函数
        'group\s+by.+\(',
        '(?:define|eval|file_get_contents|include|require|require_once|shell_exec|phpinfo|system|passthru|preg_\w+|execute|echo|print|print_r|var_dump|(fp)open|alert|showmodaldialog)\(', //禁用webshell相关某些函数
        '(gopher|doc|php|glob|file|phar|zlib|ftp|ldap|dict|ogg|data)\:\/',                  //防止一些协议攻击
        '\$_(GET|post|cookie|files|session|env|phplib|GLOBALS|SERVER)\[',                    //禁用一些内置变量,建议自行修改
        '\<(iframe|script|body|img|layer|div|meta|style|base|object|input)',            //防止xss标签植入
        '(onmouseover|onerror|onload|onclick)\=',                                   //防止xss事件植入
        '\|\|.*(?:ls|pwd|whoami|ll|ifconfog|ipconfig|&&|chmod|cd|mkdir|rmdir|cp|mv)', //防止执行shell
        '\s*and\s+.*=.*'                                                             //匹配 and 1=1
    ];


    const startWith=[
        ".env",
        "vendor/phpunit/phpunit/src/",
        "wp-includes",
        "wordpress",
        "wp-conten",
        "dns-query",
        "ds_store",
        "cms/",
        "oam/server/opensso",
        "wp-includes",
        //    "baidu.com",
    ];






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