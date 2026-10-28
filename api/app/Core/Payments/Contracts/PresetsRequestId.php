<?php

namespace App\Core\Payments\Contracts;

/**
 * A provider that wants the request id it will use (pawaPay's depositId, a
 * UUIDv4) stored on the intent before the provider is called, so a late
 * callback or a status check always finds the intent, even when the call
 * itself timed out. The id is derived from the intent id, so it is the same
 * on every retry (idempotent at the provider).
 */
interface PresetsRequestId
{
    public function requestIdFor(string $intentId): string;
}
