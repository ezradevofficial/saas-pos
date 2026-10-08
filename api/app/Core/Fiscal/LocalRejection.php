<?php

namespace App\Core\Fiscal;

use RuntimeException;

/**
 * A document that cannot be sent as it stands (a line whose tax code has
 * no fiscal code, an item without a classification): never sent, marked
 * rejected with this reason until someone fixes the data and retries.
 * Rates are never filled in to get past it.
 */
class LocalRejection extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
