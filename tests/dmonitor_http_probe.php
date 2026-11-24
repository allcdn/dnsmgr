<?php
// Run with PHP CLI + ext-curl; inspect the HTTP transport options without network I/O.
namespace GuzzleHttp {
    class Client
    {
        public static string $url;
        public static array $options;
        public static int $code = 302;

        public function request($method, $url, $options)
        {
            self::$url = $url;
            self::$options = $options;
            return new class {
                public function getStatusCode() { return Client::$code; }
            };
        }
    }
}

namespace app\utils {
    function config_get($key)
    {
        return ['proxy_server' => '127.0.0.1', 'proxy_port' => '8080', 'proxy_type' => 'http'][$key] ?? '';
    }
}

namespace {
    use app\utils\CheckUtils;
    use GuzzleHttp\Client;

    require __DIR__ . '/../app/utils/CheckUtils.php';

    $url = 'https://www.example.test:8443/health';
    foreach (['192.0.2.10', '2001:db8::10', 'primary.example.test.'] as $primary) {
        foreach ([false, true] as $proxy) {
            $result = CheckUtils::curl($url, 2, $primary, $proxy);
            if (!$result['status'] || Client::$url !== $url || (Client::$options['allow_redirects'] ?? true)) {
                throw new RuntimeException('Check the origin response without following its redirect');
            }
            $target = str_contains($primary, ':') ? '['.$primary.']' : $primary;
            if ((Client::$options['curl'][CURLOPT_CONNECT_TO] ?? null) !== ['::'.$target.':']) {
                throw new RuntimeException('The connection must target the primary, keeping the URL port');
            }
            if ((Client::$options['proxy'] ?? null) !== ($proxy ? 'http://127.0.0.1:8080' : null)) {
                throw new RuntimeException('The configured probe proxy was not preserved');
            }
        }
    }

    Client::$code = 503;
    $result = CheckUtils::curl('http://[2001:db8::1]/health', 2, '192.0.2.10');
    if ($result['status'] || $result['errmsg'] !== 'http_code=503' || isset(Client::$options['curl'])) {
        throw new RuntimeException('An explicit probe IP and its unhealthy response must be respected');
    }
    echo "PASS: HTTP primary targeting, proxy, redirects and explicit check IP\n";
}
