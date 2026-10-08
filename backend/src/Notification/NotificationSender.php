<?php

namespace App\Notification;

interface NotificationSender
{
    /** @param array<string,mixed> $job */
    public function send(array $job);
}
