<?php

namespace App\Core\Billing\Catalogue\Pricing;

use RuntimeException;

/**
 * A selection the catalogue cannot price. `reason` is stable for the API and
 * names the translation (`billing.quote.<reason>`), `field` the input it is
 * about and `key` the module or bundle.
 */
final class QuoteError extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $field, public readonly ?string $key = null)
    {
        parent::__construct("{$reason}: {$field}".($key === null ? '' : " ({$key})"));
    }
}
