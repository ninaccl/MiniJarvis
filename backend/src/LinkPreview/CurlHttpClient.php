<?php

namespace App\LinkPreview;

use RuntimeException;
final class CurlHttpClient implements HttpClient
{
    public function get($url, array $resolvedAddresses, $connectionTimeoutMilliseconds, $totalTimeoutMilliseconds, $bodyLimit)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $body = '';
        $headers = [];
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('HTTP client could not initialize.');
        }
        $resolve = array_map(static function ($ip) use ($host) {
            return $host . ':443:' . (\App\Support\Compat::contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }, $resolvedAddresses);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT_MS => $connectionTimeoutMilliseconds, CURLOPT_TIMEOUT_MS => $totalTimeoutMilliseconds, CURLOPT_RESOLVE => $resolve, CURLOPT_HTTPHEADER => ['Accept: text/html,image/*;q=0.9,*/*;q=0.1', 'User-Agent: JarvisFamilyKitchenPreview/1.0'], CURLOPT_ENCODING => '', CURLOPT_HEADERFUNCTION => static function ($handle, $line) use (&$headers) {
            if (\App\Support\Compat::startsWith($line, 'HTTP/')) {
                $headers = [];
            }
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        }, CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body, &$tooLarge, $bodyLimit) {
            if (strlen($body) + strlen($chunk) > $bodyLimit) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) (curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: (isset($headers['content-type']) ? $headers['content-type'] : ''));
        $error = curl_error($curl);
        curl_close($curl);
        if ($tooLarge) {
            throw new ResponseTooLarge();
        }
        if ($result === false) {
            throw new RuntimeException('Remote request failed: ' . $error);
        }
        return new HttpResponse($status, $headers, $body, strtolower(trim(explode(';', $contentType)[0])));
    }
}
