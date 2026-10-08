<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;

/**
 * The document approvers are resolved for (APR-02): its type, id, scope,
 * the scopes covering it as ScopeResolver sees them (`type:id`, the tenant
 * first, the document's own place last; a type naming only a location
 * gets its branch and company from the database), its field values (read
 * once) and the flow node (for its exit roles).
 */
final class ApprovalSubject
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @param  list<string>  $chain
     * @param  array<string, mixed>  $node
     */
    public function __construct(
        public readonly DocumentType $type,
        public readonly string $documentId,
        public readonly DocumentScope $scope,
        public readonly array $chain,
        public readonly array $node = [],
    ) {}

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values ??= $this->type->fieldValues($this->documentId);
    }

    /**
     * The scope $levels above the document's own place (0: the place
     * itself), e.g. location → branch → company → tenant; null beyond the
     * tenant.
     */
    public function ancestor(int $levels): ?string
    {
        $index = count($this->chain) - 1 - $levels;

        return $index >= 0 ? $this->chain[$index] : null;
    }

    /** The `type:id` of the document's $type ancestor (company, branch, location), if known. */
    public function at(string $type): ?string
    {
        foreach ($this->chain as $link) {
            if (str_starts_with($link, $type.':')) {
                return $link;
            }
        }

        return null;
    }
}
