<?php

namespace wen202402\common\di;

trait TraitSafeRequest{


    /**
     * 可信代理地址/IP。建议改为网段或反代的固定 IP。
     * 例：['127.0.0.1', '10.0.0.0/8', '192.168.1.0/24']
     */
    protected array $trustedProxies = ['127.0.0.1'];
    private $_document_root;
    protected function setupGlobalVars(): void{
        $server = $this->_request->server ?? [];
        $headers = $this->_request->header ?? [];

        $get = $this->_request->get ?? [];
        $post = $this->_request->post ?? [];
        $files = $this->_request->files ?? [];
        $cookies = $this->_request->cookie ?? [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_SERVER = [];


        $maxDepth = 8;
        $maxLen = 8192;
        $maxArray = 200;

        $cleanScalar = function ($v) use ($maxLen) {
            if (is_string($v)) {
                // 去控制字符 + CRLF
                $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
                $v = str_replace(["\r", "\n"], '', $v);
                if (strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
                return $v;
            }
            if (is_int($v) || is_float($v) || is_bool($v) || $v === null) return $v;
            if (is_object($v)) return '';
            return (string)$v;
        };

        $cleanArray = function ($arr, $depth = 0) use (&$cleanArray, $cleanScalar, $maxDepth, $maxArray) {
            if ($depth > $maxDepth) return [];
            if (!is_array($arr)) return $cleanScalar($arr);
            $out = [];
            $count = 0;
            foreach ($arr as $k => $v) {
                $count++;
                if ($count > $maxArray) break;
                $key = is_string($k) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $k) : $k;

                if (is_string($key) && strlen($key) > 128) $key = substr($key, 0, 128);

                $out[$key] = is_array($v)
                    ? $cleanArray($v, $depth + 1)
                    : $cleanScalar($v);
            }
            return $out;
        };

        $normalizePort = function ($port) {
            if ($port === null) return '';
            $port = is_string($port) ? $port : (string)$port;
            $port = preg_replace('/[\x00-\x1F\x7F]/u', '', $port);
            $port = str_replace(["\r", "\n"], '', $port);
            $port = trim($port);
            if ($port === '' || !ctype_digit($port)) return '';
            $n = (int)$port;
            if ($n < 1 || $n > 65535) return '';
            return (string)$n;
        };

        $normalizeHost = function ($host) {
            $host = is_string($host) ? $host : '';
            $host = preg_replace('/[\x00-\x1F\x7F]/u', '', $host);
            $host = str_replace(["\r", "\n"], '', $host);
            $host = trim($host);

            if ($host === '') return '';


            if (str_contains($host, ':')) {                                                          // 处理 host:port
                [$h, $p] = explode(':', $host, 2);


                if (str_starts_with($h, '[') && str_ends_with($host, ']')) return '';      // 简化：不支持 [ipv6]:port 形式（避免歧义）

                if ($p === '' || !ctype_digit($p)) return '';
                $host = $h;
            }

            // ipv4
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $host;


            if (preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $host) !== 1) return '';       // 域名粗校验
            if ($host[0] === '.' || str_ends_with($host, '.') || str_contains($host, '..')) return '';

            return strtolower($host);
        };


        $normalizeUriPath = function ($path) {
            $path = is_string($path) ? $path : '/';
            $path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path);
            $path = str_replace(["\r", "\n"], '', $path);
            if ($path === '') $path = '/';
            if (!str_starts_with($path, '/')) $path = '/' . $path;
            if (strlen($path) > 8192) $path = substr($path, 0, 8192);
            return $path;
        };


        $safeFirstIPFromXff = function ($xff) {
            if (!is_string($xff) || $xff === '') return '';
            $parts = explode(',', $xff);
            $ip = trim($parts[0] ?? '');
            $ip = preg_replace('/[\x00-\x1F\x7F]/u', '', $ip);
            $ip = str_replace(["\r", "\n"], '', $ip);

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        };

        $trustedProxies = $this->trustedProxies;

