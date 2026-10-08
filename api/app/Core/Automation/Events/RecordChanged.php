<?php

namespace App\Core\Automation\Events;

use App\Core\Automation\Chain\AutomationChain;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * AUTO-01: a document of a registered type was created, updated or
 * archived. Modules raise it through RaisesRecordChanges after writing
 * (dispatched after commit), with the document's field values before and
 * after (DocumentType::fieldValues() shapes). The automation chain current
 * when it is raised is captured (AUTO-06), so a change made by a rule's
 * action carries that rule's chain.
 */
class RecordChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const ARCHIVED = 'archived';

    /** @var array{chain_id: string, depth: int, rules: list<string>}|null */
    public readonly ?array $cause;

    /**
     * @param  array<string, mixed>  $old  values before (empty when created)
     * @param  array<string, mixed>  $new  values after
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentType,
        public readonly string $documentId,
        public readonly string $change,
        public readonly array $old,
        public readonly array $new,
        public readonly ?string $userId = null,
    ) {
        if (! in_array($change, [self::CREATED, self::UPDATED, self::ARCHIVED], true)) {
            throw new InvalidArgumentException("Unknown record change [{$change}].");
        }

        $this->cause = app(AutomationChain::class)->current()?->toArray();
    }
}
