<?php

namespace App\Core\Privacy;

use App\Core\DataExport\TenantTables;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-08: reads and changes one data subject's personal data, always on
 * the runtime connection under row-level security (TEN-01), through the
 * sources modules registered (PrivacySubjects).
 *
 * - report(): the access report. The subject's rows in every source, and
 *   the rows of tables that reference the subject's own row by a foreign
 *   key (their purchases, leave, payslips...), with the tenant export's
 *   column rules (TenantTables::exported: no secrets, password or PIN
 *   material, tokens). "Who did it" columns (`*_by`) and references from
 *   the subject's own table (an employee's reports) are left out: those
 *   rows are about other people.
 * - correct(): the approved new values of correctable fields.
 * - anonymise(): personal columns replaced in every source, the subject's
 *   row archived. Query-builder updates on purpose: an audited model
 *   update would copy the old personal values into the audit log, which
 *   would undo the anonymisation. PrivacyRequests audits the change with
 *   the columns only.
 */
class SubjectData
{
    public function __construct(
        private readonly PrivacySubjects $subjects,
        private readonly TenantTables $tables,
    ) {}

    private function db(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }

    public function exists(string $type, string $id): bool
    {
        $primary = $this->subjects->primary($type);

        return $this->db()->table($primary->table)->where($primary->match, $id)->exists();
    }

    /** A short name for lists: the display columns joined. */
    public function display(string $type, string $id): ?string
    {
        $primary = $this->subjects->primary($type);
        $row = $this->db()->table($primary->table)->where($primary->match, $id)->first($primary->display ?: ['id']);

        return $row === null ? null : trim(implode(' ', array_filter(array_map('strval', (array) $row))));
    }

    /** The subject's company's country (deadlines), or null. */
    public function country(string $type, string $id): ?string
    {
        $primary = $this->subjects->primary($type);
        if ($primary->companyColumn === null || ! Schema::hasColumn($primary->table, $primary->companyColumn)) {
            return null;
        }

        $companyId = $this->db()->table($primary->table)->where($primary->match, $id)->value($primary->companyColumn);

        return $companyId === null ? null : $this->db()->table('companies')->where('id', $companyId)->value('country');
    }

    /** @return list<array{id: string, name: string}> */
    public function search(string $type, string $term, int $limit = 20): array
    {
        $primary = $this->subjects->primary($type);
        $query = $this->db()->table($primary->table)->select(array_values(array_unique(['id', ...$primary->display])))->limit($limit)->orderBy('id');

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
    public function report(string $type, string $id): array
    {
        $limit = (int) config('privacy.report_rows_per_table', 500);
        $records = [];
        $seen = [];

        foreach ($this->subjects->sources($type) as $source) {
            $records[$source->table] = $this->rows($source->table, $source->match, $id, $limit);
            $seen[$source->table.'.'.$source->match] = true;
        }

        $primary = $this->subjects->primary($type);
        $related = [];
        foreach ($this->references($primary->table) as [$table, $column]) {
            if (isset($seen["{$table}.{$column}"])) {
                continue;
            }
            $count = (int) $this->db()->table($table)->where($column, $id)->count();
            if ($count === 0) {
                continue;
            }
            $related[$table.'.'.$column] = ['count' => $count, 'rows' => $this->rows($table, $column, $id, $limit)];
        }

        return [
            'subject' => ['type' => $type, 'id' => $id],
            'generated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'records' => $records,
            'related' => $related,
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
        $before = (array) $this->db()->table($primary->table)->where($primary->match, $id)->first(array_keys($values));

        $update = $values;
        foreach ($update as $column => $value) {
            if (is_array($value)) {
                $update[$column] = json_encode($value);
            }
        }
        if (Schema::hasColumn($primary->table, 'updated_at')) {
            $update['updated_at'] = CarbonImmutable::now();
        }
        $this->db()->table($primary->table)->where($primary->match, $id)->update($update);

        return ['before' => $before, 'after' => $values];
    }

    /**
     * @return array{changed: array<string, list<string>>, archived: list<string>, notes: list<string>}
     */
    public function anonymise(string $type, string $id, string $label): array
    {
        $changed = [];
        $archived = [];
        $notes = [];
        $now = CarbonImmutable::now();

        foreach ($this->subjects->sources($type) as $source) {
            $columns = array_filter($source->personal, fn (string $column) => Schema::hasColumn($source->table, $column), ARRAY_FILTER_USE_KEY);
            if ($columns === []) {
                continue;
            }

            $update = [];
            foreach ($columns as $column => $how) {
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
            if (Schema::hasColumn($source->table, 'updated_at')) {
                $update['updated_at'] = $now;
            }

            $rows = $this->db()->table($source->table)->where($source->match, $id)->update($update);
            if ($rows > 0) {
                $changed[$source->table] = array_keys($columns);
            }

            if (Schema::hasColumn($source->table, 'archived_at')) {
                $done = $this->db()->table($source->table)->where($source->match, $id)->whereNull('archived_at')->update(['archived_at' => $now]);
                if ($done > 0) {
                    $archived[] = $source->table;
                }
            }

            if ($source->after !== null) {
                array_push($notes, ...($source->after)($id));
            }
        }

        return ['changed' => $changed, 'archived' => $archived, 'notes' => $notes];
    }

    /** @return list<string> tables referencing the subject's row, kept as business records */
    public function retained(string $type, string $id): array
    {
        $primary = $this->subjects->primary($type);
        $sourceTables = array_map(fn (SubjectSource $s) => $s->table, $this->subjects->sources($type));
        $kept = [];

        foreach ($this->references($primary->table) as [$table, $column]) {
            if (! in_array($table, $sourceTables, true) && $this->db()->table($table)->where($column, $id)->exists()) {
                $kept[] = $table;
            }
        }

        return array_values(array_unique($kept));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, string $column, string $id, int $limit): array
    {
        $exported = array_values(array_filter($this->tables->columns($table), fn (string $c) => $this->tables->exported($table, $c)));
        if ($exported === []) {
            return [];
        }

        $q = fn (string $name) => '"'.str_replace('"', '""', $name).'"';
        $select = implode(', ', array_map(fn ($c) => 't.'.$q($c), $exported));
        $order = in_array('id', $exported, true) ? ' order by t."id"' : '';
        $sql = "select row_to_json(x)::text as line from (select {$select} from public.{$q($table)} t where t.{$q($column)} = ?{$order} limit {$limit}) x";

        return array_map(fn ($row) => json_decode($row->line, true), $this->db()->select($sql, [$id]));
    }

    /**
     * Tenant tables whose single-column foreign key (besides tenant_id)
     * points at $table: [table, column]. Skips actor columns and $table itself.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function references(string $table): array
    {
        $exportable = array_flip(array_map(fn ($t) => $t['name'], $this->tables->all()));
        $rows = $this->db()->select(<<<'SQL'
            select c.conrelid::regclass::text as tbl,
                   array_to_json(array(select a.attname from unnest(c.conkey) k(attnum)
                       join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum)) as cols
            from pg_constraint c
            where c.contype = 'f' and c.confrelid = ('public.' || quote_ident(?))::regclass
            order by 1
            SQL, [$table]);

        $out = [];
        foreach ($rows as $row) {
            $cols = array_values(array_diff(json_decode($row->cols, true), ['tenant_id']));
            $tbl = (string) $row->tbl;
            if (count($cols) !== 1 || $tbl === $table || ! isset($exportable[$tbl]) || str_ends_with($cols[0], '_by')) {
                continue;
            }
            $out[] = [$tbl, $cols[0]];
        }

        return $out;
    }
}
