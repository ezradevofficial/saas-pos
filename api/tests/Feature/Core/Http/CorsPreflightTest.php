<?php

namespace Tests\Feature\Core\Http;

use Tests\TestCase;

/** NFR-02: browsers may reuse a CORS preflight, so the web app does not pay a second round trip per call. */
class CorsPreflightTest extends TestCase
{
    public function test_a_preflight_answer_may_be_reused_for_two_hours(): void
    {
        $origin = collect(config('cors.allowed_origins'))->first() ?? 'http://localhost:3008';

        $this->call('OPTIONS', '/api/v1/me', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,x-company-id',
        ])->assertNoContent()->assertHeader('Access-Control-Max-Age', '7200');
    }
}
