<?php

namespace App\Core\Privacy;

use App\Core\Identity\Models\User;
use Closure;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * NFR-08: the registry of personal data per subject type. Core registers
 * customers (parties) and users; each module registers its own tables
 * (HR employees, Payroll, CRM leads, E-commerce accounts and addresses, the
 * POS, Loyalty, Accounting and Procurement records about a customer), so
 * core never hard-codes another module's schema. A table that is not
 * installed is skipped.
 *
 * Modules may also register:
 * - a **mask** for a table's rows in an access report, given who downloads
 *   it (HR hides the sensitive groups the downloader may not see, review H2);
 * - a **retention** rule per subject type: columns anonymisation must keep
 *   for a legal obligation, or a reason to refuse it for now (the POS keeps
 *   a customer's tax identity while fiscal documents need it, review M3);
 * - a **guard** per subject type: whether a user may ask for or carry out a
 *   deletion (users: UserPolicy::deactivate, review M4).
 */
class PrivacySubjects
{
    /** @var array<string, list<SubjectSource>> */
    private array $sources = [];

    /** @var array<string, list<Closure(list<array<string, mixed>>, User): list<array<string, mixed>>>> */
    private array $masks = [];

    /** @var array<string, list<Closure(string): ?Retention>> */
    private array $retentions = [];

    /** @var array<string, list<Closure(User, string): bool>> */
    private array $guards = [];

    /** @var array<string, bool> tables installed, read once per process */
    private array $installed = [];

    public function register(SubjectSource $source): void
    {
        if ($source->primary && $this->primaryOrNull($source->subjectType) !== null) {
            throw new InvalidArgumentException("Subject type [{$source->subjectType}] already has a primary source.");
        }

        $this->sources[$source->subjectType][] = $source;
    }

    /** @param Closure(list<array<string, mixed>>, User): list<array<string, mixed>> $mask */
    public function mask(string $table, Closure $mask): void
    {
        $this->masks[$table][] = $mask;
    }

    /** @param Closure(string): ?Retention $rule */
    public function retain(string $subjectType, Closure $rule): void
    {
        $this->retentions[$subjectType][] = $rule;
    }

    /** @param Closure(User, string): bool $guard */
    public function guardDeletion(string $subjectType, Closure $guard): void
    {
        $this->guards[$subjectType][] = $guard;
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
        $sources = array_values(array_filter($this->sources[$type] ?? [], fn (SubjectSource $s) => $this->installed($s->table)));
        usort($sources, fn (SubjectSource $a, SubjectSource $b) => $b->primary <=> $a->primary);

        return $sources;
    }

    /**
     * $rows of $table as $downloader may see them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function masked(string $table, array $rows, User $downloader): array
    {
        foreach ($this->masks[$table] ?? [] as $mask) {
            $rows = $mask($rows, $downloader);
        }

        return $rows;
    }

    /** @return list<Retention> */
    public function retentions(string $type, string $subjectId): array
    {
        return array_values(array_filter(array_map(fn (Closure $rule) => $rule($subjectId), $this->retentions[$type] ?? [])));
    }

    public function mayDelete(string $type, User $user, string $subjectId): bool
    {
        foreach ($this->guards[$type] ?? [] as $guard) {
            if (! $guard($user, $subjectId)) {
                return false;
            }
        }

        return true;
    }

    private function installed(string $table): bool
    {
        return $this->installed[$table] ??= Schema::hasTable($table);
    }

    private function primaryOrNull(string $type): ?SubjectSource
    {
        foreach ($this->sources[$type] ?? [] as $source) {
            if ($source->primary && $this->installed($source->table)) {
                return $source;
            }
        }

        return null;
    }
}
