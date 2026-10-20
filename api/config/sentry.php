<?php

use App\Core\Observability\SentryEvents;

/**
 * NFR-10: error tracking. Off unless SENTRY_LARAVEL_DSN is set (the SDK
 * sends nothing without a DSN). Every event, transaction and breadcrumb
 * goes through the shared scrubber (SentryEvents, rules in
 * config/scrub-rules.json), personal data is never attached by the SDK
 * (send_default_pii false, no SQL bindings), and the only identifying tag
 * is the tenant id (ObservabilityServiceProvider). The release is the git
 * sha the deploy writes to SENTRY_RELEASE. See docs/observability.md.
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE', is_file(base_path('REVISION')) ? trim((string) file_get_contents(base_path('REVISION'))) : null),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),

    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // Tracing is off until the owner chooses a rate (docs/observability.md).
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => null,

    'send_default_pii' => false,

    'enable_logs' => false,

    'before_send' => [SentryEvents::class, 'beforeSend'],

    'before_send_transaction' => [SentryEvents::class, 'beforeSendTransaction'],

    'before_breadcrumb' => [SentryEvents::class, 'beforeBreadcrumb'],

    'ignore_transactions' => ['/up', '/api/health/live', '/api/health/ready', '/metrics'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        // Bindings carry customer data: never.
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => true,
        'http_client_requests_origin' => false,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],
];
