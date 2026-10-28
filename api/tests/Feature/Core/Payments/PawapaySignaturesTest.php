<?php

namespace Tests\Feature\Core\Payments;

use App\Core\Payments\Pawapay\PawapayAmounts;
use App\Core\Payments\Pawapay\PawapayProvider;
use App\Core\Payments\Pawapay\PawapaySignatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NFR-06, SUB-04: pawaPay's amounts in exact minor units (CDF whole, USD two
 * places), the deterministic UUIDv4 ids, and RFC 9421 callback signatures
 * (ECDSA P-256), checked here against a key made for the test.
 */
class PawapaySignaturesTest extends TestCase
{
    public function test_amounts_are_exact_in_both_directions(): void
    {
        $this->assertSame(['135000', '48.50', '0.05', '5.00'], [PawapayAmounts::format(135000, 0), PawapayAmounts::format(4850, 2), PawapayAmounts::format(5, 2), PawapayAmounts::format(500, 2)]);
        $this->assertSame([135000, 135000, 4850, 4850, 4850, 5], [
            PawapayAmounts::parse('135000', 0), PawapayAmounts::parse('135000.00', 0), PawapayAmounts::parse('48.5', 2), PawapayAmounts::parse('48.50', 2), PawapayAmounts::parse('48.500', 2), PawapayAmounts::parse('0.05', 2),
        ]);
        // Cents a currency does not have, or something that is not an amount: never read as an amount.
        foreach (['135000.50' => 0, '48.505' => 2, 'abc' => 2, '' => 2, '-5' => 2, '1e3' => 2] as $text => $decimals) {
            $this->assertNull(PawapayAmounts::parse((string) $text, $decimals), $text);
        }
        $this->assertNull(PawapayAmounts::parse(48.5, 2));
    }

    public function test_ids_are_stable_uuid_v4s_and_phones_become_congolese_msisdns(): void
    {
        $id = '019a0000-0000-7000-8000-000000000001';

        $this->assertSame(PawapayProvider::idFor($id), PawapayProvider::idFor($id));
        $this->assertNotSame(PawapayProvider::idFor($id), PawapayProvider::idFor('019a0000-0000-7000-8000-000000000002'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', PawapayProvider::idFor($id));
        $this->assertSame(['243813456789', '243813456789', '243813456789', null, null], [
            PawapayProvider::msisdn('0813456789'), PawapayProvider::msisdn('+243 813 456 789'), PawapayProvider::msisdn('813456789'), PawapayProvider::msisdn('0712345678 99'), PawapayProvider::msisdn('12345'),
        ]);
    }

    public function test_a_valid_signature_passes_and_a_changed_body_or_unknown_key_or_wrong_key_fails(): void
    {
        config(['payments.pawapay.api_token' => 'test-only-token', 'payments.pawapay.base_url' => 'https://api.sandbox.pawapay.io']);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $public = openssl_pkey_get_details($key)['key'];
        Http::fake(['api.sandbox.pawapay.io/v2/public-key/http' => Http::response([['id' => 'HTTP_EC_P256_KEY:1', 'key' => $public]])]);
        $body = '{"depositId":"f4401bd2-1568-4140-bf2d-eb77d2b2b639","status":"COMPLETED"}';
        $signatures = new PawapaySignatures;

        $this->assertTrue($signatures->valid($this->signed($key, $body, 'HTTP_EC_P256_KEY:1')));
        Cache::forget('pawapay:public-keys');
        $this->assertFalse($signatures->valid($this->signed($key, $body, 'HTTP_EC_P256_KEY:1', tamper: true)));
        $this->assertFalse($signatures->valid($this->signed($key, $body, 'HTTP_EC_P256_KEY:99')));
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertFalse($signatures->valid($this->signed($other, $body, 'HTTP_EC_P256_KEY:1')));
        // No signature headers at all.
        $this->assertFalse($signatures->valid(Request::create('/api/v1/billing/pawapay/deposits', 'POST', content: $body)));
    }

    /** A callback signed the way pawaPay does (RFC 9421, ecdsa-p256-sha256, sha-256 content digest). */
    private function signed($key, string $body, string $keyId, bool $tamper = false): Request
    {
        $digest = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
        $date = 'Tue, 03 Nov 2026 08:00:00 GMT';
        $params = '("@method" "@authority" "@path" "signature-date" "content-digest" "content-type");alg="ecdsa-p256-sha256";keyid="'.$keyId.'";created='.time().';expires='.(time() + 300);
        $base = implode("\n", [
            '"@method": POST', '"@authority": localhost', '"@path": /api/v1/billing/pawapay/deposits', '"signature-date": '.$date,
            '"content-digest": '.$digest, '"content-type": application/json', '"@signature-params": '.$params,
        ]);
        openssl_sign($base, $der, $key, OPENSSL_ALGO_SHA256);
        $raw = $this->rawFromDer($der);

        return Request::create('http://localhost/api/v1/billing/pawapay/deposits', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE_DATE' => $date, 'HTTP_CONTENT_DIGEST' => $digest,
            'HTTP_SIGNATURE_INPUT' => 'sig-pp='.$params, 'HTTP_SIGNATURE' => 'sig-pp=:'.base64_encode($raw).':',
        ], content: $tamper ? $body.' ' : $body);
    }

    /** DER (r, s) to the raw r||s of RFC 9421. */
    private function rawFromDer(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? 1 : 0);
        $rLength = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLength);
        $sLength = ord($der[$offset + 2 + $rLength + 1]);
        $s = substr($der, $offset + 2 + $rLength + 2, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
