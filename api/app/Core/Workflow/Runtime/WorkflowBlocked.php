<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Http\ApiException;

/**
 * A move a document cannot make (WF-04): an entry or exit condition does
 * not hold, or the stage refuses the mover (WF-08). The message says what
 * happened; `reasons` lists each failed rule in the reader's language and
 * `node` names the stage. 422 for conditions, 403 for permissions.
 */
class WorkflowBlocked extends ApiException
{
    /** @param list<string> $reasons */
    public function __construct(
        string $code,
        string $message,
        public readonly array $reasons = [],
        public readonly ?string $node = null,
        int $status = 422,
    ) {
        parent::__construct($status, $code, $message, [], ['reasons' => $reasons, 'node' => $node]);
    }
}
