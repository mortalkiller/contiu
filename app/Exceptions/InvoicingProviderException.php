<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A billing provider refused a request or could not be reached. The message is written for the
 * user and never carries credentials or the provider's raw answer.
 */
final class InvoicingProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
