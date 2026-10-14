<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 7B performance review: (tenant_id, x) indexes for the columns
// Procurement filters, joins or checks a foreign key on, and
// (tenant_id, company_id, status, created_at) for the document lists.
// Currency columns reference a small global table and need none.
return new class extends Migration
{
    /** @var array<string, list<string>> table => columns indexed as (tenant_id, column) */
    private const COLUMNS = [
        'procurement_bid_files' => ['bid_id', 'tender_id'],
        'procurement_bid_scores' => ['criterion_id', 'evaluator_id'],
        'procurement_bids' => ['bidder_id'],
        'procurement_invoice_lines' => ['tax_code_id'],
        'procurement_invoices' => ['branch_id', 'party_id', 'approved_by', 'cancelled_by', 'created_by'],
        'procurement_landed_cost_allocations' => ['receipt_id', 'receipt_line_id'],
        'procurement_landed_cost_charges' => ['party_id'],
        'procurement_landed_cost_receipts' => ['receipt_id'],
        'procurement_landed_costs' => ['branch_id', 'cancelled_by', 'created_by', 'posted_by'],
        'procurement_order_lines' => ['cost_centre_id', 'item_id', 'project_id', 'requisition_line_id', 'tax_code_id', 'uom_id'],
        'procurement_orders' => ['location_id', 'quote_id', 'bid_id', 'requisition_id', 'status', 'cancelled_by', 'decided_by', 'requested_by'],
        'procurement_portal_sessions' => ['tender_id'],
        'procurement_quote_lines' => ['rfq_line_id'],
        'procurement_quotes' => ['party_id', 'created_by'],
        'procurement_receipt_lines' => ['item_id', 'uom_id'],
        'procurement_receipts' => ['branch_id', 'party_id', 'cancelled_by', 'created_by', 'posted_by'],
        'procurement_requisition_lines' => ['cost_centre_id', 'item_id', 'project_id', 'uom_id'],
        'procurement_requisitions' => ['department_id', 'location_id', 'cancelled_by', 'decided_by', 'requested_by'],
        'procurement_rfq_lines' => ['item_id', 'requisition_line_id', 'uom_id'],
        'procurement_rfq_suppliers' => ['party_id'],
        'procurement_rfqs' => ['requisition_id', 'cancelled_by', 'created_by'],
        'procurement_tender_bidders' => ['party_id', 'invited_by'],
        'procurement_tender_lines' => ['item_id', 'uom_id'],
        'procurement_tender_members' => ['user_id'],
        'procurement_tender_openings' => ['user_id'],
        'procurement_tenders' => ['requisition_id', 'awarded_by', 'cancelled_by', 'created_by'],
    ];

    /** The document tables listed by company and status, newest first. */
    private const DOCUMENTS = [
        'procurement_requisitions', 'procurement_rfqs', 'procurement_tenders', 'procurement_orders',
        'procurement_receipts', 'procurement_invoices', 'procurement_landed_costs',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement(sprintf('create index if not exists %s on %s (tenant_id, %s)', $this->name($table, [$column]), $table, $column));
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
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement('drop index if exists '.$this->name($table, [$column]));
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
