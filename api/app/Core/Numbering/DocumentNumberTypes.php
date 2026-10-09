<?php

namespace App\Core\Numbering;

use App\Core\Rbac\ModuleRegistry;

/** The numbered document types modules register (NUM-01). */
class DocumentNumberTypes
{
    /** @var array<string, DocumentNumberType> */
    private array $types = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(DocumentNumberType $type): void
    {
        $this->types[$type->key] = $type;
    }

    public function find(string $key): ?DocumentNumberType
    {
        return $this->types[$key] ?? null;
    }

    /** Types of modules active for the current tenant (RBAC-08), by key. */
    public function active(): array
    {
        $types = array_filter($this->types, fn (DocumentNumberType $type) => $this->modules->isActive($type->module));
        ksort($types);

        return array_values($types);
    }
}
