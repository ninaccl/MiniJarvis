<?php

namespace App\Notification;

final class WeChatNotificationClient implements NotificationSender
{
    private $appId;
    private $appSecret;
    private $cache;
    private $templates;
    public function __construct($appId, $appSecret, AccessTokenCache $cache, TemplateConfig $templates)
    {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->cache = $cache;
        $this->templates = $templates;
    }
    public function send(array $job)
    {
        if ($this->appId === '' || $this->appSecret === '') {
            return SendResult::permanent('WeChat notification credentials are not configured.');
        }
        $token = $this->cache->get();
        if ($token === null) {
            $tokenResult = $this->accessToken();
            if ($tokenResult instanceof SendResult) {
                return $tokenResult;
            }
            $token = $tokenResult;
        }
        try {
            $message = $this->templates->message($job);
        } catch (\Exception $ignored) {
            return SendResult::permanent('WeChat notification template is not configured.');
        }
        $message['touser'] = $job['openid'];
        $result = $this->postJson('https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=' . rawurlencode($token), $message);
        if (!is_array($result)) {
            return SendResult::transient('WeChat subscription transport failed.');
        }
        $code = (int) (isset($result['errcode']) ? $result['errcode'] : -1);
        if ($code === 0) {
            return SendResult::sent();
        }
        $messageText = 'WeChat error ' . $code . ': ' . (string) (isset($result['errmsg']) ? $result['errmsg'] : 'unknown');
        if ($code === 42001) {
            $this->cache->put('', 0);
        }
        return in_array($code, [-1, 42001, 45009], true) ? SendResult::transient($messageText) : SendResult::permanent($messageText);
    }
    /** @return string|SendResult */
    private function accessToken()
    {
        $url = 'https://api.weixin.qq.com/cgi-bin/token?' . http_build_query(['grant_type' => 'client_credential', 'appid' => $this->appId, 'secret' => $this->appSecret]);
        $result = $this->getJson($url);
        if (!is_array($result) || !is_string(isset($result['access_token']) ? $result['access_token'] : null)) {
            return SendResult::transient('WeChat access token request failed.');
        }
        $this->cache->put($result['access_token'], max(60, (int) (isset($result['expires_in']) ? $result['expires_in'] : 7200) - 120));
        return $result['access_token'];
    }
    /** @return array<string,mixed>|null */
    private function getJson($url)
    {
        return $this->request($url, null);
    }
    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    private function postJson($url, array $payload)
    {
        return $this->request($url, \App\Support\Compat::jsonEncode($payload));
    }
    /** @return array<string,mixed>|null */
    private function request($url, $body)
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($body !== null) {
            $options += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']];
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            return null;
        }
        try {
            $decoded = \App\Support\Compat::jsonDecode($raw);
            return is_array($decoded) ? $decoded : null;
        } catch (\RuntimeException $ignored) {
            return null;
        }
    }
}
