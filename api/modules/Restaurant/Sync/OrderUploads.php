<?php

namespace Modules\Restaurant\Sync;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\POS\Sync\Authority;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\Rejection as PosRejection;
use Modules\Restaurant\Billing\Bill;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Resend;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Orders\OrderActions;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\Orders\Tickets;

/**
 * REST-17, REST-03 to REST-06, REST-10, REST-11: open orders uploaded by a
 * till (NFR-04, ADR 004). Each order has its own transaction; the answer is
 * the stored order with a result per line.
 *
 * - **Idempotent.** The same record again changes nothing.
 * - **Lines are unioned by id.** A line the server has is never dropped; one
 *   it lacks is added. A line can be edited until it is sent; after that a
 *   different item, quantity, price or modifiers is `line_changed`.
 * - **Header: last writer wins** by the record's `updated_at` (table, tab,
 *   customer, covers, note, waiter, bill requested). A stale header is
 *   ignored. A waiter change by someone without `restaurant.order.transfer`
 *   is kept and flagged `transfer_unauthorised`.
 * - **Sent marks and voids only move forward.** A sent line makes tickets
 *   (marked offline when it was sent long before it arrived). Removing an
 *   unsent line is free; removing a sent line needs `pos.sale.void` within
 *   the person's limit or a manager's override (REST-11); what cannot be
 *   proven is applied and flagged.
 * - **Closed orders.** Lines for an order already closed, merged or
 *   cancelled that the server does not have are rejected `order_closed`.
 * - **Shared tables** are flagged `table_shared`, never refused.
 */
class OrderUploads
{
    /** A line sent this long before it arrives is an offline ticket. */
    private const OFFLINE_SECONDS = 120;

