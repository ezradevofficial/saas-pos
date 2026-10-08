<?php

/*
 * Fiscal transmission (concept note 7.2): every sale, refund and void is
 * queued on the server (`fiscal_submissions`) and sent to the tax
 * authority through a driver (App\Core\Fiscal\Contracts\FiscalDriver)
 * until it is accepted. Company credentials live in
 * `company_fiscal_settings` (encrypted). What the owner must provide:
 * docs/integrations.md.
 *
 * No tax rate is set here: rates and each tax code's fiscal band come
 * from the country pack and the company's tax codes (CP-01, CP-02).
 */

$development = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'queue' => env('FISCAL_QUEUE', 'fiscal'),

    // NFR-06: the fake driver accepts nothing real; local and testing only.
    'allow_fake' => (bool) env('FISCAL_ALLOW_FAKE', $development),

    // Seconds to wait before attempt 2, 3, ... after a failure the
    // authority may recover from (network, 5xx, busy); the last value
    // repeats for ever: a submission is never given up.
    'backoff' => [60, 300, 900, 1800, 3600],

    // A refund or void waits this many seconds between checks while the
    // sale it reverses is not accepted yet (not counted as an attempt).
    'wait_for_original_seconds' => 120,

    // Submissions left `sending` this many minutes (a worker died) are
    // retried.
    'stuck_minutes' => 10,

    // Submissions per tenant per queue run.
    'batch' => 50,

    // Per country: drivers that may be chosen, and when the company's
    // fiscal administrators are alerted that a submission is still not
    // accepted. `deadline_hours` is the transmission deadline the authority
    // allows for documents made offline; null until confirmed from the
    // authority's rules (never guessed), in which case only
    // `alert_after_hours` applies.
    'countries' => [
        'KE' => [
            'drivers' => ['kra_etims_oscu'],
            'alert_after_hours' => (int) env('FISCAL_KE_ALERT_AFTER_HOURS', 6),
            'deadline_hours' => env('FISCAL_KE_DEADLINE_HOURS'),
        ],
        'CD' => [
            'drivers' => ['dgi_emcf'],
            'alert_after_hours' => (int) env('FISCAL_CD_ALERT_AFTER_HOURS', 6),
            'deadline_hours' => env('FISCAL_CD_DEADLINE_HOURS'),
        ],
    ],

    // KRA eTIMS OSCU (online sales control unit). Base URL and paths are
    // configurable: KRA gives the sandbox and production hosts with the
    // integrator onboarding. Every call sends the taxpayer PIN (`tin`),
    // branch id (`bhfId`) and, after initialisation, the communication key
    // (`cmcKey`) as headers.
    'etims' => [
        'base_url' => env('ETIMS_BASE_URL', 'https://etims-api-sbx.kra.go.ke/etims-api'),
        'paths' => [
            'initialize' => env('ETIMS_PATH_INITIALIZE', '/selectInitOsdcInfo'),
            'save_sale' => env('ETIMS_PATH_SAVE_SALE', '/saveTrnsSalesOsdc'),
            'codes' => env('ETIMS_PATH_CODES', '/selectCodeList'),
            'item_classes' => env('ETIMS_PATH_ITEM_CLASSES', '/selectItemClsList'),
        ],
        'timeout' => 20,

        // The receipt QR code: this prefix followed by PIN + branch id +
        // receipt signature. Confirm the current verification URL with KRA
        // before go-live (left empty, no QR content is stored and the
        // receipt prints the signature only).
        'qr_prefix' => env('ETIMS_QR_PREFIX', 'https://etims-sbx.kra.go.ke/common/link/etims/receipt/indexEtimsReceiptData?Data='),

        // Result codes that mean "try again later" rather than "refused".
        // `000` is success; any code not listed here is a rejection that
        // needs a person (alerted, retried only on request).
        'retryable_codes' => array_values(array_filter(explode(',', (string) env('ETIMS_RETRYABLE_CODES', '')))),

        // KRA code tables (from selectCodeList; confirm against the current
        // eTIMS specification). Payment types by our method type.
        'payment_types' => [
            'cash' => '01',
            'credit' => '02',
            'card' => '05',
            'mobile_money' => '06',
            'other' => '07',
        ],
        'receipt_types' => ['sale' => 'S', 'refund' => 'R', 'void' => 'R'],
        'sales_type' => 'N',
        'sales_status' => '02',
        'refund_reason' => env('ETIMS_REFUND_REASON_CODE', '06'),

        // The tax bands eTIMS knows; a line's band is its tax code's
        // `fiscal_code` (set from the KE pack or by the business).
        'bands' => ['A', 'B', 'C', 'D', 'E'],
    ],
];
