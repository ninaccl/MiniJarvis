<?php

namespace App\LinkPreview;

final class SystemDnsResolver implements DnsResolver
{
    private $commands;
    public function __construct(DnsCommandFactory $commands)
    {
        $this->commands = $commands;
        if (function_exists('proc_open') && (!is_file($commands->binary()) || !is_executable($commands->binary()))) {
            throw new \RuntimeException('Configured PHP CLI binary is not executable.');
        }
    }
    public function resolve($host, $timeoutMilliseconds)
    {
        if ($timeoutMilliseconds < 1) {
            throw new DnsLookupTimedOut();
        }
        if (!function_exists('proc_open')) {
            return $this->resolveInProcess($host);
        }
        $deadline = \App\Support\Compat::nowSeconds() + $timeoutMilliseconds / 1000;
        $pipes = [];
        $process = proc_open($this->commands->forHost($host), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('DNS resolver process could not start.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            $remainingSeconds = $deadline - \App\Support\Compat::nowSeconds();
            if ($remainingSeconds <= 0) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
                throw new DnsLookupTimedOut();
            }
            usleep((int) min(10000, max(1, $remainingSeconds * 1000000)));
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('DNS resolution failed: ' . trim($stderr));
        }
        $decoded = \App\Support\Compat::jsonDecode($stdout);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static function ($value) {
            return is_string($value);
        }));
    }

    private function resolveInProcess($host)
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            throw new \RuntimeException('DNS resolution failed.');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) $addresses[] = (string) $record['ip'];
            if (isset($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
        }
        return array_values(array_unique($addresses));
    }
}
