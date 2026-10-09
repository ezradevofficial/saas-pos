<?php

namespace Modules\POS\Tests;

use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\POS\Tests\Concerns\SimulatesDevice;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04 (Task 7): records what the device endpoints answer, for the POS
// app's sync engine tests (pos/src/test/fixtures/device-api.json mocks
// fetch with these shapes). Skipped unless POS_RECORD_FIXTURES=1; run it
// after changing a device endpoint's answer:
//   POS_RECORD_FIXTURES=1 php artisan test modules/POS/tests/DeviceApiShapesTest.php
class DeviceApiShapesTest extends TestCase
{
    use BuildsExchangeRates, BuildsPos, RefreshTenantDatabase, SimulatesDevice;

    public function test_record_the_device_api_shapes(): void
    {
        if (! env('POS_RECORD_FIXTURES')) {
            $this->markTestSkipped('Set POS_RECORD_FIXTURES=1 to record pos/src/test/fixtures/device-api.json.');
        }

        $this->setUpPos();
        $this->rate('USD', 'KES', '130', at: '-1 day');
        $headers = $this->tillHeaders();
        $out = [];
        $answer = fn ($response) => ['status' => $response->status(), 'body' => $response->json()];

        $out['bootstrap'] = $answer($this->getJson('/api/v1/sync/bootstrap', $headers)->assertOk());
        $first = $this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => ['items', 'item_prices', 'exchange_rates'], 'limit' => 1]), $headers)->assertOk();
        $out['pull_first'] = $answer($first);
        $this->inTenant(fn () => $this->soap->archive());
        $out['pull_tombstone'] = $answer($this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => ['items'], 'cursors' => ['items' => $first->json('entities.items.cursor')]]), $headers)->assertOk());
        $out['pull_invalid_cursor'] = $answer($this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => ['items'], 'cursors' => ['items' => 'nope']]), $headers));
        $this->inTenant(fn () => $this->soap->restore());

        $out['number_ranges'] = $answer($this->postJson('/api/v1/pos/number-ranges', ['document_type' => 'pos.receipt'], $headers)->assertOk());
        $shift = $this->shiftBody();
        $out['shifts_request'] = ['shifts' => [$shift]];
        $out['shifts_stored'] = $answer($this->postJson('/api/v1/pos/shifts', ['shifts' => [$shift]], $headers)->assertOk());

        $sale = $this->saleBody($shift['id'], 1, ['offline' => true]);
        $out['sales_request'] = ['sales' => [$sale]];
        $out['sales_stored'] = $answer($this->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $headers)->assertOk());
        $out['sales_resent'] = $answer($this->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $headers)->assertOk());
        $out['sales_retryable'] = $answer($this->postJson('/api/v1/pos/sales', ['sales' => [$this->saleBody($this->id(), 2)]], $headers)->assertUnprocessable());
        $out['sales_payload_mismatch'] = $answer($this->postJson('/api/v1/pos/sales', ['sales' => [[...$sale, 'offline' => false]]], $headers)->assertUnprocessable());
        $out['sales_validation_failed'] = $answer($this->postJson('/api/v1/pos/sales', ['sales' => [[...$sale, 'id' => 'not-a-uuid']]], $headers)->assertUnprocessable());

        $path = base_path('../pos/src/test/fixtures/device-api.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            '_source' => 'Recorded by api/modules/POS/tests/DeviceApiShapesTest.php (POS_RECORD_FIXTURES=1). Do not edit by hand.',
            ...$out,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->assertFileExists($path);
    }
}
