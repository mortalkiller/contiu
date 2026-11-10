<?php

namespace App\Exceptions;

use RuntimeException;

final class InvoiceXpressException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message, $status ?? 0);
    }
}
