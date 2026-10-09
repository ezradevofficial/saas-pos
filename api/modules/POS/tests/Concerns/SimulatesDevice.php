<?php

namespace Modules\POS\Tests\Concerns;

use App\Core\Identity\Pin\OverrideVerifier as CoreVerifier;
use App\Core\Sync\DeviceSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Sync\CoreOverrides;
use Modules\POS\Sync\OverrideVerifier;

/**
 * NFR-04, ADR 004: a till as the app drives it, through the real HTTP
 * endpoints only: paired through the API (device token and secret),
 * bootstrap, paged pulls per entity from its cursors, an outbox pushed in
 * enqueue order one run of a kind per request (at most 50 records, never
 * the same record twice in a request, as pos/src/sync/engine.js does), and
 * offline manager overrides signed with the device secret (AUTH-08, core's
 * verifier, not the module tests' fake). Use with BuildsPos.
 */
trait SimulatesDevice
{
    /** Upload paths of the outbox kinds (pos/src/sync/pushKinds.js). */
    private const KINDS = [
        'pos.shifts' => ['pos/shifts', 'shifts'],
        'pos.sales' => ['pos/sales', 'sales'],
        'pos.cash_movements' => ['pos/cash-movements', 'movements'],
        'pos.voids' => ['pos/voids', 'voids'],
        'pos.refunds' => ['pos/refunds', 'refunds'],
    ];

    /** @var list<array{kind: string, payload: array}> */
    protected array $outbox = [];

    /** Core's override verifier (real offline signatures) instead of the module tests' fake. */
    protected function useCoreOverrides(): void
    {
        app()->instance(OverrideVerifier::class, app(CoreOverrides::class));
    }

