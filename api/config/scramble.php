<?php

use Dedoc\Scramble\OpenApiVersion;

/*
 * API-04 (docs/adr/020-public-api.md): Scramble generates the public API's
 * OpenAPI document. Only routes marked `public-api:<resource>` are
 * documented (ApiServiceProvider), and its own UI and JSON routes are not
 * served: `php artisan api:docs --write` writes the committed document,
 * which the developer portal reads from GET /api/v1/developers/openapi.json.
 */
return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'resources/openapi/v1.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'array',
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        'version' => '1.0.0',
        'description' => '',
    ],

    'ui' => [
        'title' => null,
    ],

    'dev_tools' => [
        'enabled' => false,
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [],

    'extensions' => [],

    'security_strategy' => null,
];
