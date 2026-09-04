<?php
/**
 * Cloudflare R2 存储服务（S3 兼容 API）
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

class R2StorageService
{
    private array $config;
    private string $host;

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $configFile = ROOT_PATH . '/config/r2.php';
            $this->config = file_exists($configFile)
                ? require $configFile
                : ['enabled' => false];
        }
        $this->host = (string) parse_url($this->config['endpoint'] ?? '', PHP_URL_HOST);
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled'])
            && !empty($this->config['access_key_id'])
            && !empty($this->config['secret_access_key'])
            && !empty($this->config['endpoint'])
            && !empty($this->config['bucket'])
            && function_exists('curl_init');
    }

    /**
     * 检查依赖并返回错误信息
     */
    public function checkRequirements(): ?string
    {
        if (empty($this->config['enabled'])) {
            return 'R2 存储未启用';
        }
        if (!function_exists('curl_init')) {
            return '服务器未安装 PHP curl 扩展';
        }
        if (empty($this->config['access_key_id']) || empty($this->config['secret_access_key'])) {
            return 'R2 凭证未配置';
        }
        if (empty($this->config['endpoint']) || empty($this->config['bucket'])) {
            return 'R2 端点或桶名未配置';
        }
        return null;
    }

    /**
     * 上传本地文件到 R2
     */
    public function uploadFile(string $localPath, string $objectKey, string $contentType): bool
    {
        if (!is_readable($localPath)) {
            Logger::error('R2 upload: file not readable', ['path' => $localPath]);
            return false;
        }

        $fileSize = filesize($localPath);
        if ($fileSize === false) {
            return false;
        }

        // 大文件使用流式上传，避免内存溢出
        if ($fileSize > 8 * 1024 * 1024) {
            return $this->uploadFileStream($localPath, $objectKey, $contentType, $fileSize);
        }

        $body = file_get_contents($localPath);
        if ($body === false) {
            return false;
        }

        return $this->uploadBody($body, $objectKey, $contentType);
    }

    /**
     * 上传二进制内容到 R2
     */
    public function uploadBody(string $body, string $objectKey, string $contentType): bool
    {
        $url = $this->buildObjectUrl($objectKey);
        $headers = $this->signRequest('PUT', $objectKey, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
        ], hash('sha256', $body));

        return $this->executeCurl('PUT', $url, $headers, $body);
    }

    /**
     * 流式上传大文件（使用 UNSIGNED-PAYLOAD）
     */
    private function uploadFileStream(string $localPath, string $objectKey, string $contentType, int $fileSize): bool
    {
        $url = $this->buildObjectUrl($objectKey);
        $headers = $this->signRequest('PUT', $objectKey, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $fileSize,
        ], 'UNSIGNED-PAYLOAD');

        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            return false;
        }

        $curlHeaders = $this->formatCurlHeaders($headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error('R2 stream upload failed', [
                'key' => $objectKey,
                'http_code' => $httpCode,
                'response' => is_string($response) ? substr($response, 0, 500) : '',
                'curl_error' => $error,
            ]);
            return false;
        }

        return true;
    }

    /**
     * 获取对象元信息（HEAD）
     */
    public function headObject(string $objectKey): ?array
    {
        $url = $this->buildObjectUrl($objectKey);
        $headers = $this->signRequest('HEAD', $objectKey, [], 'UNSIGNED-PAYLOAD');
        $curlHeaders = $this->formatCurlHeaders($headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }

        $contentType = 'application/octet-stream';
        $contentLength = 0;

        foreach (explode("\r\n", $response) as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($line, 15));
            }
        }

        return [
            'content_type' => $contentType,
            'content_length' => $contentLength,
        ];
    }

    /**
     * 流式输出对象到客户端（避免大视频占满内存）
     *
     * @param array{filename?: string, download?: bool} $options
     */
    public function streamObject(string $objectKey, ?string $range = null, array $options = []): bool
    {
        $url = $this->buildObjectUrl($objectKey);
        $extraHeaders = [];
        if ($range !== null && $range !== '') {
            $extraHeaders['Range'] = $range;
        }

        $filename = $options['filename'] ?? basename($objectKey);
        $download = !empty($options['download']);

        $headers = $this->signRequest('GET', $objectKey, $extraHeaders, 'UNSIGNED-PAYLOAD');
        $curlHeaders = $this->formatCurlHeaders($headers);

        $meta = [
            'content_type' => 'application/octet-stream',
            'content_length' => 0,
            'status' => 0,
            'content_range' => '',
        ];
        $sentResponseHeaders = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_HEADERFUNCTION => static function ($curl, $headerLine) use (&$meta) {
                $line = trim($headerLine);
                if (stripos($line, 'HTTP/') === 0 && preg_match('/\s(\d{3})\s/', $line, $m)) {
                    $meta['status'] = (int) $m[1];
                }
                if (stripos($line, 'Content-Type:') === 0) {
                    $meta['content_type'] = trim(substr($line, 13));
                }
                if (stripos($line, 'Content-Length:') === 0) {
                    $meta['content_length'] = (int) trim(substr($line, 15));
                }
                if (stripos($line, 'Content-Range:') === 0) {
                    $meta['content_range'] = trim(substr($line, 14));
                }
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, $data) use (&$meta, &$sentResponseHeaders, $objectKey, $filename, $download) {
                if (!$sentResponseHeaders) {
                    if ($meta['status'] !== 200 && $meta['status'] !== 206) {
                        return 0;
                    }
                    $contentType = $meta['content_type'];
                    if ($contentType === '' || $contentType === 'application/octet-stream') {
                        $contentType = self::guessMimeFromKey($objectKey);
                    }
                    header('Content-Type: ' . $contentType);
                    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
                    if ($meta['content_length'] > 0) {
                        header('Content-Length: ' . $meta['content_length']);
                    }
                    if ($meta['content_range'] !== '') {
                        header('Content-Range: ' . $meta['content_range']);
                    }
                    header('Cache-Control: public, max-age=86400');
                    header('Accept-Ranges: bytes');
                    header('X-Content-Type-Options: nosniff');
                    http_response_code($meta['status']);
                    $sentResponseHeaders = true;
                }
                echo $data;
                return strlen($data);
            },
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $ok && ($httpCode === 200 || $httpCode === 206) && $sentResponseHeaders;
    }

    public static function guessMimeFromKey(string $objectKey): string
    {
        $ext = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    /**
     * 从 R2 读取对象
     */
    public function getObject(string $objectKey): ?array
    {
        $url = $this->buildObjectUrl($objectKey);
        $headers = $this->signRequest('GET', $objectKey, [], 'UNSIGNED-PAYLOAD');

        $curlHeaders = $this->formatCurlHeaders($headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 600,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response)) {
            return null;
        }

        [$headerText, $body] = explode("\r\n\r\n", $response, 2);
        $contentType = 'application/octet-stream';

        foreach (explode("\r\n", $headerText) as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
        }

        return [
            'body' => $body,
            'content_type' => $contentType,
        ];
    }

    public function deleteObject(string $objectKey): bool
    {
        $url = $this->buildObjectUrl($objectKey);
        $headers = $this->signRequest('DELETE', $objectKey, [], 'UNSIGNED-PAYLOAD');

        return $this->executeCurl('DELETE', $url, $headers);
    }

    public function buildObjectKey(string $filename): string
    {
        $prefix = trim($this->config['prefix'] ?? 'chat', '/');
        return $prefix . '/' . date('Y/m') . '/' . $filename;
    }

    public function getPublicUrl(string $objectKey, ?string $displayName = null): string
    {
        $filename = ($displayName !== null && $displayName !== '')
            ? $displayName
            : (basename($objectKey) ?: 'media.bin');

        $publicUrl = rtrim($this->config['public_url'] ?? '', '/');
        if ($publicUrl !== '') {
            return $publicUrl . '/' . ltrim($objectKey, '/');
        }

        return '/api/media.php?k=' . rawurlencode(base64_encode($objectKey))
            . '&f=' . rawurlencode($filename);
    }

    public function isR2Key(string $path): bool
    {
        $prefix = trim($this->config['prefix'] ?? 'chat', '/');
        return strncmp($path, $prefix . '/', strlen($prefix) + 1) === 0;
    }

    private function buildObjectUrl(string $objectKey): string
    {
        $endpoint = rtrim($this->config['endpoint'], '/');
        $bucket = $this->config['bucket'];
        $key = ltrim($objectKey, '/');

        return "{$endpoint}/{$bucket}/{$key}";
    }

    private function executeCurl(string $method, string $url, array $headers, ?string $body = null): bool
    {
        $curlHeaders = $this->formatCurlHeaders($headers);

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error('R2 request failed', [
                'method' => $method,
                'url' => $url,
                'http_code' => $httpCode,
                'response' => is_string($response) ? substr($response, 0, 500) : '',
                'curl_error' => $error,
            ]);
            return false;
        }

        return true;
    }

    private function formatCurlHeaders(array $headers): array
    {
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        return $curlHeaders;
    }

    /**
     * AWS Signature Version 4
     */
    private function signRequest(string $method, string $objectKey, array $headers, string $payloadHash): array
    {
        $service = 's3';
        $region = $this->config['region'] ?? 'auto';
        $accessKey = $this->config['access_key_id'];
        $secretKey = $this->config['secret_access_key'];
        $bucket = $this->config['bucket'];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $key = ltrim($objectKey, '/');
        $canonicalUri = '/' . $this->encodePathSegment($bucket);
        if ($key !== '') {
            $canonicalUri .= '/' . implode('/', array_map([$this, 'encodePathSegment'], explode('/', $key)));
        }

        $headers['Host'] = $this->host;
        $headers['X-Amz-Content-Sha256'] = $payloadHash;
        $headers['X-Amz-Date'] = $amzDate;

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            $canonicalHeaders .= $lower . ':' . trim($value) . "\n";
            $signedHeadersList[] = $lower;
        }
        sort($signedHeadersList);
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * S3 URI 编码（保留 / 不编码）
     */
    private function encodePathSegment(string $segment): string
    {
        return str_replace('%2F', '/', rawurlencode($segment));
    }

    private function getSigningKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
