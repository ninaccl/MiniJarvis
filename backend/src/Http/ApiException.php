<?php

namespace App\Http;

use RuntimeException;
final class ApiException extends RuntimeException
{
    private $status;
    private $errorCode;
    private $fields;
    /** @param array<string, string> $fields */
    public function __construct($status, $errorCode, $message, array $fields = [])
    {
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
        parent::__construct($message, $status);
    }
    public function status()
    {
        return $this->status;
    }
    public function errorCode()
    {
        return $this->errorCode;
    }
    /** @return array<string, string> */
    public function fields()
    {
        return $this->fields;
    }
}
