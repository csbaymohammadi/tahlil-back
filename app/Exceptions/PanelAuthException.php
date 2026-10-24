<?php

namespace App\Exceptions;

use RuntimeException;

class PanelAuthException extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = [], public ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
