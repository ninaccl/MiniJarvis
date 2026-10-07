<?php

namespace App\Notification;

final class SendResult
{
    public $sent;
    public $transient;
    public $error;
    private function __construct($sent, $transient, $error)
    {
        $this->sent = $sent;
        $this->transient = $transient;
        $this->error = $error;
    }
    public static function sent()
    {
        return new self(true, false, '');
    }
    public static function transient($error)
    {
        return new self(false, true, $error);
    }
    public static function permanent($error)
    {
        return new self(false, false, $error);
    }
}
