<?php

namespace wen202402\common\helper;

class SafeHelper
{
    public static function normalizeUriPath($path){
        $path = is_string($path) ? $path : '/';
        $path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path);
        $path = str_replace(["\r", "\n"], '', $path);
        if ($path === '') $path = '/';
        if (!str_starts_with($path, '/')) $path = '/' . $path;
        if (strlen($path) > 8192) $path = substr($path, 0, 8192);
        return $path;
    }

    public static function normalizeHost($host){
        $host = is_string($host) ? $host : '';
        $host = preg_replace('/[\x00-\x1F\x7F]/u', '', $host);
        $host = str_replace(["\r", "\n"], '', $host);
        $host = trim($host);

        if ($host === '') return '';

        if (str_contains($host, ':')) { // 处理 host:port
            [$h, $p] = explode(':', $host, 2);

            if (str_starts_with($h, '[') && str_ends_with($host, ']')) return ''; // 不支持 [ipv6]:port
            if ($p === '' || !ctype_digit($p)) return '';
            $host = $h;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $host;

        if (preg_match('/^[a-zA-Z0-9.-]{1,253}$/', $host) !== 1) return '';
        if ($host[0] === '.' || str_ends_with($host, '.') || str_contains($host, '..')) return '';

        return strtolower($host);
    }

    public static function safeFirstIPFromXff($xff)
    {
        if (!is_string($xff) || $xff === '') return '';
        $parts = explode(',', $xff);
        $ip = trim($parts[0] ?? '');
        $ip = preg_replace('/[\x00-\x1F\x7F]/u', '', $ip);
        $ip = str_replace(["\r", "\n"], '', $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function normalizePort($port)
    {
        if ($port === null) return '';
        $port = is_string($port) ? $port : (string)$port;
        $port = preg_replace('/[\x00-\x1F\x7F]/u', '', $port);
        $port = str_replace(["\r", "\n"], '', $port);
        $port = trim($port);
        if ($port === '' || !ctype_digit($port)) return '';
        $n = (int)$port;
        if ($n < 1 || $n > 65535) return '';
        return (string)$n;
    }

    public static function cleanScalar($v, int $maxLen = 8192){
        if (is_string($v)) {
            $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
            $v = str_replace(["\r", "\n"], '', $v);
            if (strlen($v) > $maxLen) $v = substr($v, 0, $maxLen);
            return $v;
        }
        if (is_int($v) || is_float($v) || is_bool($v) || $v === null) return $v;
        if (is_object($v)) return '';
        return (string)$v;
    }

    public static function cleanArray($arr, int $depth = 0, int $maxDepth = 8, int $maxArray = 200, int $maxLen = 8192){
        if ($depth > $maxDepth) return [];
        if (!is_array($arr)) return self::cleanScalar($arr, $maxLen);

        $out = [];
        $count = 0;

        foreach ($arr as $k => $v) {
            $count++;
            if ($count > $maxArray) break;
            $key = is_string($k) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $k) : $k;
            if (is_string($key) && strlen($key) > 128) $key = substr($key, 0, 128);

            $out[$key] = is_array($v) ? self::cleanArray($v, $depth + 1, $maxDepth, $maxArray, $maxLen): self::cleanScalar($v, $maxLen);
        }

        return $out;
    }


    public static function buildGlobalVarsFromRequest(array $server, array $headers, $documentRoot, callable $isTrustedProxyChecker): array{
        $maxDepth = 8;
        $maxLen = 8192;
        $maxArray = 200;

        $get     = $server['_GET'] ?? null; // 不存在时无所谓（兼容）
        $post    = $server['_POST'] ?? null;
        $files   = $server['_FILES'] ?? null;
        $cookies = $server['_COOKIE'] ?? null;

        // 你当前框架是：从 $this->_request->get/post/files/cookie 来的，
        // 所以这里为了不改你原结构，下面直接用 server 里没有就从 headers/server 外传入不行。
        // 但我们在 Trait 里会把真实的 get/post/files/cookie 仍然传给 buildGlobalVarsFromRequest，
        // 因此这里不应依赖这些 server['_GET'] 之类。
        // 为了保持一致：我们把 GET/POST/COOKIE 的获取交给 Trait（见 Trait 代码改造），所以此函数内部只处理已传入的数据。
        // ------
        // 为了让这个函数可直接用，我们下面改成从 $server 数组约定键读取：
        // - $server['_safe_get'], $server['_safe_post'], $server['_safe_files'], $server['_safe_cookie']
        // Trait 会按这个键传入。
        // ------

        $safeGet     = $server['_safe_get'] ?? [];
        $safePost    = $server['_safe_post'] ?? [];
        $safeFiles   = $server['_safe_files'] ?? [];
        $safeCookies = $server['_safe_cookie'] ?? [];

        $cleanedGet     = self::cleanArray($safeGet, 0, $maxDepth, $maxArray, $maxLen);
        $cleanedPost    = self::cleanArray($safePost, 0, $maxDepth, $maxArray, $maxLen);
        $cleanedCookies = self::cleanArray($safeCookies, 0, $maxDepth, $maxArray, $maxLen);
        $cleanedFiles   = $safeFiles; // 不强行清理文件数组

        // remote addr
        $serverRemote = $server['remote_addr'] ?? $server['remoteAddr'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $serverRemote = is_string($serverRemote) ? $serverRemote : (string)$serverRemote;
        $serverRemote = preg_replace('/[\x00-\x1F\x7F]/u', '', $serverRemote);
        $serverRemote = str_replace(["\r", "\n"], '', $serverRemote);
        $serverRemote = trim($serverRemote);
        if (!filter_var($serverRemote, FILTER_VALIDATE_IP)) $serverRemote = '127.0.0.1';

        // trusted proxy + XFF
        $trustedProxy = (bool)$isTrustedProxyChecker($serverRemote);
        $realIp = '';
        if ($trustedProxy) {
            $xff = $headers['x-forwarded-for'] ?? $headers['X-Forwarded-For'] ?? null;
            $realIp = self::safeFirstIPFromXff($xff);
        }
        if ($realIp === '') $realIp = $serverRemote;

        $port = self::normalizePort($server['server_port'] ?? $server['serverPort'] ?? ($server['port'] ?? ''));
        $xProto = $headers['x-forwarded-proto'] ?? $headers['X-Forwarded-Proto'] ?? null;

        $scheme = $server['scheme'] ?? $server['SCHEME'] ?? 'http';
        $scheme = is_string($scheme) ? strtolower(trim($scheme)) : 'http';
        if ($trustedProxy && is_string($xProto) && $xProto !== '') {
            $scheme = strtolower(trim(explode(',', $xProto)[0]));
        }
        if (!in_array($scheme, ['http', 'https'], true)) $scheme = 'http';

        if ($port === '') $port = ($scheme === 'https') ? '443' : '80';

        $rawHost = $headers['host'] ?? $headers['Host'] ?? ($server['http_host'] ?? $server['HTTP_HOST'] ?? '');
        $host = self::normalizeHost($rawHost);
        $httpHost = ($host !== '' ? $host : 'localhost');
        $defaultNoPort = ($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80');
        if (!$defaultNoPort) $httpHost .= ':' . $port;

        // request uri/path
        $requestUri = $server['request_uri'] ?? $server['REQUEST_URI'] ?? '/';
        $requestUri = is_string($requestUri) ? $requestUri : (string)$requestUri;

        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = self::normalizeUriPath($path ?: '/');

        $query = parse_url($requestUri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : '';
        $query = preg_replace('/[\x00-\x1F\x7F]/u', '', $query);
        $query = str_replace(["\r", "\n"], '', $query);
        if (strlen($query) > 8192) $query = substr($query, 0, 8192);

        $method = strtoupper((string)($server['request_method'] ?? $server['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            $method = 'GET';
        }

        // script name/self
        $scriptName = $server['script_name'] ?? '/index.php';
        $scriptName = is_string($scriptName) ? $scriptName : '/index.php';
        $scriptName = preg_replace('/[\x00-\x1F\x7F]/u', '', $scriptName);
        $scriptName = str_replace(["\r", "\n"], '', $scriptName);
        if ($scriptName === '') $scriptName = '/index.php';

        // build $_SERVER
        $docRoot = (string)($documentRoot ?? '');

        $outServer = [];
        $outServer['REMOTE_ADDR'] = $realIp;
        $outServer['REMOTE_PORT'] = (string)($server['remote_port'] ?? $server['remotePort'] ?? '0');

        $outServer['HTTP_HOST'] = $httpHost;
        $outServer['SERVER_NAME'] = $host !== '' ? $host : 'localhost';
        $outServer['SERVER_PORT'] = $port;
        $outServer['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $outServer['SERVER_SOFTWARE'] = $server['server_software'] ?? 'Swoole';

        $outServer['REQUEST_METHOD'] = $method;
        $outServer['QUERY_STRING'] = $query;
        $outServer['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
        $outServer['PATH_INFO'] = $path;

        $outServer['SCRIPT_NAME'] = $scriptName;
        $outServer['PHP_SELF'] = $outServer['PHP_SELF'] ?? $scriptName;

        $outServer['DOCUMENT_ROOT'] = $outServer['DOCUMENT_ROOT'] ?? $docRoot;
        if ($docRoot !== '') {
            $outServer['SCRIPT_FILENAME'] = rtrim($docRoot, '/\\') . '/index.php';
        }

        // header whitelist -> server vars (只做清洗：沿用 cleanScalar）
        $headerWhitelist = [
            'authorization' => 'HTTP_AUTHORIZATION',
            'user-agent' => 'HTTP_USER_AGENT',
            'content-type' => 'CONTENT_TYPE',
            'accept-language' => 'HTTP_ACCEPT_LANGUAGE',
            'referer' => 'HTTP_REFERER',
            'x-request-id' => 'HTTP_X_REQUEST_ID',
        ];

        foreach ($headerWhitelist as $inKey => $outKey) {
            $val = null;
            foreach ([$inKey, ucfirst($inKey), strtoupper($inKey)] as $k) {
                if (array_key_exists($k, $headers)) {
                    $val = $headers[$k];
                    break;
                }
            }
            if ($val === null) {
                $val = $headers[$inKey] ?? $headers[ucfirst($inKey)] ?? null;
            }
            if ($val === null) continue;

            $val = self::cleanScalar($val, $maxLen);
            $outServer[$outKey] = $val;
        }

        // rawBody：这里 Trait 原本用 $this->_request->rawContent()，此函数无法访问对象
        // 所以原逻辑里 rawBody 在 Trait 中仍要提供。
        // 你可以选择：把 rawBody 也传进来；这里我们用占位，Trait 会覆盖。
        $out = [
            '_GET' => $cleanedGet,
            '_POST' => $cleanedPost,
            '_FILES' => $cleanedFiles,
            '_COOKIE' => $cleanedCookies,
            '_SERVER' => $outServer,
            '_RAW_BODY' => '', // Trait 覆盖
        ];

        return $out;
    }
}