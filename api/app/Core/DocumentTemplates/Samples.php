<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-01: sample data in the DataSource shape, for previews and for the
 * document types whose modules don't exist yet. Names are sample texts
 * in the request's language; amounts are made up and obviously samples
 * (no real tax rate is implied: the sample tax line is named "VAT" with
 * no rate, CP-02).
 */
final class Samples
{
    public static function currency(?string $country): string
    {
        return $country === 'CD' ? 'CDF' : 'KES';
    }

    /** The parts every type shares: company, branch, location, customer, fiscal. */
    public static function base(string $type, ?string $country, ?string $authority): array
    {
        $currency = self::currency($country);
        $scale = $currency === 'CDF' ? 1 : 100;

        return [
            'currencies' => [$currency => $currency === 'CDF' ? 0 : 2],
            'company' => [
                'name' => __('templates.sample.company'),
                'legal_name' => __('templates.sample.company'),
                'tax_id' => 'P000000000S',
                'address' => '1 Sample Road',
                'phone' => '+000 000 000',
                'email' => 'hello@example.com',
                'country' => $country,
                'logo' => null,
                'custom' => [],
            ],
            'branch' => ['name' => __('templates.sample.branch'), 'code' => 'BR1', 'address' => '1 Sample Road'],
            'location' => ['name' => __('templates.sample.location'), 'code' => 'L01'],
            'customer' => [
                'name' => __('templates.sample.customer'),
                'tax_id' => 'P000000001S',
                'phone' => '+000 000 001',
                'email' => 'customer@example.com',
                'address' => '2 Sample Avenue',
                'tags' => [],
                'custom' => [],
            ],
            'fiscal' => $authority === null ? null : [
                'authority' => $authority,
                'status' => 'accepted',
                'invoice_number' => '1001',
                'receipt_number' => 'SAMPLE-1001',
                'receipt_signature' => 'SAMPLESIGNATURE0001',
                'internal_data' => 'SAMPLEINTERNALDATA',
                'control_unit_id' => 'SAMPLE-CU-01',
                'authority_time' => '2026-10-09 14:32',
                'qr' => 'SAMPLE-'.strtoupper(str_replace('.', '-', $type)).'-1001',
            ],
            'scale' => $scale,
        ];
    }

    public static function money(int $major, string $currency, int $scale): array
    {
        return ['minor' => (string) ($major * $scale), 'currency' => $currency];
    }
}
