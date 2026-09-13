<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Notification\NotificationSender;
use App\Notification\SendResult;

final class QueueNotificationSender implements NotificationSender
{
    /** @param list<SendResult> $results */
    public function __construct(public array $results = []) {}
    public array $sent = [];
    public function send(array $job): SendResult { $this->sent[] = $job; return array_shift($this->results) ?? SendResult::sent(); }
}
