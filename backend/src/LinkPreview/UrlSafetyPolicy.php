<?php

namespace App\LinkPreview;

use App\Http\ApiException;
final class UrlSafetyPolicy
{
    private $dns;
    private $pageHosts;
    private $imageHosts;
    public static function defaultHosts()
    {
        return ['douyin.com', 'v.douyin.com', 'bilibili.com', 'b23.tv', 'xiaohongshu.com', 'xhslink.com'];
    }
    /** @param list<string>|null $pageHosts @param list<string>|null $imageHosts */
    public function __construct(DnsResolver $dns, $pageHosts = null, $imageHosts = null)
    {
        $this->dns = $dns;
        $this->pageHosts = $pageHosts;
        $this->imageHosts = $imageHosts;
    }
    public function validatePage($url, $dnsTimeoutMilliseconds)
    {
        return $this->validate($url, isset($this->pageHosts) ? $this->pageHosts : self::defaultHosts(), $dnsTimeoutMilliseconds);
    }
    public function validateImage($url, $dnsTimeoutMilliseconds)
    {
        return $this->validate($url, isset($this->imageHosts) ? $this->imageHosts : (isset($this->pageHosts) ? $this->pageHosts : self::defaultHosts()), $dnsTimeoutMilliseconds);
    }
    /** @param list<string> $allowedHosts */
    private function validate($url, array $allowedHosts, $dnsTimeoutMilliseconds)
    {
        if (strlen($url) > 2048) {
            throw $this->unsafe();
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) (isset($parts['scheme']) ? $parts['scheme'] : '')) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) && $parts['port'] !== 443) {
            throw $this->unsafe();
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || filter_var($host, FILTER_VALIDATE_IP) !== false || !$this->matchesAnyHost($host, $allowedHosts)) {
            throw $this->unsafe();
        }
        $normalized = preg_replace('/#.*$/s', '', $url) !== null ? preg_replace('/#.*$/s', '', $url) : $url;
        try {
            $addresses = $this->dns->resolve($host, $dnsTimeoutMilliseconds);
        } catch (DnsLookupTimedOut $ignored) {
            throw new DnsResolutionTimedOut($normalized, $this->platform($host));
        } catch (\Exception $ignored) {
            throw new DnsResolutionFailed($normalized, $this->platform($host));
        }
        if ($addresses === []) {
            throw $this->unsafe();
        }
        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw $this->unsafe();
            }
        }
        return new ValidatedUrl($normalized, $host, $this->platform($host), array_values(array_unique($addresses)));
    }
    /** @param list<string> $allowedHosts */
    private function matchesAnyHost($host, array $allowedHosts)
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed, " \t\n\r\x00\v."));
            if ($allowed !== '' && ($host === $allowed || \App\Support\Compat::endsWith($host, '.' . $allowed))) {
                return true;
            }
        }
        return false;
    }
    private function platform($host)
    {
        if ($this->matchesAnyHost($host, ['douyin.com', 'v.douyin.com'])) {
            return 'douyin';
        }
        if ($this->matchesAnyHost($host, ['bilibili.com', 'b23.tv'])) {
            return 'bilibili';
        }
        if ($this->matchesAnyHost($host, ['xiaohongshu.com', 'xhslink.com'])) {
            return 'xiaohongshu';
        }
        return 'other';
    }
    private function isPublicAddress($address)
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return $this->isPublicIpv4($packed);
        }
        if (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            return $this->isPublicIpv4(substr($packed, 12, 4));
        }
        $first = ord($packed[0]);
        if (($first & 0xe0) !== 0x20) {
            return false;
        }
        if ($this->hasPrefix($packed, inet_pton('2001::'), 23)) {
            return false;
        }
        if ($this->hasPrefix($packed, inet_pton('2001:db8::'), 32)) {
            return false;
        }
        if ($this->hasPrefix($packed, inet_pton('3fff::'), 20)) {
            return false;
        }
        return true;
    }
    private function isPublicIpv4($packed)
    {
        $value = unpack('N', $packed)[1];
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['192.88.99.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as list($network, $bits)) {
            $networkValue = unpack('N', inet_pton($network))[1];
            $mask = $bits === 0 ? 0 : 0xffffffff << 32 - $bits & 0xffffffff;
            if (($value & $mask) === ($networkValue & $mask)) {
                return false;
            }
        }
        return true;
    }
    private function hasPrefix($address, $network, $bits)
    {
        if ($network === false) {
            return false;
        }
        $bytes = (int) floor($bits / 8);
        $remaining = $bits % 8;
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = 0xff << 8 - $remaining & 0xff;
        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
    private function unsafe()
    {
        return new ApiException(422, 'UNSAFE_URL', 'The URL is not an allowed public HTTPS destination.');
    }
}
