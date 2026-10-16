<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-01 (ADR 020): `Idempotency-Key` on a key's POST. The first request
 * with a key claims it (insert, no conflict) and its response is stored
 * for `api.idempotency.hours`; the same key again with the same method,
 * path and body replays that response (`Idempotent-Replayed: true`),
 * with another request answers 422 `idempotency_mismatch`, and while the
 * first still runs 409 `idempotency_in_progress`. A 5xx, or a body larger
 * than `api.idempotency.max_body_bytes`, is not stored, so the client may
 * retry. Rows are the tenant's (RLS) and per key; expired ones are
 * pruned by a lottery.
 */
class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const PATTERN = '/^[A-Za-z0-9\-_.:]{1,255}$/D';

    /**
     * Run $next once per key: a stored response is replayed instead.
     *
     * @param  callable(): Response  $next
     */
    public function handle(Request $request, ApiKey $key, string $idempotencyKey, callable $next): Response
    {
        if (preg_match(self::PATTERN, $idempotencyKey) !== 1) {
            $message = __('api.errors.idempotency_key_invalid');

            throw new ApiException(422, 'idempotency_key_invalid', $message, [self::HEADER => [$message]]);
        }

        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $now = CarbonImmutable::now();

        Lottery::odds(1, 100)->winner(fn () => $this->db()->table('api_idempotency_keys')->where('expires_at', '<', $now)->delete())->choose();

        $claimed = $this->claim($key, $idempotencyKey, $hash, $now);

        if (! $claimed) {
            $existing = $this->db()->table('api_idempotency_keys')->where('api_key_id', $key->id)->where('key', $idempotencyKey)->first();

            if ($existing !== null && CarbonImmutable::parse($existing->expires_at)->lte($now)) {
                $this->db()->table('api_idempotency_keys')->where('id', $existing->id)->delete();
                $claimed = $this->claim($key, $idempotencyKey, $hash, $now);
                $existing = null;
            }

            if (! $claimed) {
                return $this->replay($existing, $hash);
            }
        }

        try {
            $response = $next();
        } catch (\Throwable $e) {
            $this->forget($key, $idempotencyKey);

            throw $e;
        }

        $this->store($key, $idempotencyKey, $response);

        return $response;
    }

    private function claim(ApiKey $key, string $idempotencyKey, string $hash, CarbonImmutable $now): bool
    {
        return $this->db()->table('api_idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $key->tenant_id,
            'api_key_id' => $key->id,
            'key' => $idempotencyKey,
            'request_hash' => $hash,
            'expires_at' => $now->addHours(max(1, (int) config('api.idempotency.hours', 24))),
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    private function replay(?object $existing, string $hash): Response
    {
        if ($existing !== null && ! hash_equals((string) $existing->request_hash, $hash)) {
            $message = __('api.errors.idempotency_mismatch');

            throw new ApiException(422, 'idempotency_mismatch', $message, [self::HEADER => [$message]]);
        }

        // Gone between the conflict and the read (pruned, or a 5xx forgotten): ask the client to send it again.
        if ($existing === null || $existing->status === null) {
            throw new ApiException(409, 'idempotency_in_progress', __('api.errors.idempotency_in_progress'));
        }

        return new Response((string) $existing->body, (int) $existing->status, array_filter([
            'Content-Type' => $existing->content_type,
            self::REPLAYED_HEADER => 'true',
        ]));
    }

    private function store(ApiKey $key, string $idempotencyKey, Response $response): void
    {
        $body = $response->getContent();

        if ($response->getStatusCode() >= 500 || ! is_string($body) || strlen($body) > (int) config('api.idempotency.max_body_bytes', 1048576)) {
            $this->forget($key, $idempotencyKey);

            return;
        }

        $this->db()->table('api_idempotency_keys')->where('api_key_id', $key->id)->where('key', $idempotencyKey)->update([
            'status' => $response->getStatusCode(),
            'body' => $body,
            'content_type' => substr((string) $response->headers->get('Content-Type'), 0, 100) ?: null,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function forget(ApiKey $key, string $idempotencyKey): void
    {
        $this->db()->table('api_idempotency_keys')->where('api_key_id', $key->id)->where('key', $idempotencyKey)->whereNull('status')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}
