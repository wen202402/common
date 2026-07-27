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
    private $_document_root;

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




    protected function setupGlobalVars(): void{
        $server  = $this->_request->server ?? [];
        $headers = $this->_request->header ?? [];

        $get   = $this->_request->get ?? [];
        $post  = $this->_request->post ?? [];
        $files = $this->_request->files ?? [];
        $cookies = $this->_request->cookie ?? [];

        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        $_COOKIE = $cookies;
        $_SERVER = [];


        foreach ($server as $key => $value) $_SERVER[strtoupper($key)] = $value;

        foreach ($headers as $key => $value) $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;



        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== '' ? explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0] : ($_SERVER['REMOTE_PORT'] ? '127.0.0.1' : '127.0.0.1');

        $_SERVER['REMOTE_PORT'] = $_SERVER['REMOTE_PORT'] ?? ($server['remote_port'] ?? ($server['remotePort'] ?? null));


        $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? ($server['server_port'] ?? $server['serverPort'] ?? $_SERVER['HTTP_HOST'] && str_contains($_SERVER['HTTP_HOST'], ':') ? (int)explode(':', (string)$_SERVER['HTTP_HOST'], 2)[1] : '19999');

        $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? ($server['server_name'] ?? $server['serverName'] ?? ($server['host'] ?? 'localhost'));

        // HOST
        if (isset($_SERVER['HTTP_HOST']) === false) {
            $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'];

            if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] !== '80' && (string)$_SERVER['SERVER_PORT'] !== '443') $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] . ':' . $_SERVER['SERVER_PORT'];

        }


        $_SERVER['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $_SERVER['SERVER_SOFTWARE'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Swoole';

        $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $this->_document_root;
        $server['script_name'] = $server['script_name'] ?? '/index.php';
        $requestUri = $server['request_uri'] ?? '/';
        $scriptName = $server['script_name'] ?? '/index.php';
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? $scriptName;
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') . '/index.php';

        $_SERVER['REQUEST_METHOD'] = strtoupper($server['request_method'] ?? 'GET');

        $_SERVER['QUERY_STRING'] = $queryString = $server['query_string'] ?? http_build_query($get);

        $_SERVER['REQUEST_URI'] = $requestUri . ($queryString !== '' && !str_contains($requestUri, '?') ? '?' . $queryString : '');

        $_SERVER['PATH_INFO'] = $pathInfo = parse_url($requestUri, PHP_URL_PATH) ?: '/';


        $this->setScriptFile($scriptName);
        $this->setQueryParams($get);
        $this->setBodyParams($post);
        $this->setRawBody($this->_request->rawContent() ?: '');

        $this->setUrl($_SERVER['REQUEST_URI']);
        $this->setPathInfo($pathInfo);
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










    protected function loadCookies(){
        $cookies = [];
        if (!$this->enableCookieValidation) {
            if (!empty($_COOKIE))  foreach ($_COOKIE as $name => $value) $cookies[$name] = Yii::createObject(['class' => \yii\web\Cookie::class, 'name' => $name, 'value' => $value, 'expire' => null,]);
            return $cookies;
        }
        if ($this->cookieValidationKey == '') throw new InvalidConfigException(get_class($this) . '::cookieValidationKey must be configured with a secret key.');
        foreach ($_COOKIE as $name => $value) {
            if (!is_string($value)) continue;
            if (($data = Yii::$app->getSecurity()->validateData($value, $this->cookieValidationKey)) === false) continue;
            $data = (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70000) ? @unserialize($data, ['allowed_classes' => false]): @unserialize($data);
            if (is_array($data) && isset($data[0], $data[1]) && $data[0] === $name) $cookies[$name] = Yii::createObject(['class' => \yii\web\Cookie::class, 'name' => $name, 'value' => $data[1], 'expire' => null,]);

        }
        return $cookies;
    }





}