<?php

namespace App\Core\Workflow\Definitions;

use App\Core\Http\ApiException;

/**
 * A graph that cannot be saved or published (422): `problems` lists each
 * one with its code, translated message and node, so the builder can
 * point at it.
 */
class InvalidGraph extends ApiException
{
    /** @param list<array{code: string, message: string, node: ?string}> $problems */
    public function __construct(public readonly array $problems, string $code = 'workflow_invalid')
    {
        parent::__construct(422, $code, __('workflow.errors.'.$code), [], ['problems' => $problems]);
    }
}