    public function __construct(
        private readonly Authority $authority,
        private readonly OrderActions $actions,
        private readonly Tickets $tickets,
        private readonly OrderView $view,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public function upload(DevicePlace $place, array $orders): array
    {
        return array_map(fn (array $order) => $this->one($place, $order), $orders);
    }

    /** @return array<string, mixed> */
    private function one(DevicePlace $place, array $data): array
    {
        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data));
        } catch (PosRejection|Rejection $rejection) {
            return ['id' => $data['id'], 'status' => 'rejected', 'error' => $this->error($rejection)];
        } catch (UniqueConstraintViolationException) {
            // Two uploads of one new order at once: the second finds the first.
            return DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data));
        }
    }

    /** @return array<string, mixed> */
    private function store(DevicePlace $place, array $data): array
    {
        $order = Order::query()->whereKey($data['id'])->lockForUpdate()->first();
        $flags = [];

        if ($order !== null && $order->location_id !== $place->location->id) {
            throw new Rejection('id_conflict', 'id');
        }

        $created = $order === null;
        $changed = $created;

        if ($created) {
            $order = $this->create($place, $data);
        } else {
            $changed = $this->header($place, $order, $data);
        }

        $results = [];
        $newlySent = collect();
        $voided = collect();
        $open = $order->status === 'open';
        $locked = $order->status !== 'open';

        foreach ($data['lines'] ?? [] as $lineData) {
            $results[] = $this->line($place, $order, $lineData, $locked, $newlySent, $voided, $changed);
        }

        if ($open && ($data['status'] ?? null) === 'cancelled' && $order->status === 'open') {
            try {
                $this->actions->cancel($order, __('restaurant.cancelled_by_till'), null);
                $changed = true;
            } catch (ApiException) {
                // A sent or paid order stays open: the till's cancel lost to what happened meanwhile.
                $flags[] = 'cancel_refused';
            }
        }

        $offlineAfter = CarbonImmutable::now()->subSeconds(self::OFFLINE_SECONDS);
        $offline = $newlySent->contains(fn (OrderLine $line) => $line->sent_at->lessThan($offlineAfter));
        $this->tickets->make($order, $newlySent->values(), 'order', $offline, $this->actor($data));

        foreach ($data['resends'] ?? [] as $resend) {
            $this->resend($order, $resend);
        }

        $target = $data['merged_into_id'] ?? null;

        if ($target !== null && $order->status === 'open') {
            $into = Order::query()->whereKey($target)->lockForUpdate()->first();

            if ($into !== null && $into->status === 'open' && $into->location_id === $order->location_id) {
                $this->actions->merge($order, $into, null);
                $changed = true;
            } else {
                $flags[] = 'merge_pending';
            }
        }

        foreach ($flags as $flag) {
            $order->flags = $this->actions->withFlag($order->flags, $flag);
        }

        if ($changed || $newlySent->isNotEmpty() || $voided->isNotEmpty() || $order->isDirty()) {
            $order->rev = $created ? 1 : $order->rev + 1;
            $order->save();
        }

        $fresh = $order->fresh();

        return [
            'id' => $order->id,
            'status' => 'stored',
            'rev' => (int) $fresh->rev,
            'flags' => $fresh->flags ?? [],
            'lines' => $results,
            'order' => $this->view->record($fresh),
        ];
    }

    private function create(DevicePlace $place, array $data): Order
    {
        $kind = $data['kind'];

        if ($kind === 'table') {
            $table = Table::query()->whereKey($data['table_id'] ?? null)->first();

            if ($table === null || $table->location_id !== $place->location->id) {
                throw new Rejection('table_unknown', 'table_id');
            }
        }

        $waiter = User::query()->whereKey($data['waiter_id'])->first() ?? throw new Rejection('waiter_unknown', 'waiter_id');
        $flags = [];

        if ($kind === 'table' && Order::query()->where('table_id', $data['table_id'])->where('status', 'open')->exists()) {
            $flags = $this->actions->withFlag($flags, 'table_shared');
        }

        if ($data['customer_id'] ?? null) {
            $this->customer($data['customer_id']);
        }

        $order = new Order([
            'id' => $data['id'],
            ...$place->columns(),
            'kind' => $kind,
            'table_id' => $kind === 'table' ? $data['table_id'] : null,
            'tab_name' => $data['tab_name'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'waiter_id' => $waiter->id,
            'updated_by' => $data['updated_by'] ?? $waiter->id,
            'covers' => $data['covers'] ?? 1,
            'currency' => $data['currency'],
            'status' => 'open',
            'note' => $data['note'] ?? null,
            'flags' => $flags,
            'rev' => 1,
            'opened_at' => CarbonImmutable::parse($data['opened_at'])->utc(),
            'header_at' => CarbonImmutable::parse($data['updated_at'])->utc(),
            'bill_requested_at' => isset($data['bill_requested_at']) ? CarbonImmutable::parse($data['bill_requested_at'])->utc() : null,
        ]);
        $order->save();

        return $order;
    }

    /** Last writer wins; true when something changed. */
    private function header(DevicePlace $place, Order $order, array $data): bool
    {
        $at = CarbonImmutable::parse($data['updated_at'])->utc();

        if ($order->status !== 'open' || ! $at->greaterThan($order->header_at)) {
            return false;
        }

        if (($data['kind'] ?? $order->kind) === 'table' && ($data['table_id'] ?? null) !== null && $data['table_id'] !== $order->table_id) {
            $table = Table::query()->whereKey($data['table_id'])->first();

            if ($table === null || $table->location_id !== $place->location->id) {
                throw new Rejection('table_unknown', 'table_id');
            }

            $order->table_id = $table->id;
        }

        if (array_key_exists('customer_id', $data) && $data['customer_id'] !== $order->customer_id) {
            if ($data['customer_id'] !== null) {
                $this->customer($data['customer_id']);
            }
            $order->customer_id = $data['customer_id'];
        }

        if (isset($data['waiter_id']) && $data['waiter_id'] !== $order->waiter_id) {
            $to = User::query()->whereKey($data['waiter_id'])->first() ?? throw new Rejection('waiter_unknown', 'waiter_id');
            $by = isset($data['updated_by']) ? User::query()->whereKey($data['updated_by'])->first() : null;
            $allowed = $by !== null && $this->authority->can($by, 'restaurant.order.transfer', $place->scope());
            $this->actions->transfer($order, $to->id, $by, unauthorised: ! $allowed);
        }

        foreach (['tab_name', 'note'] as $key) {
            if (array_key_exists($key, $data)) {
                $order->{$key} = $data[$key];
            }
        }

        if (isset($data['covers'])) {
            $order->covers = (int) $data['covers'];
        }

        if (isset($data['bill_requested_at'])) {
            $order->bill_requested_at = CarbonImmutable::parse($data['bill_requested_at'])->utc();
        }

        if (isset($data['updated_by'])) {
            $order->updated_by = User::query()->whereKey($data['updated_by'])->exists() ? $data['updated_by'] : $order->updated_by;
        }

        $order->header_at = $at;

        return true;
    }

    /**
     * @param  Collection<int, OrderLine>  $newlySent
     * @param  Collection<int, OrderLine>  $voided
     * @return array<string, mixed>
     */
    private function line(DevicePlace $place, Order $order, array $data, bool $orderLocked, Collection $newlySent, Collection $voided, bool &$changed): array
    {
        $stored = OrderLine::query()->whereKey($data['id'])->lockForUpdate()->first();

        try {
            if ($stored !== null && $stored->order_id !== $order->id) {
                $this->adopt($place, $order, $stored);
                $changed = true;
            }

            if ($stored === null) {
                if ($orderLocked) {
                    throw new Rejection('order_closed', 'lines');
                }

                $stored = $this->createLine($place, $order, $data);
                $changed = true;
            } elseif ($stored->sent_at === null && ! $orderLocked && $stored->voided_at === null) {
                $changed = $this->editLine($stored, $data) || $changed;
            } elseif ($this->differs($stored, $data) && $stored->voided_at === null) {
                throw new Rejection('line_changed', 'lines');
            }

            if (! empty($data['sent_at']) && $stored->sent_at === null && $stored->voided_at === null && ! $orderLocked) {
                $stored->sent_at = CarbonImmutable::parse($data['sent_at'])->utc();
                $stored->sent_by = $this->knownUser($data['sent_by'] ?? null);
                $stored->round = $data['round'] ?? $stored->round;
                $stored->save();
                $newlySent->push($stored);
                $changed = true;
            }

            if (! empty($data['removed']) && $stored->voided_at === null && ! $orderLocked) {
                $this->remove($place, $order, $stored, $data['removed']);
                $voided->push($stored);
                $changed = true;
            }

            return ['id' => $stored->id, 'status' => 'stored'];
        } catch (PosRejection|Rejection $rejection) {
            return ['id' => $data['id'], 'status' => 'rejected', 'error' => $this->error($rejection)];
        }
    }

    /** A line sent under another open order of the place (a merge done at the till) moves here. */
    private function adopt(DevicePlace $place, Order $order, OrderLine $line): void
    {
        $from = Order::query()->whereKey($line->order_id)->lockForUpdate()->first();

        if ($from === null || $from->location_id !== $place->location->id || $from->status !== 'open' || $order->status !== 'open') {
            throw new Rejection('line_conflict', 'lines');
        }

        $line->order_id = $order->id;
        $line->save();
    }

    private function createLine(DevicePlace $place, Order $order, array $data): OrderLine
    {
        $item = Item::query()->whereKey($data['item_id'])->first() ?? throw new Rejection('item_unknown', 'item_id');

        return OrderLine::create([
            'id' => $data['id'],
            'order_id' => $order->id,
            'location_id' => $place->location->id,
            'item_id' => $item->id,
            'name' => $data['name'],
            'qty' => self::dec($data['qty'], 6),
            'unit_price_minor' => $data['unit_price_minor'],
            'tax_code_id' => $this->knownTaxCode($data['tax_code_id'] ?? null),
            'tax_rate' => isset($data['tax_rate']) ? self::dec($data['tax_rate'], 4) : null,
            'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
            'course' => $data['course'] ?? 1,
            'seat' => $data['seat'] ?? null,
            'note' => $data['note'] ?? null,
            'modifiers' => $data['modifiers'] ?? [],
            'round' => $data['round'] ?? null,
            'created_at_device' => CarbonImmutable::parse($data['created_at'])->utc(),
            'flags' => [],
        ]);
    }

    /** An unsent line follows the till. @return bool whether something changed */
    private function editLine(OrderLine $line, array $data): bool
    {
        $before = $line->getAttributes();
        $line->fill(array_filter([
            'name' => $data['name'] ?? null,
            'qty' => isset($data['qty']) ? self::dec($data['qty'], 6) : null,
            'unit_price_minor' => $data['unit_price_minor'] ?? null,
            'tax_rate' => isset($data['tax_rate']) ? self::dec($data['tax_rate'], 4) : null,
            'course' => $data['course'] ?? null,
            'note' => $data['note'] ?? null,
            'modifiers' => $data['modifiers'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('seat', $data)) {
            $line->seat = $data['seat'];
        }

        if (array_key_exists('tax_inclusive', $data)) {
            $line->tax_inclusive = (bool) $data['tax_inclusive'];
        }

        $dirty = $line->isDirty();
        $line->save();

        return $dirty && $before !== $line->getAttributes();
    }

    private static function dec(string|int|float $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp);
    }

    /** Whether a sent line's content differs from what the till now says. */
    private function differs(OrderLine $line, array $data): bool
    {
        return $data['item_id'] !== $line->item_id
            || ! BigDecimal::of((string) $data['qty'])->isEqualTo((string) $line->qty)
            || (string) $data['unit_price_minor'] !== (string) $line->unit_price_minor
            || $this->mods($data['modifiers'] ?? []) !== $this->mods($line->modifiers ?? []);
    }

    /** @return list<string> */
    private function mods(array $modifiers): array
    {
        $out = array_map(fn ($m) => ($m['id'] ?? '').':'.($m['price_minor'] ?? '0'), $modifiers);
        sort($out);

        return $out;
    }

    /** REST-11: removes a line; a sent one needs the right or a manager. */
    private function remove(DevicePlace $place, Order $order, OrderLine $line, array $removed): void
    {
        $actor = $this->authority->user($removed['by'], 'removed.by');
        $at = CarbonImmutable::parse($removed['at'])->utc();
        $reason = (string) ($removed['reason'] ?? '');
        $flags = [];
        $approver = null;

        if ($line->sent_at !== null) {
            if ($reason === '') {
                throw new Rejection('reason_required', 'removed.reason');
            }

            $payable = Bill::line([
                'qty' => (string) $line->qty, 'unit_price_minor' => (string) $line->unit_price_minor, 'modifiers' => $line->modifiers ?? [],
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate, 'tax_inclusive' => (bool) $line->tax_inclusive,
            ])['payable'];

            try {
                $approval = $this->authority->approve(
                    $actor, $removed['override'] ?? null, $removed['actor_proof'] ?? null, 'pos.sale.void', $place->scope(),
                    fn (User $user) => $this->authority->within($user, 'max_refund_amount', $place->scope(), $this->actions->major((string) $payable, $order->currency)),
                    $place->device, $line->id, 'removed.override',
                );
            } catch (PosRejection $e) {
                throw $e->errorCode === 'limit_exceeded' ? new Rejection('void_limit_exceeded', 'removed') : $e;
            }

            $approver = $approval->approverId();

            if ($approval->held()) {
                $flags[] = $approval->flag();
            }

            foreach ($approval->reviewFlags() as $code) {
                $flags[] = $code;
            }
        }

        $this->actions->voidLine($order, $line, $reason, $actor, $approver, $flags, $at, offline: true);
    }

    /** REST-10: a re-send of sent lines, once by its id. */
    private function resend(Order $order, array $data): void
    {
        if (Resend::query()->whereKey($data['id'])->exists()) {
            return;
        }

        $lines = OrderLine::query()->where('order_id', $order->id)->whereIn('id', $data['line_ids'])->whereNotNull('sent_at')->whereNull('voided_at')->get();
        $made = $this->tickets->make($order, $lines, 'resend', false, $this->knownUser($data['by'] ?? null));
        Resend::create(['id' => $data['id'], 'order_id' => $order->id, 'ticket_id' => $made[0]->id ?? null, 'resent_at' => CarbonImmutable::parse($data['at'])->utc()]);
        $this->auditor->record('restaurant.order.resend', $order, null, ['resend_id' => $data['id'], 'lines' => count($lines)], array_filter(['user_id' => $this->knownUser($data['by'] ?? null)]));
    }

    private function customer(string $id): void
    {
        if (! Party::query()->whereKey($id)->exists()) {
            throw new Rejection('customer_unknown', 'customer_id');
        }
    }

    private function knownUser(?string $id): ?string
    {
        return $id !== null && User::query()->whereKey($id)->exists() ? $id : null;
    }

    private function knownTaxCode(?string $id): ?string
    {
        return $id !== null && TaxCode::query()->whereKey($id)->exists() ? $id : null;
    }

    private function actor(array $data): ?string
    {
        return $this->knownUser($data['updated_by'] ?? null);
    }

    /** @return array{code: string, message: string, field: ?string, retryable: bool} */
    private function error(PosRejection|Rejection $rejection): array
    {
        return $rejection->toArray();
    }
}
