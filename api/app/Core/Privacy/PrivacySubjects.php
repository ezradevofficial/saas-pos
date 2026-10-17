<?php

namespace App\Core\Privacy;

use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * NFR-08: the registry of personal data per subject type. Core registers
 * customers (parties) and users; each module registers its own tables
 * (HR employees, Payroll, CRM leads, E-commerce customers), so core never
 * hard-codes another module's schema. A table that is not installed is
 * skipped.
 */
class PrivacySubjects
{
    /** @var array<string, list<SubjectSource>> */
    private array $sources = [];

    public function register(SubjectSource $source): void
    {
        if ($source->primary && $this->primaryOrNull($source->subjectType) !== null) {
            throw new InvalidArgumentException("Subject type [{$source->subjectType}] already has a primary source.");
        }

        $this->sources[$source->subjectType][] = $source;
    }

    /** @return list<string> subject types that have a primary source installed */
    public function types(): array
    {
        return array_values(array_filter(array_keys($this->sources), fn (string $type) => $this->primaryOrNull($type) !== null));
    }

    public function primary(string $type): SubjectSource
    {
        return $this->primaryOrNull($type) ?? throw new InvalidArgumentException("Unknown subject type [{$type}].");
    }

    /** @return list<SubjectSource> installed sources, the primary first */
    public function sources(string $type): array
    {
        $sources = array_values(array_filter($this->sources[$type] ?? [], fn (SubjectSource $s) => Schema::hasTable($s->table)));
        usort($sources, fn (SubjectSource $a, SubjectSource $b) => $b->primary <=> $a->primary);

        return $sources;
    }

    private function primaryOrNull(string $type): ?SubjectSource
    {
        foreach ($this->sources[$type] ?? [] as $source) {
            if ($source->primary && Schema::hasTable($source->table)) {
                return $source;
            }
        }

        return null;
    }
}
