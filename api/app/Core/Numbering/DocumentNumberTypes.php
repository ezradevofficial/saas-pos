<?php

namespace App\Core\Numbering;

use App\Core\Rbac\ModuleRegistry;
use Closure;

/** The numbered document types modules register (NUM-01). */
class DocumentNumberTypes
{
    /** @var array<string, DocumentNumberType> */
    private array $types = [];

    /** @var list<Closure(): list<DocumentNumberType>> */
    private array $sources = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(DocumentNumberType $type): void
    {
        $this->types[$type->key] = $type;
    }

    /** CF-04: numbered types that exist per tenant (custom form types), for the current tenant. */
    public function registerSource(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function find(string $key): ?DocumentNumberType
    {
        return $this->types[$key] ?? $this->fromSources()[$key] ?? null;
    }

    /** Types of modules active for the current tenant (RBAC-08), by key. */
    public function active(): array
    {
        $types = array_filter([...$this->fromSources(), ...$this->types], fn (DocumentNumberType $type) => $this->modules->isActive($type->module));
        ksort($types);

        return array_values($types);
    }

    /** @return array<string, DocumentNumberType> */
    private function fromSources(): array
    {
        $found = [];

        foreach ($this->sources as $source) {
            foreach ($source() as $type) {
                $found[$type->key] ??= $type;
            }
        }

        return $found;
    }
}
