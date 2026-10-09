<?php

namespace App\Core\Fiscal\Etims;

use App\Core\Support\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;

/**
 * KRA eTIMS OSCU calls (base URL and paths from `fiscal.etims`). Every call
 * is a JSON POST carrying the taxpayer PIN (`tin`) and branch id (`bhfId`)
 * as headers, plus the communication key (`cmcKey`) once the device is
 * initialised. Answers are `{resultCd, resultMsg, resultDt, data}`;
 * `000` is success.
 *
 * Returns the decoded body, or null when nothing usable came back (no
 * connection, time-out, a server error, a body that is not eTIMS JSON):
 * the caller retries later.
 */
class EtimsClient
{
    /** @param array<string, mixed> $body */
    public function post(string $path, array $body, string $tin, string $branchId, ?string $cmcKey = null): ?array
    {
        $headers = array_filter(['tin' => $tin, 'bhfId' => $branchId, 'cmcKey' => $cmcKey], fn ($value) => $value !== null);

        try {
            $response = OutboundHttp::client((string) config('fiscal.etims.base_url'), (int) config('fiscal.etims.timeout', 20))
                ->withHeaders($headers)
                ->post((string) config("fiscal.etims.paths.{$path}"), $body);
        } catch (ConnectionException) {
            return null;
        }

        $json = $response->json();

        if ($response->serverError() || ! is_array($json) || ! array_key_exists('resultCd', $json)) {
            return null;
        }

        return $json;
    }
}
