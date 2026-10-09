<?php

namespace Modules\POS\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\POS\Tests\Concerns\SimulatesDevice;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-03 (POS speed: adding an item and completing a cash sale under 1 s
// on a low-end Android device, online or offline). The till never waits
// for the server to complete a sale (it stores locally and uploads later,
// NFR-04), so the server's share is the upload and the catalogue pull.
// These checks measure both through the device endpoints and print the
// numbers; the bounds are generous so CI stays stable on shared runners.
class OfflineTimingTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase, SimulatesDevice;

    private const CATALOGUE = 5000;

    public function test_sale_upload_latency(): void
    {
        $this->setUpPos();
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $this->upload([$this->saleBody($shift, 1)])->assertOk(); // warm-up

        $single = [];
        foreach (range(2, 31) as $seq) {
            $body = $this->saleBody($shift, $seq);
            $t = hrtime(true);
            $this->upload([$body])->assertOk()->assertJsonPath('results.0.status', 'stored');
            $single[] = (hrtime(true) - $t) / 1e6;
        }

        $batch = array_map(fn (int $seq) => $this->saleBody($shift, $seq), range(32, 81));
        $t = hrtime(true);
        $this->upload($batch)->assertOk();
        $batchMs = (hrtime(true) - $t) / 1e6;

        $t = hrtime(true);
        $this->upload($batch)->assertOk(); // the same 50 again: idempotent answers
        $resendMs = (hrtime(true) - $t) / 1e6;

        sort($single);
        $stats = ['median' => $single[intdiv(count($single), 2)], 'p95' => $single[(int) floor(count($single) * 0.95) - 1], 'max' => max($single)];
        $this->report(sprintf('sale upload, 1 sale: median %.0f ms, p95 %.0f ms, max %.0f ms; batch of 50: %.0f ms (%.0f ms a sale); resend of 50: %.0f ms',
            $stats['median'], $stats['p95'], $stats['max'], $batchMs, $batchMs / 50, $resendMs));

        $this->assertLessThan(1000, $stats['median'], 'one sale uploads in under a second');
        $this->assertLessThan(3000, $stats['max']);
        $this->assertLessThan(20000, $batchMs, 'a batch of 50 uploads in under 20 s');
        $this->assertLessThan(10000, $resendMs);
    }

    public function test_pull_of_a_large_catalogue_in_pages(): void
    {
        $this->setUpPos();
        $this->seedCatalogue();
        $device = ['id' => $this->till->id, 'token' => $this->tillToken];

        $report = [];
        foreach (['items', 'item_prices'] as $entity) {
            $cursor = null;
            $pages = [];
            $rows = 0;

            do {
                $query = http_build_query(array_filter(['entities' => [$entity], 'cursors' => $cursor === null ? [] : [$entity => $cursor], 'limit' => 500], fn ($v) => $v !== []));
                $t = hrtime(true);
                $page = $this->getJson('/api/v1/sync/pull?'.$query, $this->tillHeaders($device['token']))->assertOk()->json("entities.{$entity}");
                $pages[] = (hrtime(true) - $t) / 1e6;
                $rows += count($page['upserts']);
                $cursor = $page['cursor'];
            } while ($page['has_more']);

            $this->assertSame(self::CATALOGUE + 1, $rows, "{$entity}: every row (the catalogue and the soap)");
            $total = array_sum($pages);
            $report[] = sprintf('%s: %d rows in %d pages of 500, %.0f ms total, slowest page %.0f ms', $entity, $rows, count($pages), $total, max($pages));

            $this->assertLessThan(5000, max($pages), "{$entity}: a page of 500 in under 5 s");
            $this->assertLessThan(30000, $total, "{$entity}: the whole catalogue in under 30 s");
        }

        $this->report('catalogue pull: '.implode('; ', $report));
    }

    /** 5,000 shared items with a KES price each, inserted in bulk (the database stamps them for sync). */
    private function seedCatalogue(): void
    {
        $this->inTenant(function () {
            $tenant = $this->owner->tenant_id;
            $now = now();

            foreach (array_chunk(range(1, self::CATALOGUE), 500) as $chunk) {
                $items = [];
                $prices = [];

                foreach ($chunk as $n) {
                    $id = (string) Str::uuid7();
                    $items[] = ['id' => $id, 'tenant_id' => $tenant, 'code' => sprintf('BULK%05d', $n), 'name' => "Bulk item {$n}", 'type' => 'stock',
                        'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id, 'custom' => '{}', 'created_at' => $now, 'updated_at' => $now];
                    $prices[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'price_list_id' => $this->retail->id, 'item_id' => $id, 'uom_id' => $this->each->id,
                        'amount_minor' => 900 * ($n % 100 + 1), 'currency' => 'KES', 'effective_from' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now];
                }

                DB::table('items')->insert($items);
                DB::table('item_prices')->insert($prices);
            }
        });
    }

    private function report(string $line): void
    {
        fwrite(STDERR, "\n[NFR-03] {$line}\n");
    }
}
