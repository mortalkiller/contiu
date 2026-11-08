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
        parent::__construct('Não há conta atual definida; a consulta foi bloqueada para não expor dados de outras contas.');
    }
}
