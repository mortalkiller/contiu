<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by BelongsToAccount when a write reaches a read-only account (trial over, nothing paying).
 * The panel shows the message as a notification (see AppServiceProvider), not an error page.
 */
class AccountIsReadOnly extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A conta está só de leitura. Subscreve para continuar a registar.');
    }
}
