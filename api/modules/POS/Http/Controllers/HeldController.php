<?php

namespace Modules\POS\Http\Controllers;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\POS\Http\Requests\DecideHeldRequest;
use Modules\POS\Http\Requests\ListHeldRequest;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\HeldRecords;
use Modules\POS\Sync\Records;
use Modules\POS\Sync\Rejection;

/**
 * H2: money-out records from the till that wait for review because who
 * allowed them could not be proven. Someone holding the action's
 * permission at the record's location (and, for a refund, within their
 * refund limit) approves it, which applies it (sale voided, quantities
 * refunded, drawer counted, events raised), or rejects it. Both are
 * audited naming them.
 */
class HeldController
{
    /** kind => the permission that decides it */
    public const PERMISSIONS = ['void' => 'pos.sale.void', 'refund' => 'pos.sale.refund', 'cash_movement' => 'pos.cash.move'];

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly HeldRecords $records,
        private readonly Authority $authority,
    ) {}

    public function index(ListHeldRequest $request): JsonResponse
    {
        $user = $request->user();
        $kinds = $request->filled('kind') ? [$request->validated('kind')] : array_keys(self::PERMISSIONS);
        $flag = $request->validated('flag');
        $rows = [];

        foreach ($kinds as $kind) {
            $visible = $this->resolver->visibleIds($user, self::PERMISSIONS[$kind]);
            $query = match ($kind) {
                'void' => SaleVoid::query()->whereIn('sale_id', $visible->applyTo(Sale::query(), Scope::LOCATION)->select('id')),
                'refund' => $visible->applyTo(Refund::query(), Scope::LOCATION),
                'cash_movement' => $visible->applyTo(CashMovement::query(), Scope::LOCATION),
            };

            $query->where('status', Records::HELD)
                ->when($flag !== null, fn (Builder $q) => $q->whereRaw("flags @> jsonb_build_array(jsonb_build_object('code', ?::text))", [$flag]))
                ->orderByDesc('received_at')->limit(200)->get()
                ->each(function ($record) use ($kind, &$rows) {
                    $rows[] = $this->row($kind, $record);
                });
        }

        usort($rows, fn (array $a, array $b) => strcmp($b['received_at'], $a['received_at']));

        return response()->json(['data' => $this->named($rows)]);
    }

    public function approveVoid(DecideHeldRequest $request, SaleVoid $posVoid): JsonResponse
    {
        $this->authorizeAt($request->user(), 'pos.sale.void', Sale::query()->whereKey($posVoid->sale_id)->value('location_id'));

        return $this->decide($posVoid, 'void', fn (SaleVoid $void) => $this->records->applyVoid($void, $request->user()));
    }

    public function approveRefund(DecideHeldRequest $request, Refund $posRefund): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAt($user, 'pos.sale.refund', $posRefund->location_id);
        $limit = BigDecimal::ofUnscaledValue((string) $posRefund->base_total_minor, app(CurrencyDecimals::class)->for($posRefund->base_currency));

        if (! $this->authority->within($user, 'max_refund_amount', Scope::location($posRefund->location_id), $limit)) {
            throw new ApiException(422, 'limit_exceeded', __('pos.errors.limit_exceeded'));
        }

        return $this->decide($posRefund, 'refund', fn (Refund $refund) => $this->records->applyRefund($refund, $user));
    }

    public function approveCashMovement(DecideHeldRequest $request, CashMovement $posCashMovement): JsonResponse
    {
        $this->authorizeAt($request->user(), 'pos.cash.move', $posCashMovement->location_id);

        return $this->decide($posCashMovement, 'cash_movement', fn (CashMovement $movement) => $this->records->applyMovement($movement, $request->user()));
    }

    public function rejectVoid(DecideHeldRequest $request, SaleVoid $posVoid): JsonResponse
    {
        $this->authorizeAt($request->user(), 'pos.sale.void', Sale::query()->whereKey($posVoid->sale_id)->value('location_id'));

        return $this->decide($posVoid, 'void', fn (SaleVoid $void) => $this->records->reject($void, $request->user(), (string) $request->validated('reason')));
    }

    public function rejectRefund(DecideHeldRequest $request, Refund $posRefund): JsonResponse
    {
        $this->authorizeAt($request->user(), 'pos.sale.refund', $posRefund->location_id);

        return $this->decide($posRefund, 'refund', fn (Refund $refund) => $this->records->reject($refund, $request->user(), (string) $request->validated('reason')));
    }

    public function rejectCashMovement(DecideHeldRequest $request, CashMovement $posCashMovement): JsonResponse
    {
        $this->authorizeAt($request->user(), 'pos.cash.move', $posCashMovement->location_id);

        return $this->decide($posCashMovement, 'cash_movement', fn (CashMovement $movement) => $this->records->reject($movement, $request->user(), (string) $request->validated('reason')));
    }

    /** 404 when the user reaches the location with no POS permission at all, 403 without this one. */
    private function authorizeAt(User $user, string $permission, ?string $locationId): void
    {
        $scope = Scope::location((string) $locationId);
        $sees = $locationId !== null && collect([...array_values(self::PERMISSIONS), 'pos.sale.view'])->contains(fn (string $p) => $this->resolver->can($user, $p, $scope));

        abort_unless($sees, 404);
        abort_unless($this->resolver->can($user, $permission, $scope), 403);
    }

    private function decide(SaleVoid|Refund|CashMovement $record, string $kind, callable $apply): JsonResponse
    {
        $record = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($record, $apply) {
            $fresh = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Records::HELD) {
                throw new ApiException(422, 'not_held', __('pos.errors.not_held'));
            }

            try {
                $apply($fresh);
            } catch (Rejection $rejection) {
                throw new ApiException(422, $rejection->errorCode, $rejection->getMessage());
            }

            return $fresh->refresh();
        });

        return response()->json(['data' => $this->named([$this->row($kind, $record)])[0]]);
    }

    /**
     * The back office's words for ids: who did it, who approved and decided
     * it, the sale's receipt number, the till. One query per kind of name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function named(array $rows): array
    {
        $userIds = [];

        foreach ($rows as $row) {
            array_push($userIds, $row['by'], $row['approved_by'], $row['decided_by']);
        }

        $ids = fn (array $values) => array_values(array_unique(array_filter($values)));
        $users = User::query()->whereKey($ids($userIds))->pluck('name', 'id');
        $sales = Sale::query()->whereKey($ids(array_column($rows, 'sale_id')))->get(['id', 'receipt_number', 'location_id'])->keyBy('id');
        // A void's place is its sale's.
        $rows = array_map(fn (array $row) => [...$row, 'location_id' => $row['location_id'] ?? ($row['sale_id'] === null ? null : $sales[$row['sale_id']]->location_id ?? null)], $rows);
        $devices = Device::query()->whereKey($ids(array_column($rows, 'device_id')))->pluck('name', 'id');
        $locations = Location::query()->whereKey($ids(array_column($rows, 'location_id')))->pluck('name', 'id');
        $person = fn (?string $id) => $id === null ? null : ['id' => $id, 'name' => $users[$id] ?? null];

        return array_map(fn (array $row) => [
            ...$row,
            'by_user' => $person($row['by']),
            'approver' => $person($row['approved_by']),
            'decider' => $person($row['decided_by']),
            'sale_receipt_number' => $row['sale_id'] === null ? null : ($sales[$row['sale_id']]->receipt_number ?? null),
            'device' => $row['device_id'] === null ? null : ['id' => $row['device_id'], 'name' => $devices[$row['device_id']] ?? null],
            'location' => $row['location_id'] === null ? null : ['id' => $row['location_id'], 'name' => $locations[$row['location_id']] ?? null],
        ], $rows);
    }

    /** @return array<string, mixed> */
    private function row(string $kind, SaleVoid|Refund|CashMovement $record): array
    {
        return [
            'kind' => $kind,
            'id' => $record->id,
            'status' => $record->status,
            'sale_id' => $record->sale_id ?? null,
            'receipt_number' => $record instanceof Refund ? $record->receipt_number : null,
            'amount' => match (true) {
                $record instanceof Refund => ['amount_minor' => (string) $record->total_minor, 'currency' => $record->currency],
                $record instanceof CashMovement => ['amount_minor' => (string) $record->amount_minor, 'currency' => $record->currency],
                default => null,
            },
            'reason' => $record->reason,
            'device_id' => $record->device_id,
            'location_id' => $record instanceof SaleVoid ? null : $record->location_id,
            'occurred_at' => ($record->voided_at ?? $record->refunded_at ?? $record->occurred_at)?->toIso8601String(),
            'by' => $record->voided_by ?? $record->cashier_id ?? $record->user_id,
            'approved_by' => $record->approved_by,
            'override_verified' => $record->override_verified,
            'flags' => $record->flags,
            'decided_by' => $record->decided_by,
            'decided_at' => $record->decided_at?->toIso8601String(),
            'received_at' => $record->received_at->toIso8601String(),
        ];
    }
}
