<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Money already received must keep pointing at what it paid, so the record cannot be deleted.
 */
class HasPaymentsException extends RuntimeException
{
    public static function for(string $what): self
    {
        return new self(__('Cannot delete :record because it already has payments on record. Delete the payments first.', ['record' => $what]));
    }
}
