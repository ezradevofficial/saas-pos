<?php

namespace Modules\Hotel\Housekeeping;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * HOT-29, NFR-04: a housekeeper's phone queues status changes while it is
 * offline and replays them on reconnect. Each queued change carries an
 * `op_id` (a UUID made on the phone); the first request with an `op_id`
 * runs and its response is kept for 14 days, so the same `op_id` again (a
 * lost response, a retry after a crash) answers the same response and
 * changes nothing twice (`Idempotent-Replayed: true`). Keys are per tenant
 * and user, so one person's id never answers for another's. Refusals are
 * not kept: replaying one runs again and is refused the same way.
 */
class HousekeepingOps
{
    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const DAYS = 14;

    /** @param  Closure(): JsonResource  $run */
    public function once(Request $request, string $kind, string $subject, Closure $run): JsonResponse
    {
        $op = $request->input('op_id');

        if (! is_string($op) || $op === '') {
            return $run()->response($request);
        }

        $key = 'hotel:housekeeping-op:'.app(TenantContext::class)->require().':'.$request->user()->id.':'.$kind.':'.$subject.':'.$op;

        // A second copy of the same op arriving while the first runs waits for it, then replays it.
        return Cache::lock($key.':lock', 15)->block(10, function () use ($request, $key, $run) {
            $stored = Cache::get($key);

            if (is_array($stored)) {
                return response()->json($stored['body'], $stored['status'])->header(self::REPLAYED_HEADER, 'true');
            }

            $response = $run()->response($request);
            Cache::put($key, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addDays(self::DAYS));

            return $response;
        });
    }
}
