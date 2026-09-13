<?php

declare(strict_types=1);

namespace App\LinkPreview;

final class SystemDnsResolver implements DnsResolver
{
    public function __construct(private readonly DnsCommandFactory $commands)
    {
        if (!is_file($commands->binary()) || !is_executable($commands->binary())) {
            throw new \RuntimeException('Configured PHP CLI binary is not executable.');
        }
        if (!function_exists('proc_open')) throw new \RuntimeException('proc_open is required for bounded DNS resolution.');
    }

    public function resolve(string $host, int $timeoutMilliseconds): array
    {
        if ($timeoutMilliseconds < 1) throw new DnsLookupTimedOut();
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        $pipes = [];
        $process = proc_open(
            $this->commands->forHost($host),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) throw new \RuntimeException('DNS resolver process could not start.');
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
            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                proc_terminate($process, 9);
                foreach ($pipes as $pipe) fclose($pipe);
                proc_close($process);
                throw new DnsLookupTimedOut();
            }
            usleep((int) min(10_000, max(1, intdiv($remainingNanoseconds, 1000))));
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        if ($exitCode !== 0) throw new \RuntimeException('DNS resolution failed: ' . trim($stderr));
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) return [];
        return array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value)));
    }
}
