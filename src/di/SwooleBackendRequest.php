<?php
namespace wen202402\common\di;


use wen202402\common\helper\I8n;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;

/**
 * Swoole Request Proxy
 * @package swoole\foundation\web
 */
class SwooleBackendRequest extends \yii\web\Request{

    /**
     * @var \Swoole\Http\Request
     */
    private $_request;


    public $enableCookieValidation = true;
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
    public function setRequest($request, $document_root){
        $this->_request = $request;
        $this->_document_root = $document_root;
        $this->setupHeaders();
        $this->setupGlobalVars();


    }


    protected function setupHeaders(){
        $this->headers->removeAll();
        foreach ($this->_request->header as $name => $value) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
            $this->headers->add($name, $value);
        }
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






    public function setMethod($method){
        $_SERVER['REQUEST_METHOD'] = strtoupper($method ?? 'GET');
    }




    public function setHeaders($headers){
        $yheaders = $this->getHeaders();
        foreach ($headers ?? [] as $name => $value) $yheaders->set($name, $value);
    }





    protected function setupGlobalVars(): void{
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
        $_SERVER = [];

        foreach ($server as $key => $value) $_SERVER[strtoupper($key)] = $value;
        foreach ($headers as $key => $value) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        $requestUri = $server['request_uri'] ?? '/';

        $_SERVER['REQUEST_METHOD'] = strtoupper($server['request_method'] ?? 'GET');

        $_SERVER['QUERY_STRING'] = $queryString = $server['query_string'] ?? http_build_query($get);

        $_SERVER['REQUEST_URI'] = $requestUri . ($queryString !== '' && !str_contains($requestUri, '?') ? '?' . $queryString : '');

        $_SERVER['PATH_INFO'] = $pathInfo = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $_SERVER['SCRIPT_NAME']     =   $scriptName =$server['script_name'] ?? '/index.php';
        $this->setQueryParams($get);
        $this->setBodyParams($post);
        $this->setRawBody($this->_request->rawContent() ?: '');

        $this->setUrl($_SERVER['REQUEST_URI']);
        $this->setPathInfo($pathInfo);
    }



}