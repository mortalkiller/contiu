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
        parent::__construct(__('The account is read-only. Subscribe to continue recording transactions.'));
    }
}
