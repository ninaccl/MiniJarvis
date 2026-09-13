<?php

declare(strict_types=1);

namespace App\Notification;

interface NotificationSender
{
    /** @param array<string,mixed> $job */
    public function send(array $job): SendResult;
}
