<?php

namespace App\Core\Configuration;

use App\Core\Http\ApiException;

/**
 * A payload that cannot be published (422): `problems` lists each one with
 * its path, code and translated message, so a designer can point at it.
 */
class InvalidPayload extends ApiException
{
    /** @param list<array{path: string, code: string, message: string}> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(422, 'config_invalid', __('config.errors.config_invalid'), [], ['problems' => $problems]);
    }
}
