<?php

namespace App\Core\Privacy;

use App\Core\DataExport\TenantTables;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-08: reads and changes one data subject's personal data, always on
 * the runtime connection under row-level security (TEN-01), and only in
 * the tables modules declared about that kind of subject (PrivacySubjects).
 * Every query also names the current tenant, so a table without row-level
 * security (verification challenges) is just as confined.
 *
 * - report(): the access report: the subject's rows in each declared
 *   table, with the tenant export's column rules (TenantTables::exported:
 *   no secrets, password or PIN material, tokens), each table's mask for
 *   the downloader (HR's sensitive groups, review H2) and the downloader's
 *   field rules on the table's resource (Phase 8 review M7). Nothing is found by
 *   following foreign keys, so other people's rows never appear (review H1).
 * - correct(): the approved new values of correctable fields.
 * - anonymise(): personal columns replaced in every declared table, except
 *   what a retention rule keeps for a legal obligation (review M3); the
 *   subject's row archived. Query-builder updates on purpose: an audited
 *   model update would copy the old personal values into the audit log.
 *   PrivacyRequests audits the change with the columns only.
 */
class SubjectData
{
    /** A value the downloader may not see, in an access report. */
    public const HIDDEN = '[hidden]';

    public function __construct(
        private readonly PrivacySubjects $subjects,
        private readonly TenantTables $tables,
        private readonly FieldRules $fieldRules,
    ) {}

    private function db(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }

    /** The rows of $source about $subjectId, in this tenant. */
    private function query(SubjectSource $source, string $subjectId): Builder
    {
        $query = $this->db()->table($source->table);
        if (Schema::hasColumn($source->table, 'tenant_id')) {
            $query->where($source->table.'.tenant_id', app(TenantContext::class)->require());
        }

        return $source->scope($query, $subjectId);
    }

    public function exists(string $type, string $id): bool
    {
        return $this->query($this->subjects->primary($type), $id)->exists();
    }

    /** A short name for lists: the display columns joined. */
    public function display(string $type, string $id): ?string
    {
        $primary = $this->subjects->primary($type);
        $row = $this->query($primary, $id)->first($primary->display ?: ['id']);

        return $row === null ? null : trim(implode(' ', array_filter(array_map('strval', (array) $row))));
    }

    /**
     * Display names of many subjects of one type, in one query (lists).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function displayMany(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $primary = $this->subjects->primary($type);
        $columns = array_values(array_unique([$primary->match, ...($primary->display ?: ['id'])]));

        return $this->db()->table($primary->table)->whereIn($primary->match, array_values(array_unique($ids)))->get($columns)
            ->mapWithKeys(fn ($row) => [(string) $row->{$primary->match} => trim(implode(' ', array_filter(array_map(fn ($c) => (string) ($row->{$c} ?? ''), $primary->display ?: ['id']))))])
            ->all();
    }

    /** The subject's company's country (deadlines), or null. */
    public function country(string $type, string $id): ?string
    {
        $primary = $this->subjects->primary($type);
        if ($primary->companyColumn === null || ! Schema::hasColumn($primary->table, $primary->companyColumn)) {
            return null;
        }

        $companyId = $this->query($primary, $id)->value($primary->companyColumn);

        return $companyId === null ? null : $this->db()->table('companies')->where('id', $companyId)->value('country');
    }

    /** @return list<array{id: string, name: string}> */
    public function search(string $type, string $term, int $limit = 20): array
    {
        $primary = $this->subjects->primary($type);
        $query = $primary->subjectsOnly($this->db()->table($primary->table)->select(array_values(array_unique(['id', ...$primary->display])))->limit($limit)->orderBy('id'));

        if (trim($term) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
            $query->where(function ($q) use ($primary, $like) {
                foreach ($primary->display as $column) {
                    $q->orWhereRaw('cast('.$this->db()->getQueryGrammar()->wrap($column).' as text) ilike ?', [$like]);
                }
            });
        }

        return $query->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => trim(implode(' ', array_filter(array_map(fn ($c) => (string) ($row->{$c} ?? ''), $primary->display)))),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function report(string $type, string $id, User $downloader): array
    {
        $limit = (int) config('privacy.report_rows_per_table', 500);
        $records = [];
        $related = [];

        foreach ($this->subjects->sources($type) as $source) {
            if (! $source->report) {
                continue;
            }

            $count = (int) $this->query($source, $id)->count();
            if ($count === 0 && ! $source->primary) {
                continue;
            }

            $rows = $this->withFieldRules($source, $this->subjects->masked($source->table, $this->rows($source, $id, $limit), $downloader), $downloader);
            if ($source->related) {
                $related[$source->table] = ['count' => $count, 'rows' => $rows];
            } else {
                $records[$source->table] = $rows;
            }
        }

        return [
            'subject' => ['type' => $type, 'id' => $id],
            'generated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'records' => $records,
            'related' => $related,
            'retained_for_legal_obligation' => $this->retainedFor($type, $id),
            'notes' => [
                'Secrets (password and PIN material, tokens, keys) are never included.',
                'Related tables show at most '.$limit.' rows each; counts are complete.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $values  validated correctable fields
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function correct(string $type, string $id, array $values): array
    {
        $primary = $this->subjects->primary($type);
        $values = array_intersect_key($values, array_flip($primary->correctableFields()));
        $before = (array) $this->query($primary, $id)->first(array_keys($values));

        $update = $values;
        foreach ($update as $column => $value) {
            if (is_array($value)) {
                $update[$column] = json_encode($value);
            }
        }
        if (Schema::hasColumn($primary->table, 'updated_at')) {
            $update['updated_at'] = CarbonImmutable::now();
        }
        $this->query($primary, $id)->update($update);

        return ['before' => $before, 'after' => $values];
    }

    /** Refuse (422) when a module's retention rule says anonymisation must wait. */
    public function assertMayAnonymise(string $type, string $id): void
    {
        foreach ($this->subjects->retentions($type, $id) as $retention) {
            if ($retention->blockedBy !== null) {
                throw new ApiException(422, $retention->blockedBy, __("privacy.errors.{$retention->blockedBy}"));
            }
        }
    }

    /**
     * @return array{changed: array<string, list<string>>, archived: list<string>, kept: list<array{table: string, columns: list<string>, reason: string}>, notes: list<string>}
     */
    public function anonymise(string $type, string $id, string $label): array
    {
        $this->assertMayAnonymise($type, $id);

        $retentions = $this->subjects->retentions($type, $id);
        $changed = [];
        $archived = [];
        $notes = [];
        $now = CarbonImmutable::now();

        foreach ($this->subjects->sources($type) as $source) {
            $keep = [];
            foreach ($retentions as $retention) {
                $keep += $retention->keep[$source->table] ?? [];
            }

            $columns = array_filter($source->personal, fn (string $column) => Schema::hasColumn($source->table, $column), ARRAY_FILTER_USE_KEY);
            $update = [];
            foreach ($columns as $column => $how) {
                $rule = $keep[$column] ?? null;
                if ($rule === 'keep') {
                    continue;
                }
                if (is_string($rule) && str_starts_with($rule, 'copy:')) {
                    $update[$column] = DB::raw($this->db()->getQueryGrammar()->wrap(substr($rule, 5)));

                    continue;
                }
                $update[$column] = match ($how) {
                    'label' => $label,
                    'empty_array' => DB::raw("'[]'::jsonb"),
                    'empty_object' => DB::raw("'{}'::jsonb"),
                    'empty_string' => '',
                    // A unique, undeliverable address (users need an email or a phone).
                    'anon_email' => 'anonymised-'.$id.'@anonymised.invalid',
                    default => null,
                };
            }

            if ($update !== []) {
                if (Schema::hasColumn($source->table, 'updated_at')) {
                    $update['updated_at'] = $now;
                }
                if ($this->query($source, $id)->update($update) > 0) {
                    $changed[$source->table] = array_values(array_diff(array_keys($update), ['updated_at']));
                }
            }

            if (! $source->related && Schema::hasColumn($source->table, 'archived_at')
                && $this->query($source, $id)->whereNull('archived_at')->update(['archived_at' => $now]) > 0) {
                $archived[] = $source->table;
            }

            if ($source->after !== null) {
                array_push($notes, ...($source->after)($id));
            }
        }

        return ['changed' => $changed, 'archived' => $archived, 'kept' => $this->retainedFor($type, $id, $retentions), 'notes' => $notes];
    }

    /** @return list<string> declared tables of business records about the subject, kept (TEN-06) */
    public function retained(string $type, string $id): array
    {
        $kept = [];
        foreach ($this->subjects->sources($type) as $source) {
            if ($source->related && $this->query($source, $id)->exists()) {
                $kept[] = $source->table;
            }
        }

        return $kept;
    }

    /**
     * @param  list<Retention>|null  $retentions
     * @return list<array{table: string, columns: list<string>, reason: string}>
     */
    private function retainedFor(string $type, string $id, ?array $retentions = null): array
    {
        $out = [];
        foreach ($retentions ?? $this->subjects->retentions($type, $id) as $retention) {
            foreach ($retention->keep as $table => $columns) {
                $out[] = ['table' => $table, 'columns' => array_keys($columns), 'reason' => $retention->reason];
            }
        }

        return $out;
    }

    /**
     * RBAC-05 (Phase 8 review M7): $rows of $source without what the
     * downloader's field rules hide on the source's resource (a support
     * principal gets the most restrictive rules, FieldRules). Hidden values
     * become HIDDEN, so the reader still sees the record exists.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withFieldRules(SubjectSource $source, array $rows, User $downloader): array
    {
        if ($source->fieldRules === null || $rows === []) {
            return $rows;
        }

        $hidden = $this->fieldRules->for($downloader, $source->fieldRules)['hidden'];
        if ($hidden === []) {
            return $rows;
        }

        $columns = [];
        $custom = [];
        foreach ($hidden as $field) {
            if (str_starts_with($field, 'custom.')) {
                $custom[] = substr($field, 7);
            }
            array_push($columns, ...($source->fieldColumns[$field] ?? [$field]));
        }

        return array_map(function (array $row) use ($columns, $custom) {
            foreach ($columns as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = self::HIDDEN;
                }
            }
            if ($custom !== [] && is_array($row['custom'] ?? null)) {
                foreach ($custom as $key) {
                    if (array_key_exists($key, $row['custom'])) {
                        $row['custom'][$key] = self::HIDDEN;
                    }
                }
            }

            return $row;
        }, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function rows(SubjectSource $source, string $id, int $limit): array
    {
        $table = $source->table;
        $exported = array_values(array_filter($this->tables->columns($table), fn (string $c) => $this->tables->exported($table, $c)));
        if ($exported === []) {
            return [];
        }

        $query = $this->query($source, $id)->select(array_map(fn ($c) => $table.'.'.$c, $exported))->limit($limit);
        if (in_array('id', $exported, true)) {
            $query->orderBy($table.'.id');
        }

        $sql = 'select row_to_json(x)::text as line from ('.$query->toSql().') x';

        return array_map(fn ($row) => json_decode($row->line, true), $this->db()->select($sql, $query->getBindings()));
    }
}
