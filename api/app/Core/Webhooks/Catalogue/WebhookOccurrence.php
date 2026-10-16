<?php

namespace App\Core\Webhooks\Catalogue;

use App\Core\Rbac\Scope;

/**
 * HOOK-01: one happening of a public event, as captured from the internal
 * domain event (WebhookEventType::$capture, no queries): the documented
 * `data`, where it happened (company, branch, location; the fan-out job
 * completes what is missing) and, for a document event, the document.
 * Carried by the fan-out job as plain data (toArray / fromArray).
 */
final class WebhookOccurrence
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{type: string, id: string}|null  $document
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $companyId = null,
        public readonly ?string $branchId = null,
        public readonly ?string $locationId = null,
        public readonly ?array $document = null,
    ) {}

    /** The narrowest known scope, for permission checks (the tenant when nothing is known). */
    public function scope(): Scope
    {
        return match (true) {
            $this->locationId !== null => Scope::location($this->locationId),
            $this->branchId !== null => Scope::branch($this->branchId),
            $this->companyId !== null => Scope::company($this->companyId),
            default => Scope::tenant(),
        };
    }

    /** A copy with the given scope parts filled in (nulls keep what is known). */
    public function at(?string $companyId, ?string $branchId = null, ?string $locationId = null): self
    {
        return new self($this->data, $companyId ?? $this->companyId, $branchId ?? $this->branchId, $locationId ?? $this->locationId, $this->document);
    }

    /** @param  array<string, mixed>  $data */
    public function with(array $data): self
    {
        return new self($data, $this->companyId, $this->branchId, $this->locationId, $this->document);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'company_id' => $this->companyId,
            'branch_id' => $this->branchId,
            'location_id' => $this->locationId,
            'document' => $this->document,
        ];
    }

    /** @param  array<string, mixed>  $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (array) ($value['data'] ?? []),
            $value['company_id'] ?? null,
            $value['branch_id'] ?? null,
            $value['location_id'] ?? null,
            $value['document'] ?? null,
        );
    }
}