        $isTrustedProxyIp = function (string $ip) use ($trustedProxies): bool {
            foreach ($trustedProxies as $tp) {
                if (!is_string($tp) || $tp === '') continue;
                $tp = trim($tp);

                if ($tp === $ip) return true;

                if (str_contains($tp, '/')) {
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
            }
            return false;
        };


        // ===== GET/POST/COOKIE =====
        $_GET = $cleanArray($get);
        $_POST = $cleanArray($post);
        $_COOKIE = $cleanArray($cookies);
        $_FILES = $files; // 兼容：不强行清理文件数组

        // ===== REMOTE_ADDR（可信代理才信 XFF）=====
        $serverRemote = $server['remote_addr'] ?? $server['remoteAddr'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $serverRemote = is_string($serverRemote) ? $serverRemote : (string)$serverRemote;
        $serverRemote = preg_replace('/[\x00-\x1F\x7F]/u', '', $serverRemote);
        $serverRemote = str_replace(["\r", "\n"], '', $serverRemote);
        $serverRemote = trim($serverRemote);

        if (!filter_var($serverRemote, FILTER_VALIDATE_IP)) $serverRemote = '127.0.0.1';
        $xff = $headers['x-forwarded-for'] ?? $headers['X-Forwarded-For'] ?? null;
        $realIp = ($trustedProxy = $isTrustedProxyIp($serverRemote)) ? $safeFirstIPFromXff($xff) : '';
        if ($realIp === '') $realIp = $serverRemote;
        $_SERVER['REMOTE_ADDR'] = $realIp;
        $_SERVER['REMOTE_PORT'] = (string)($server['remote_port'] ?? $server['remotePort'] ?? '0');


        $rawHost = $headers['host'] ?? $headers['Host'] ?? ($server['http_host'] ?? $server['HTTP_HOST'] ?? '');
        $host = $normalizeHost($rawHost);

        $port = $normalizePort($server['server_port'] ?? $server['serverPort'] ?? ($server['port'] ?? ''));
        $xProto = $headers['x-forwarded-proto'] ?? $headers['X-Forwarded-Proto'] ?? null;

        $scheme = $server['scheme'] ?? $server['SCHEME'] ?? 'http';
        $scheme = is_string($scheme) ? strtolower(trim($scheme)) : 'http';

        if ($trustedProxy && is_string($xProto) && $xProto !== '') $scheme = strtolower(trim(explode(',', $xProto)[0]));

        if (!in_array($scheme, ['http', 'https'], true)) $scheme = 'http';


        if ($port === '') $port = ($scheme === 'https') ? '443' : '80';


        $httpHost = $host !== '' ? $host : 'localhost';
        $defaultNoPort = ($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80');
        if (!$defaultNoPort) $httpHost .= ':' . $port;


        $_SERVER['HTTP_HOST'] = $httpHost;
        $_SERVER['SERVER_NAME'] = $host !== '' ? $host : 'localhost';
        $_SERVER['SERVER_PORT'] = $port;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['SERVER_SOFTWARE'] = $server['server_software'] ?? 'Swoole';

        // ===== REQUEST_URI / PATH_INFO =====
        $requestUri = $server['request_uri'] ?? $server['REQUEST_URI'] ?? '/';
        $requestUri = is_string($requestUri) ? $requestUri : (string)$requestUri;

        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = $normalizeUriPath($path ?: '/');

        $query = parse_url($requestUri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';
        $query = preg_replace('/[\x00-\x1F\x7F]/u', '', $query);
        $query = str_replace(["\r", "\n"], '', $query);
        if (strlen($query) > 8192) $query = substr($query, 0, 8192);

        $_SERVER['REQUEST_METHOD'] = strtoupper((string)($server['request_method'] ?? $server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
        $_SERVER['PATH_INFO'] = $path;

        // ===== 只做白名单 header -> $_SERVER（兼容+更安全）=====
        $headerWhitelist = [
            'authorization' => 'HTTP_AUTHORIZATION',
            'user-agent' => 'HTTP_USER_AGENT',
            'content-type' => 'CONTENT_TYPE', // 有时 PHP 用这个
            'accept-language' => 'HTTP_ACCEPT_LANGUAGE',
            'referer' => 'HTTP_REFERER',
            'x-request-id' => 'HTTP_X_REQUEST_ID',
        ];

        foreach ($headerWhitelist as $inKey => $outKey) {
            $val = null;
            // 兼容大小写
            foreach ([$inKey, ucfirst($inKey), strtoupper($inKey)] as $k) {
                if (array_key_exists($k, $headers)) {
                    $val = $headers[$k];
                    break;
                }

            }
            if ($val === null) $val = $headers[$inKey] ?? $headers[ucfirst($inKey)] ?? null;

            if ($val === null) continue;

            $val = $cleanScalar($val);


            $_SERVER[$outKey] = $val;
        }

        // ===== SCRIPT_NAME / PHP_SELF / DOCUMENT_ROOT / SCRIPT_FILENAME =====
        $scriptName = $server['script_name'] ?? '/index.php';
        $scriptName = is_string($scriptName) ? $scriptName : '/index.php';
        $scriptName = preg_replace('/[\x00-\x1F\x7F]/u', '', $scriptName);
        $scriptName = str_replace(["\r", "\n"], '', $scriptName);
        if ($scriptName === '') $scriptName = '/index.php';

        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['PHP_SELF'] = $_SERVER['PHP_SELF'] ?? $scriptName;

        $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? ($this->_document_root ?? '');
        $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($docRoot !== '') $_SERVER['SCRIPT_FILENAME'] = rtrim($docRoot, '/\\') . '/index.php';



        $this->setScriptFile($scriptName);
        $this->setQueryParams($_GET);
        $this->setBodyParams($_POST);

        $rawBody = (string)($this->_request->rawContent() ?: '');
        $rawBody = preg_replace('/[\x00-\x1F\x7F]/u', '', $rawBody);
        $rawBody = str_replace(["\r", "\n"], '', $rawBody);
        if (strlen($rawBody) > 1024 * 1024) $rawBody = substr($rawBody, 0, 1024 * 1024);
        $this->setRawBody($rawBody);

        $this->setUrl($_SERVER['REQUEST_URI']);
        $this->setPathInfo($_SERVER['PATH_INFO']);
    }

}
