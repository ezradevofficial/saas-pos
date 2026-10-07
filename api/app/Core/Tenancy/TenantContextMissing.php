<?php

namespace App\Core\Tenancy;

use RuntimeException;

class TenantContextMissing extends RuntimeException
{
    public function __construct(string $message = 'No tenant context is set.')
    {
        parent::__construct($message);
    }
}
