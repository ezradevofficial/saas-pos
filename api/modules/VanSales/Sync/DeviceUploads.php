<?php

namespace Modules\VanSales\Sync;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\NumberRanges;
use Modules\POS\Sync\Rejection as PosRejection;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Collection;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Reconciliation\Counts;
use Modules\VanSales\Reconciliation\Reopening;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Settings;

/**
 * VAN-27, VAN-06, VAN-09, VAN-14, VAN-17, VAN-18: what a van's device sends
 * (runs, visits, collections, end-of-day counts), NFR-04 and ADR 004.
 *
 * - **Idempotent.** The same record again answers the same; a collection or
 *   count whose id exists with other content is `payload_mismatch`. Each
 *   record has its own transaction: one refused record never blocks the rest.
 * - **Checked against the device.** The van is the one whose location the
 *   device sits at; every id is read under the tenant; the person named must
 *   hold the permission at the van's location.
 * - **Visits: last writer wins** by `updated_at`; a visit with a sale stays
 *   `sold`.
 * - **Money in is never refused for lateness:** a collection for a run that
 *   was already counted is stored and flagged `received_after_count`; one
 *   above the balance is flagged `exceeds_balance`.
 */
class DeviceUploads
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly NumberRanges $ranges,
        private readonly Counts $counts,
        private readonly Ledger $ledger,
        private readonly Auditor $auditor,
        private readonly Authority $authority,
        private readonly Reopening $reopening,
    ) {}

    /**
     * @param  array{runs?: list<array>, visits?: list<array>, collections?: list<array>, counts?: list<array>}  $records
     * @return array{runs: list<array>, visits: list<array>, collections: list<array>, counts: list<array>}
     */
    public function upload(DevicePlace $place, array $records): array
    {
        $results = [];

        foreach (['runs' => 'run', 'visits' => 'visit', 'collections' => 'collection', 'counts' => 'count'] as $key => $kind) {
            $results[$key] = array_map(fn (array $record) => $this->one($place, $kind, $record), $records[$key] ?? []);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function one(DevicePlace $place, string $kind, array $record): array
    {
        try {
            return Errors::transaction(fn () => match ($kind) {
                'run' => $this->run($place, $record),
                'visit' => $this->visit($place, $record),
                'collection' => $this->collection($place, $record),
                'count' => $this->count($place, $record),
            });
        } catch (ApiException $e) {
            return ['id' => $record['id'], 'status' => 'rejected', 'code' => $e->errorCode, 'message' => $e->getMessage(), 'field' => array_key_first($e->errors)];
        } catch (UniqueConstraintViolationException) {
            return ['id' => $record['id'], 'status' => 'rejected', 'code' => 'id_conflict', 'message' => __('van_sales.errors.id_conflict'), 'field' => 'id'];
        }
    }

    private function van(DevicePlace $place): Van
    {
        $van = Van::query()->where('location_id', $place->location->id)->whereNull('archived_at')->first();

        if ($van === null || ($van->device_id !== null && $van->device_id !== $place->device->id)) {
            throw Errors::make(422, 'van_unknown');
        }

        return $van;
    }

    /**
     * The person a record names (VAN-27, AUTH-07). The device names them, so
     * the server checks: they exist, hold the permission at the van's place,
     * and either carry a verified sign-in attestation (`actor_proof`) or, where
     * `$proofRequired` is false, are the rep of the day (`$repId`). Money
     * (collections) and stock (load confirmations) always need the proof.
     *
     * @param  array<string, mixed>  $data
     */
    public function actor(DevicePlace $place, array $data, string $permission, bool $proofRequired, ?string $repId = null): User
    {
        $user = User::query()->find($data['user_id'] ?? '');

        if ($user === null || ! $user->isActive()) {
            throw Errors::make(422, 'rep_unknown', field: 'user_id');
        }

        if (! $this->resolver->can($user, $permission, $place->scope())) {
            throw Errors::make(403, 'not_permitted', field: 'user_id');
        }

        $proven = $this->authority->proven($place->device, $user, is_array($data['actor_proof'] ?? null) ? $data['actor_proof'] : null) !== null;

        if (! $proven && ($proofRequired || $user->id !== $repId)) {
            throw Errors::make(422, 'actor_unverified', field: 'user_id');
        }

        return $user;
    }

    /** A customer of the run's company (or a shared one) with the customer role (VAN-17). */
    private function customer(string $id, string $companyId, string $field = 'customer_id'): Party
    {
        $party = Party::query()->find($id);

        if ($party === null || ! in_array('customer', (array) $party->roles, true) || ($party->company_id !== null && $party->company_id !== $companyId)) {
            throw Errors::make(422, 'customer_unknown', field: $field);
        }

        return $party;
    }

    /** @return array<string, mixed> */
    private function run(DevicePlace $place, array $data): array
    {
        $van = $this->van($place);
        $existing = Run::query()->find($data['id']);

        if ($existing !== null) {
            if ($existing->van_id !== $van->id) {
                throw Errors::make(422, 'id_conflict', field: 'id');
            }

            return ['id' => $existing->id, 'status' => 'stored', 'run_status' => $existing->status];
        }

        $user = $this->actor($place, $data, 'van_sales.run.start', false, $van->rep_id);
        $routeId = $data['route_id'] ?? null;

        if ($routeId !== null && ! Route::query()->whereNull('archived_at')->where('company_id', $van->company_id)->whereKey($routeId)->exists()) {
            throw Errors::make(422, 'route_unknown', field: 'route_id');
        }

        if (Run::query()->where('van_id', $van->id)->where('status', 'open')->exists()) {
            throw Errors::make(409, 'run_open', field: 'id');
        }

        $startedAt = CarbonImmutable::parse($data['started_at'])->utc();
        $zone = $place->branch->timezone ?: ($place->company->timezone ?: 'UTC');
        $run = Run::create([
            'id' => $data['id'],
            'company_id' => $van->company_id,
            'branch_id' => $van->branch_id,
            'van_id' => $van->id,
            'location_id' => $van->location_id,
            'route_id' => $routeId,
            'device_id' => $place->device->id,
            'rep_id' => $user->id,
            'run_date' => $startedAt->setTimezone($zone)->toDateString(),
            'status' => 'open',
            'started_at' => $startedAt,
        ]);

        return ['id' => $run->id, 'status' => 'stored', 'run_status' => $run->status];
    }

    private function runOf(DevicePlace $place, string $runId): Run
    {
        $run = Run::query()->find($runId);

        if ($run === null || $run->location_id !== $place->location->id) {
            throw Errors::make(422, 'run_unknown', field: 'run_id');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function visit(DevicePlace $place, array $data): array
    {
        $run = $this->runOf($place, $data['run_id']);
        $user = $this->actor($place, $data, 'van_sales.visit.record', false, $run->rep_id);
        $existing = Visit::query()->find($data['id']);

        if ($existing !== null && $existing->run_id !== $run->id) {
            throw Errors::make(422, 'id_conflict', field: 'id');
        }

        if ($run->status === 'closed') {
            throw Errors::make(409, 'run_closed', field: 'run_id');
        }

        $this->customer($data['customer_id'], $run->company_id);
        $stopId = $data['stop_id'] ?? null;

        if ($stopId !== null && ! Stop::query()->where('company_id', $run->company_id)->whereKey($stopId)->exists()) {
            throw Errors::make(422, 'stop_unknown', field: 'stop_id');
        }

        if ($data['status'] === 'no_sale' && empty($data['no_sale_reason'])) {
            throw Errors::make(422, 'visit_invalid', field: 'no_sale_reason');
        }

        $at = CarbonImmutable::parse($data['updated_at'])->utc();

        if ($existing !== null && $existing->device_updated_at->greaterThanOrEqualTo($at)) {
            return ['id' => $existing->id, 'status' => 'stored', 'stale' => true];
        }

        $status = $data['status'];

        // A visit with a sale stays sold, whatever order the records arrive in.
        if ($status !== 'sold' && VanSale::query()->where('visit_id', $data['id'])->where('status', 'completed')->exists()) {
            $status = 'sold';
        }

        $values = [
            'company_id' => $run->company_id, 'run_id' => $run->id, 'stop_id' => $stopId, 'customer_id' => $data['customer_id'], 'user_id' => $user->id,
            'status' => $status, 'no_sale_reason' => $status === 'no_sale' ? $data['no_sale_reason'] : null, 'note' => $data['note'] ?? null,
            'arrived_at' => isset($data['arrived_at']) ? CarbonImmutable::parse($data['arrived_at'])->utc() : null,
            'left_at' => isset($data['left_at']) ? CarbonImmutable::parse($data['left_at'])->utc() : null,
            'device_updated_at' => $at,
        ];

        // VAN-24: one rounded point at arrival, only when the company allows it.
        if (is_array($data['check_in'] ?? null) && Settings::for($run->company_id)['gps_checkin'] === 'optional') {
            $values += [
                'checkin_lat' => round((float) $data['check_in']['lat'], 4), 'checkin_lng' => round((float) $data['check_in']['lng'], 4),
                'checkin_accuracy_m' => isset($data['check_in']['accuracy_m']) ? (int) round((float) $data['check_in']['accuracy_m']) : null,
                'checkin_at' => CarbonImmutable::parse($data['check_in']['at'] ?? $data['arrived_at'] ?? $at)->utc(),
            ];
        }

        $visit = $existing ?? new Visit(['id' => $data['id']]);
        $visit->fill($values)->save();

        return ['id' => $visit->id, 'status' => 'stored'];
    }

    /** @return array<string, mixed> */
    private function collection(DevicePlace $place, array $data): array
    {
        $digest = self::digest($data);
        $existing = Collection::query()->find($data['id']);

        if ($existing !== null) {
            return $existing->device_id === $place->device->id && $existing->content_digest === $digest
                ? ['id' => $existing->id, 'status' => 'stored', 'receipt_number' => $existing->receipt_number]
                : throw Errors::make(422, 'payload_mismatch', field: 'id');
        }

        $run = $this->runOf($place, $data['run_id']);
        $user = $this->actor($place, $data, 'van_sales.collection.record', true);
        $method = PaymentMethod::query()->find($data['payment_method_id']);

        if ($method === null || $method->company_id !== $run->company_id || ! in_array($method->type, ['cash', 'mobile_money'], true)) {
            throw Errors::make(422, 'collection_invalid', field: 'payment_method_id');
        }

        if ($method->currency !== null && $method->currency !== $data['currency']) {
            throw Errors::make(422, 'collection_invalid', field: 'currency');
        }

        $customer = $this->customer($data['customer_id'], $run->company_id);
        $amount = (int) $data['amount_minor'];

        if ($amount <= 0) {
            throw Errors::make(422, 'collection_invalid', field: 'amount_minor');
        }

        $visitId = ! empty($data['visit_id']) ? Visit::query()->where('run_id', $run->id)->whereKey($data['visit_id'])->value('id') : null;
        $collectedAt = CarbonImmutable::parse($data['collected_at'])->utc();

        try {
            $range = $this->ranges->claim($place, 'pos.receipt', (int) $data['receipt_seq'], (string) $data['receipt_number'], $collectedAt, 'receipt', $data['number_range_id'] ?? null);
        } catch (PosRejection $rejection) {
            throw Errors::make(422, $rejection->errorCode === 'receipt_number_mismatch' ? 'receipt_number_mismatch' : 'receipt_range_unknown', field: (string) $rejection->field);
        }

        $flags = [];

        if ($run->status !== 'open') {
            $flags[] = 'received_after_count';
        }

        if ($amount > max(0, $this->ledger->balance($customer->id, $data['currency']))) {
            $flags[] = 'exceeds_balance';
        }

        $collection = Collection::create([
            'id' => $data['id'],
            'company_id' => $run->company_id, 'branch_id' => $run->branch_id, 'location_id' => $run->location_id, 'device_id' => $place->device->id,
            'run_id' => $run->id, 'visit_id' => $visitId, 'customer_id' => $customer->id, 'payment_method_id' => $method->id,
            'method_type' => $method->type, 'currency' => $data['currency'], 'amount_minor' => $amount,
            'provider_reference' => $data['provider_reference'] ?? null,
            'receipt_number' => $data['receipt_number'], 'receipt_seq' => (int) $data['receipt_seq'], 'number_range_id' => $range->id,
            'status' => 'recorded', 'flags' => $flags, 'user_id' => $user->id,
            'content_digest' => $digest, 'collected_at' => $collectedAt, 'received_at' => now(),
        ]);

        $this->ledger->add($run->company_id, $customer->id, $run->id, 'collection', $collection->id, $data['currency'], -$amount, $collectedAt);
        Tender::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $collection->tenant_id, 'run_id' => $run->id, 'source_type' => 'collection', 'source_id' => $collection->id,
            'kind' => $method->type, 'currency' => $data['currency'], 'amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Money that reaches a run after its count changes what the count has to say (VAN-20).
        $this->reopening->late($run, 'late_collection');

        return ['id' => $collection->id, 'status' => 'stored', 'receipt_number' => $collection->receipt_number, 'flags' => $flags];
    }

    /** @return array<string, mixed> */
    private function count(DevicePlace $place, array $data): array
    {
        $run = $this->runOf($place, $data['run_id']);
        $user = $this->actor($place, $data, 'van_sales.reconciliation.submit', false, $run->rep_id);
        $count = $this->counts->submit($run, [
            'id' => $data['id'], 'submitted_at' => $data['submitted_at'], 'note' => $data['note'] ?? null,
            'lines' => $data['lines'] ?? [], 'tenders' => $data['tenders'] ?? [],
        ], $user);

        return ['id' => $count->id, 'status' => 'stored', 'reconciliation' => $this->summary($count)];
    }

    /** @return array<string, mixed> */
    public function summary(Count $count): array
    {
        return [
            'id' => $count->id,
            'status' => $count->status,
            'stock_variance_lines' => (int) $count->stock_variance_lines,
            'tenders' => $count->tenders()->orderBy('currency')->orderBy('kind')->get()->map(fn ($t) => [
                'kind' => $t->kind, 'currency' => $t->currency, 'expected_minor' => (string) $t->expected_minor,
                'counted_minor' => (string) $t->counted_minor, 'variance_minor' => (string) $t->variance_minor,
            ])->all(),
        ];
    }

    /** A stable fingerprint of a record: the same content in any key order. */
    public static function digest(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $value);
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }
}
