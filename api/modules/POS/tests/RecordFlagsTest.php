<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Sale;
use Modules\POS\Payments\RecordFlags;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-09, M3, AUD-01: flags added after the fact (payment settlements). A
// new flag re-opens a reviewed sale, audited; the add is atomic (the row
// lock is held in a transaction on the tenant connection).
class RecordFlagsTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->sale = $this->saleBody($this->openShift(), 1);
        $this->upload([$this->sale])->assertOk();
    }

    private function stored(): Sale
    {
        return $this->inTenant(fn () => Sale::query()->findOrFail($this->sale['id']));
    }

    private function flag(string $code, array $detail = []): void
    {
        $this->inTenant(fn () => RecordFlags::add(Sale::query()->findOrFail($this->sale['id']), $code, $detail));
    }

    public function test_a_new_flag_reopens_a_reviewed_sale_and_is_audited(): void
    {
        $this->flag('mpesa_mismatch', ['payment_id' => 'p-1']);
        $this->postJson("/api/v1/pos/sales/{$this->sale['id']}/review", ['note' => 'Checked'], $this->headersFor())->assertOk();
        $this->assertNotNull($this->stored()->reviewed_at);

        // The same flag again changes nothing: still reviewed.
        $this->flag('mpesa_mismatch', ['payment_id' => 'p-1']);
        $this->assertNotNull($this->stored()->reviewed_at);
        $this->assertSame(0, $this->inTenant(fn () => AuditEntry::query()->where('action', 'pos.sale.review_reopened')->count()));

        // A new flag needs a new review.
        $this->flag('mpesa_code_reused', ['payment_id' => 'p-1']);
        $sale = $this->stored();
        $this->assertSame([null, null], [$sale->reviewed_at, $sale->reviewed_by]);
        $this->assertSame(['mpesa_mismatch', 'mpesa_code_reused'], array_column($sale->flags, 'code'));

        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'pos.sale.review_reopened')->sole());
        $this->assertSame($this->sale['id'], $entry->auditable_id);
        $this->assertSame($this->owner->id, $entry->before['reviewed_by']);
        $this->assertSame('mpesa_code_reused', $entry->after['flag']);

        $this->getJson('/api/v1/pos/sales?reviewed=0', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $this->sale['id']);
    }

    public function test_the_row_lock_is_held_in_a_transaction(): void
    {
        $connection = DB::connection(TenantContext::CONNECTION);
        $outside = $connection->transactionLevel();
        $levels = [];
        DB::listen(function (QueryExecuted $query) use (&$levels) {
            if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'pos_sales')) {
                $levels[] = $query->connection->transactionLevel();
            }
        });

        $this->flag('mpesa_mismatch');

        // Without its own transaction the lock would be released as soon as the select ends.
        $this->assertSame([$outside + 1], $levels);
    }
}
