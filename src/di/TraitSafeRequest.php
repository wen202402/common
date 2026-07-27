<?php

namespace wen202402\common\di;

use wen202402\common\helper\IPHelper;
use wen202402\common\helper\SafeHelper;

trait TraitSafeRequest {
    private $_document_root;


    protected bool $_enableSafeRequestFiltering = true;

    protected function setupGlobalVars(): void {


        if ($this->_enableSafeRequestFiltering) {
            $server  = $this->_request->server ?? [];
            $headers = $this->_request->header ?? [];

            $get     = $this->_request->get ?? [];
            $post    = $this->_request->post ?? [];
            $files   = $this->_request->files ?? [];
            $cookies = $this->_request->cookie ?? [];
            $_GET = [];
            $_POST = [];
            $_FILES = [];
            $_COOKIE = [];
            $_SERVER = [];

            // 把 get/post/files/cookie 注入到约定键里
            $server['_safe_get'] = $get;
            $server['_safe_post'] = $post;
            $server['_safe_files'] = $files;
            $server['_safe_cookie'] = $cookies;

            $state = SafeHelper::buildGlobalVarsFromRequest($server, $headers, $this->_document_root ?? '', function (string $ip): bool {return IPHelper::isTrustedIp($ip);});

            $_GET = $state['_GET'];
            $_POST = $state['_POST'];
            $_FILES = $state['_FILES'];
            $_COOKIE = $state['_COOKIE'];
            $_SERVER = $state['_SERVER'];

            $rawBody = preg_replace('/[\x00-\x1F\x7F]/u', '',  (string)($this->_request->rawContent() ?: ''));
            $rawBody = str_replace(["\r", "\n"], '', $rawBody);
            if (strlen($rawBody) > 1024 * 1024) $rawBody = substr($rawBody, 0, 1024 * 1024);
            $this->setRawBody($rawBody);

            $this->setScriptFile($_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $this->setQueryParams($_GET);
            $this->setBodyParams($_POST);
            $this->setUrl($_SERVER['REQUEST_URI'] ?? '/');
            $this->setPathInfo($_SERVER['PATH_INFO'] ?? '/');
            return;
        }

        $this->noFilter();
    }



    public function noFilter(){

        $server  = $this->_request->server ?? [];
        $headers = $this->_request->header ?? [];

        $get     = $this->_request->get ?? [];
        $post    = $this->_request->post ?? [];
        $files   = $this->_request->files ?? [];
        $cookies = $this->_request->cookie ?? [];

        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
        $_COOKIE = $cookies;
        $_SERVER = [];

        // 1) server -> $_SERVER
        foreach ($server as $key => $value) {
            $_SERVER[strtoupper($key)] = $value;
        }

        // 2) header -> $_SERVER['HTTP_*']
        foreach ($headers as $key => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        // 3) REMOTE_ADDR / REMOTE_PORT
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? (
        ($xff !== null && $xff !== '') ? explode(',', (string)$xff)[0] : '127.0.0.1'
        );

        $_SERVER['REMOTE_PORT'] = $_SERVER['REMOTE_PORT'] ?? (
            $server['remote_port'] ?? $server['remotePort'] ?? '0'
        );

        // 4) SERVER_PORT / SERVER_NAME / HTTP_HOST
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        $defaultPort = '19999';

        $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? ($server['server_port'] ?? $server['serverPort'] ?? (
        ($httpHost !== '' && str_contains($httpHost, ':')) ? (string)explode(':', (string)$httpHost, 2)[1] : $defaultPort)
        );

        $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? ($server['server_name'] ?? $server['serverName'] ?? ($server['host'] ?? 'localhost'));

        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'];
            if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] !== '80' && (string)$_SERVER['SERVER_PORT'] !== '443') {
                $_SERVER['HTTP_HOST'] .= ':' . (string)$_SERVER['SERVER_PORT'];
            }
        }

        // 5) 协议/软件
        $_SERVER['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $_SERVER['SERVER_SOFTWARE'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Swoole';


        $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? ($this->_document_root ?? '');

        $scriptName = $server['script_name'] ?? '/index.php';
        $requestUri = $server['request_uri'] ?? '/';

        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? $scriptName;

        $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docRoot !== '') $_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? rtrim($docRoot, '/\\') . '/index.php';
        else   $_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'] ?? ($server['script_filename'] ?? '');


        // 7) request_method / query / request_uri / path_info
        $_SERVER['REQUEST_METHOD'] = strtoupper($server['request_method'] ?? 'GET');
        $_SERVER['QUERY_STRING'] = $queryString = $server['query_string'] ?? http_build_query($get);

        $_SERVER['REQUEST_URI'] = $requestUri . (
            $queryString !== '' && !str_contains($requestUri, '?') ? '?' . $queryString : ''
            );

        $_SERVER['PATH_INFO'] = $pathInfo = parse_url($requestUri, PHP_URL_PATH) ?: '/';


        $this->setScriptFile($scriptName);
        $this->setQueryParams($get);
        $this->setBodyParams($post);
        $this->setRawBody($this->_request->rawContent() ?: '');

        $this->setUrl($_SERVER['REQUEST_URI']);
        $this->setPathInfo($pathInfo);


    }


}
