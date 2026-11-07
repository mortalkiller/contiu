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
        return new self("Não é possível apagar {$what}: já tem recebimentos registados. Apaga primeiro os recebimentos.");
    }
}
