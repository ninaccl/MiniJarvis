<?php

declare(strict_types=1);

namespace App\Notification;

final class SendResult
{
    private function __construct(public readonly bool $sent, public readonly bool $transient, public readonly string $error)
    {
    }

    public static function sent(): self { return new self(true, false, ''); }
    public static function transient(string $error): self { return new self(false, true, $error); }
    public static function permanent(string $error): self { return new self(false, false, $error); }
}
