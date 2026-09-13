<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Config\Config;
use App\Notification\TemplateConfig;
use PHPUnit\Framework\TestCase;

final class TemplateConfigTest extends TestCase
{
    public function testTaskDeadlineIsDisplayedInConfiguredTimezoneAcrossMidnight(): void
    {
        $job = ['job_type' => 'task_due', 'payload' => ['title' => '准备早餐', 'due_at' => '2026-09-13T16:30:00.000000Z']];
        $default = new TemplateConfig(new Config(['WECHAT_TASK_DUE_TEMPLATE_ID' => 'task-template']));
        self::assertSame('2026-09-14 00:30', $default->message($job)['data']['time2']['value']);
        $tokyo = new TemplateConfig(new Config(['WECHAT_TASK_DUE_TEMPLATE_ID' => 'task-template', 'CALENDAR_TIMEZONE' => 'Asia/Tokyo']));
        self::assertSame('2026-09-14 01:30', $tokyo->message($job)['data']['time2']['value']);
    }
}