    /**
     * A till at location A paired through the API by the owner.
     *
     * @return array{id: string, token: string, secret: string, kid: string}
     */
    protected function pairDevice(string $name = 'Till P'): array
    {
        $id = $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => $name], $this->headersFor())->assertCreated()->json('data.id');
        $code = $this->postJson("/api/v1/devices/{$id}/pairing-code", [], $this->headersFor())->assertOk()->json('code');
        $paired = $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => $name])->assertOk();

        return ['id' => $id, 'token' => $paired->json('token'), 'secret' => $paired->json('device_secret'), 'kid' => $paired->json('device_secret_kid')];
    }

    /** @return list<string> the entity keys bootstrap offers, in order */
    protected function bootstrapDevice(array $device): array
    {
        return array_column($this->getJson('/api/v1/sync/bootstrap', $this->tillHeaders($device['token']))->assertOk()->json('entities'), 'key');
    }

    /**
     * Pull $entities from $cursors until no entity has more, the way the
     * engine does (one request for all pending entities per page).
     *
     * @param  list<string>  $entities
     * @param  array<string, string>  $cursors
     * @return array{cursors: array<string, string>, pages: array<string, list<array>>, requests: int}
     */
    protected function pullAllEntities(array $device, array $entities, array $cursors = [], int $limit = 500): array
    {
        $pending = $entities;
        $pages = array_fill_keys($entities, []);
        $requests = 0;

        while ($pending !== [] && $requests < 2000) {
            $requests++;
            $query = http_build_query(array_filter([
                'entities' => $pending,
                'cursors' => array_intersect_key($cursors, array_flip($pending)),
                'limit' => $limit,
            ], fn ($v) => $v !== []));
            $body = $this->getJson('/api/v1/sync/pull?'.$query, $this->tillHeaders($device['token']))->assertOk()->json('entities');

            foreach ($pending as $key) {
                $page = $body[$key];
                $pages[$key][] = $page;
                $cursors[$key] = $page['cursor'];

                if (! $page['has_more']) {
                    $pending = array_values(array_diff($pending, [$key]));
                }
            }
        }

        return ['cursors' => $cursors, 'pages' => $pages, 'requests' => $requests];
    }

    /**
     * The rows a device holds for one entity after applying $pages (snapshot
     * replace drops what was not sent again; tombstones remove).
     *
     * @param  array<string, array>  $rows
     * @return array<string, array>
     */
    protected function applyPages(array $rows, array $pages): array
    {
        $replaced = null;

        foreach ($pages as $page) {
            if ($page['replace'] || $page['reset']) {
                $replaced ??= [];
            }

            foreach ($page['upserts'] as $row) {
                $rows[$row['id']] = $row;

                if ($replaced !== null) {
                    $replaced[$row['id']] = true;
                }
            }

            foreach ($page['tombstones'] as $id) {
                unset($rows[$id]);
            }
        }

        return $replaced === null ? $rows : array_intersect_key($rows, $replaced);
    }

    /** @return array{version: int, xid: int, seq: int} an incremental cursor, decoded */
    protected function decodeCursor(string $cursor): array
    {
        [$kind, $version, $xid, $seq] = explode('.', (string) base64_decode(strtr($cursor, '-_', '+/')));
        $this->assertSame('i1', $kind);

        return ['version' => (int) $version, 'xid' => (int) $xid, 'seq' => (int) $seq];
    }

    /**
     * Ids of $table (and of its entity's tombstones) stamped after $cursor,
     * in cursor order: what a pull from $cursor must deliver.
     *
     * @return array<string, array{0: int, 1: int}> id => [xid, seq]
     */
    protected function changedSince(string $table, string $entity, string $cursor): array
    {
        $from = $this->decodeCursor($cursor);

        return $this->inTenant(function () use ($table, $entity, $from) {
            $rows = DB::table($table)->whereRaw('(sync_xid, sync_seq) > (?, ?)', [$from['xid'], $from['seq']])->get(['id', 'sync_xid', 'sync_seq'])
                ->concat(DB::table('sync_tombstones')->where('entity', $entity)->whereRaw('(sync_xid, sync_seq) > (?, ?)', [$from['xid'], $from['seq']])->get(['record_id as id', 'sync_xid', 'sync_seq']))
                ->sortBy(fn ($r) => [(int) $r->sync_xid, (int) $r->sync_seq])
                ->values();

            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->id] = [(int) $row->sync_xid, (int) $row->sync_seq];
            }

            return $out;
        });
    }

    /** AUTH-08: an override the device signs offline with its current secret (message v2). */
    protected function offlineOverride(array $device, string $managerId, ?string $cashierId, string $permission, string $reference, string $at): array
    {
        $id = (string) Str::uuid7();
        $message = CoreVerifier::offlineMessage($device['id'], $device['kid'], $id, $managerId, $cashierId, $permission, $reference, $at);

        return [
            'id' => $id, 'kid' => $device['kid'], 'manager_user_id' => $managerId, 'cashier_user_id' => $cashierId,
            'permission' => $permission, 'reference' => $reference, 'authorised_at' => $at,
            'signature' => DeviceSecrets::encode(hash_hmac('sha256', $message, DeviceSecrets::decode($device['secret']), true)),
        ];
    }

    protected function enqueue(string $kind, array $payload): void
    {
        $this->outbox[] = ['kind' => $kind, 'payload' => $payload];
    }

    /**
     * Push $outbox (default: the device's) in order, one run of a kind per
     * request, at most 50 records and never one record twice per request.
     *
     * @return array{results: list<array>, requests: list<array{kind: string, status: int, count: int}>}
     */
    protected function pushOutbox(array $device, ?array $outbox = null): array
    {
        $outbox ??= $this->outbox;
        $results = [];
        $requests = [];
        $i = 0;

        while ($i < count($outbox)) {
            $kind = $outbox[$i]['kind'];
            $batch = [];
            $ids = [];

            while ($i < count($outbox) && $outbox[$i]['kind'] === $kind && count($batch) < 50 && ! isset($ids[$outbox[$i]['payload']['id']])) {
                $batch[] = $outbox[$i]['payload'];
                $ids[$outbox[$i]['payload']['id']] = true;
                $i++;
            }

            [$path, $key] = self::KINDS[$kind];
            $response = $this->postJson("/api/v1/{$path}", [$key => $batch], $this->tillHeaders($device['token']));
            $requests[] = ['kind' => $kind, 'status' => $response->status(), 'count' => count($batch)];
            $this->assertContains($response->status(), [200, 422], $response->getContent());
            $this->assertIsArray($response->json('results'), "{$kind}: ".$response->getContent());

            foreach ($response->json('results') as $result) {
                $results[] = ['kind' => $kind, ...$result];
            }
        }

        return ['results' => $results, 'requests' => $requests];
    }
}
