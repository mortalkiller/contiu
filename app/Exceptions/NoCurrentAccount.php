<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown instead of silently returning every account's rows: scoped queries fail closed.
 */
class NoCurrentAccount extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('No current account is set; the query was blocked to protect other accounts’ data.'));
    }
}
