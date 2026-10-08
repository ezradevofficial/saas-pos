<?php

namespace Tests\Support\Workflow;

/**
 * A test target type in an optional module (`wfext`), to switch its module
 * off after a flow was published (RBAC-08, WF-07, WF-11).
 */
class ExtOrderType extends TestOrderType
{
    public const MODULE = 'wfext';

    public const KEY = 'wfext.order';

    public function key(): string
    {
        return self::KEY;
    }
}
