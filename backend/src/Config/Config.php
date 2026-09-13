<?php

declare(strict_types=1);

namespace App\Config;

use App\Http\ApiException;
use DateTimeZone;
use Throwable;

final class Config
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(): self
    {
        $keys = [
            'APP_ENV', 'APP_DEBUG', 'CALENDAR_TIMEZONE', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'WECHAT_APP_ID', 'WECHAT_APP_SECRET',
            'WECHAT_TASK_DUE_TEMPLATE_ID', 'WECHAT_EXPIRY_TEMPLATE_ID',
            'WECHAT_TASK_DUE_TITLE_KEY', 'WECHAT_TASK_DUE_TIME_KEY', 'WECHAT_EXPIRY_SUMMARY_KEY',
            'WECHAT_TASKS_PAGE', 'WECHAT_INVENTORY_PAGE', 'WECHAT_TOKEN_CACHE_FILE',
            'PUBLIC_ROOT', 'UPLOAD_PUBLIC_PREFIX', 'PREVIEW_ROOT',
            'LINK_PREVIEW_ALLOWED_HOSTS', 'LINK_PREVIEW_CDN_HOSTS',
            'PHP_CLI_BINARY',
        ];
        $values = [];
        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($value !== false && $value !== null) {
                $values[$key] = (string) $value;
            }
        }
        return new self($values);
    }

    public function get(string $key, ?string $default = null): string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        throw new ApiException(500, 'CONFIGURATION_ERROR', 'Required server configuration is missing.');
    }

    public function environment(): string
    {
        return $this->get('APP_ENV', 'production');
    }

    public function calendarTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->get('CALENDAR_TIMEZONE', 'Asia/Shanghai'));
        } catch (Throwable) {
            throw new ApiException(500, 'CONFIGURATION_ERROR', 'Calendar timezone configuration is invalid.');
        }
    }
}
