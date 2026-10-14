<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 7B performance review: the store stock list reads each place's
// latest own-ledger movement (location, ledger, seq; and per item), and
// (tenant_id, x) indexes for the columns Stores filters, joins or checks a
// foreign key on, plus (tenant_id, company_id, status, created_at) for the
// document lists. Currency columns reference a small global table.
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => index column lists (after tenant_id) */
    private const INDEXES = [
        'stores_movements' => [['location_id', 'ledger', 'seq'], ['location_id', 'item_id', 'ledger', 'seq'], ['company_id'], ['cost_centre_id'], ['created_by'], ['department_id'], ['uom_id']],
        'stores_requisitions' => [['branch_id'], ['company_id'], ['cost_centre_id'], ['cancelled_by'], ['closed_by'], ['decided_by'], ['requested_by']],
        'stores_requisition_lines' => [['item_id'], ['uom_id']],
        'stores_issues' => [['branch_id'], ['company_id'], ['cost_centre_id'], ['department_id'], ['acknowledged_by'], ['issued_by']],
        'stores_issue_lines' => [['requisition_line_id'], ['item_id'], ['uom_id']],
        'stores_returns' => [['branch_id'], ['company_id'], ['cost_centre_id'], ['department_id'], ['returned_by']],
        'stores_return_lines' => [['issue_line_id'], ['item_id'], ['uom_id']],
    ];

    private const DOCUMENTS = ['stores_requisitions', 'stores_issues', 'stores_returns'];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (collect($columns)->every(fn (string $column) => Schema::hasColumn($table, $column))) {
                    DB::statement(sprintf('create index if not exists %s on %s (tenant_id, %s)', $this->name($table, $columns), $table, implode(', ', $columns)));
                }
            }
        }

        foreach (self::DOCUMENTS as $table) {
            if (Schema::hasColumn($table, 'status') && Schema::hasColumn($table, 'company_id')) {
                DB::statement(sprintf('create index if not exists %s on %s (tenant_id, company_id, status, created_at)', $this->name($table, ['company', 'status', 'created']), $table));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                DB::statement('drop index if exists '.$this->name($table, $columns));
            }
        }

        foreach (self::DOCUMENTS as $table) {
            DB::statement('drop index if exists '.$this->name($table, ['company', 'status', 'created']));
        }
    }

    /** @param list<string> $parts an index name of at most 63 characters */
    private function name(string $table, array $parts): string
    {
        $name = $table.'_t_'.implode('_', $parts).'_idx';

        return strlen($name) <= 63 ? $name : substr($name, 0, 54).'_'.substr(md5($name), 0, 8);
    }
};
