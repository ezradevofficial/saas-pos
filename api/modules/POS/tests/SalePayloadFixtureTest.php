<?php

namespace Modules\POS\Tests;

use Illuminate\Support\Facades\Validator;
use Modules\POS\Http\Requests\Device\UploadSalesRequest;
use Tests\TestCase;

// POS-01, POS-03, AUTH-07, AUTH-08: tests/Fixtures/pos/sale-payload.json is exactly what the till
// builds (pos/src/pos/payloads.fixture.test.js); it must pass the upload request's rules here.
class SalePayloadFixtureTest extends TestCase
{
    public function test_the_tills_sale_payload_passes_the_upload_rules(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/pos/sale-payload.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['about']);

        $validator = Validator::make($payload, (new UploadSalesRequest)->rules());

        $this->assertSame([], $validator->errors()->toArray());

        // Every key the till sends is one the request knows (a stray key would change the stored hash).
        $rules = array_keys((new UploadSalesRequest)->rules());
        $known = array_map(fn (string $rule) => preg_replace('/\.\*\./', '.N.', str_replace('sales.*.', '', $rule)), $rules);

        foreach ($this->paths($payload['sales'][0]) as $path) {
            $this->assertContains(preg_replace('/\.\d+\./', '.N.', preg_replace('/\.\d+$/', '', $path)), [...$known, ...array_map(fn ($k) => preg_replace('/\.\*$/', '', $k), $known)], "unknown key {$path}");
        }
    }

    /** @return list<string> dotted paths of the leaves */
    private function paths(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $out = [...$out, ...(is_array($value) && $value !== [] ? $this->paths($value, $path) : [$path])];
        }

        return $out;
    }
}
