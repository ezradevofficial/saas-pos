<?php

namespace App\Core\CustomFields\Formula;

use RuntimeException;

/**
 * A formula that cannot be read or computed (CF-01). `key` is the
 * translation key under `core.custom_field.formula_errors`, `params` its
 * replacements; the message is never built from user input as code.
 */
final class FormulaError extends RuntimeException
{
    /** @param array<string, string|int> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }

    public function translated(): string
    {
        return __('core.custom_field.formula_errors.'.$this->key, $this->params);
    }
}
