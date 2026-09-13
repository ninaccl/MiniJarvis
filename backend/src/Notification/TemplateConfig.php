<?php

declare(strict_types=1);

namespace App\Notification;

use App\Config\Config;

final class TemplateConfig
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $job @return array{template_id:string,page:string,data:array<string,array{value:string}>} */
    public function message(array $job): array
    {
        $payload = $job['payload'];
        if ($job['job_type'] === 'task_due') {
            return [
                'template_id' => $this->config->get('WECHAT_TASK_DUE_TEMPLATE_ID'),
                'page' => $this->config->get('WECHAT_TASKS_PAGE', 'pages/tasks/index'),
                'data' => [
                    $this->config->get('WECHAT_TASK_DUE_TITLE_KEY', 'thing1') => ['value' => $this->truncate((string) $payload['title'], 20)],
                    $this->config->get('WECHAT_TASK_DUE_TIME_KEY', 'time2') => ['value' => (new \DateTimeImmutable((string) $payload['due_at']))->setTimezone($this->config->calendarTimezone())->format('Y-m-d H:i')],
                ],
            ];
        }
        return [
            'template_id' => $this->config->get('WECHAT_EXPIRY_TEMPLATE_ID'),
            'page' => $this->config->get('WECHAT_INVENTORY_PAGE', 'pages/inventory/index'),
            'data' => [
                $this->config->get('WECHAT_EXPIRY_SUMMARY_KEY', 'thing1') => ['value' => $this->truncate((string) $payload['summary'], 20)],
            ],
        ];
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value, 'UTF-8') <= $limit ? $value : mb_substr($value, 0, $limit - 1, 'UTF-8') . '…';
    }
}
