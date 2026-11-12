<?php
declare (strict_types = 1);

namespace app\common\service\storage;

/**
 * WebDAV 驱动（手写 curl 客户端，零第三方依赖）
 *
 * 覆盖对象：坚果云 / InfiniCloud / 自建 Alist（聚合阿里云盘、夸克、百度、OneDrive 等）
 *
 * config: {
 *   "base_url":   "http://alist:5244/dav",
 *   "username":   "user",
 *   "password":   "pass",
 *   "direct_url": "http://alist:5244/d/{path}"   // 直链模板，302 模式用；留空=不支持直链
 * }
 */
class WebdavStorage implements StorageDriverInterface
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected string $directUrlTemplate;
    protected int    $timeout = 15;

    public function __construct(array $config, int $urlMode = 1)
    {
        $this->baseUrl           = rtrim((string)($config['base_url'] ?? ''), '/');
        $this->username          = (string)($config['username'] ?? '');
        $this->password          = (string)($config['password'] ?? '');
        $this->directUrlTemplate = (string)($config['direct_url'] ?? '');
        // 未填直链模板时，302 模式自动降级为代理模式
        $this->urlMode = ($urlMode === 2 && $this->directUrlTemplate === '') ? 1 : $urlMode;
    }

    public function put(string $localPath, string $relativePath): bool
    {
        $content = file_get_contents($localPath);
        if ($content === false) {
            return false;
        }
        $resp = $this->request('PUT', $relativePath, $content);
        return $resp['code'] >= 200 && $resp['code'] < 300;
    }

    public function delete(string $relativePath): bool
    {
        $resp = $this->request('DELETE', $relativePath);
        return $resp['code'] >= 200 && $resp['code'] < 300 || $resp['code'] === 404;
    }

    public function getContent(string $relativePath): ?string
    {
        $resp = $this->request('GET', $relativePath);
        return $resp['code'] >= 200 && $resp['code'] < 300 && $resp['body'] !== false ? $resp['body'] : null;
    }

    public function getDirectUrl(string $relativePath): string
    {
        if ($this->directUrlTemplate === '') {
            return '';
        }
        return str_replace('{path}', ltrim(str_replace('\\', '/', $relativePath), '/'), $this->directUrlTemplate);
    }

    public function exists(string $relativePath): bool
    {
        $resp = $this->request('HEAD', $relativePath);
        return $resp['code'] >= 200 && $resp['code'] < 300;
    }

    public function probe(): array
    {
        if ($this->baseUrl === '') {
            return ['ok' => false, 'msg' => 'base_url 未配置'];
        }
        $resp = $this->request('PROPFIND', '/', null, ['Depth: 0']);
        return [
            'ok'  => $resp['code'] >= 200 && $resp['code'] < 300,
            'msg' => 'HTTP ' . $resp['code'] . ($resp['error'] !== '' ? ' ' . $resp['error'] : ''),
        ];
    }

    public function listFiles(): array
    {
        $out = [];
        $this->walk('', $out, 0);
        return $out;
    }

    /** 递归 PROPFIND（depth:1） */
    protected function walk(string $rel, array &$out, int $depth): void
    {
        if ($depth > 6) {
            return;
        }
        $resp = $this->request('PROPFIND', $rel === '' ? '/' : rtrim($rel, '/') . '/', null, ['Depth: 1']);
        if ($resp['code'] < 200 || $resp['code'] >= 300 || !is_string($resp['body'])) {
            return;
        }
        $xml = @simplexml_load_string($resp['body']);
        if (!$xml) {
            return;
        }
        $basePath = parse_url($this->baseUrl, PHP_URL_PATH) ?: '';
        $nodes = $xml->xpath('//*[local-name()="response"]') ?: [];
        foreach ($nodes as $node) {
            $node->registerXPathNamespace('d', 'DAV:');
            $href = '';
            $hrefNode = $node->xpath('d:href');
            if ($hrefNode) {
                $href = urldecode((string)$hrefNode[0]);
            }
            if ($href === '') {
                continue;
            }
            $isDir = !empty($node->xpath('d:propstat/d:prop/d:resourcetype/d:collection'));
            $lmNode = $node->xpath('d:propstat/d:prop/d:getlastmodified');
            $mtime = $lmNode ? (strtotime((string)$lmNode[0]) ?: 0) : 0;

            $path = $href;
            if ($basePath !== '' && str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
            $path = ltrim($path, '/');
            $selfRtrim = rtrim($rel, '/');
            if ($path === '' || ($selfRtrim !== '' && rtrim($path, '/') === $selfRtrim)) {
                continue;
            }
            if ($isDir) {
                $this->walk(rtrim($path, '/'), $out, $depth + 1);
            } else {
                $out[] = ['path' => $path, 'mtime' => (int)$mtime];
            }
        }
    }

    /**
     * WebDAV 请求核心
     * @return array{code:int, body:mixed, error:string}
     */
    protected function request(string $method, string $relativePath, ?string $body = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $ch  = curl_init($url);
        $headers = $extraHeaders;
        if ($method === 'PUT') {
            $headers[] = 'Content-Type: application/octet-stream';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($this->username !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
        if ($body !== null && $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res   = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => $res, 'error' => $error];
    }

    /** 兼容接口（urlMode 属性由接口约定，此处显式声明避免动态属性） */
    protected int $urlMode = 1;

    public function getUrlMode(): int
    {
        return $this->urlMode;
    }
}
