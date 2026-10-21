<?php

namespace App\Core\Api\Http\Controllers;

use App\Core\Api\Docs\OpenApiDocument;
use Illuminate\Http\Response;

/**
 * API-04: the public API's OpenAPI document for the developer portal, as
 * committed and checked in CI (`api:docs --check`). It describes public
 * routes only and holds no tenant data, so it needs no credential.
 */
class OpenApiController
{
    public function __invoke(): Response
    {
        $path = base_path(OpenApiDocument::BASELINE);
        abort_unless(is_file($path), 404);

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
