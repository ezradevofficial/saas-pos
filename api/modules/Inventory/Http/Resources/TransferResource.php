<?php

namespace Modules\Inventory\Http\Resources;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Transfer;
use Modules\Inventory\Models\TransferLine;
use Modules\Inventory\Stock\Qty;

/**
 * INV-08, INV-19: a transfer, its places, people and times, its value
 * (valuation holders only), and in the detail its lines and what the
 * reader may do next (dispatch, receive, cancel).
 *
 * @mixin Transfer
 */
class TransferResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Transfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'status' => $transfer->status,
            'company' => Refs::place($transfer->company),
            'branch' => Refs::place($transfer->branch),
            'from_location' => Refs::place($transfer->fromLocation),
            'to_location' => Refs::place($transfer->toLocation),
            'note' => $transfer->note,
            'requested_by' => Refs::user($transfer->requester),
            'value' => Values::visible($request, $transfer->from_location_id) ? Values::money($transfer->value_minor, $transfer->currency) : null,
            'lines_count' => $transfer->lines_count ?? $transfer->lines()->count(),
            'decided_at' => Refs::at($transfer->decided_at),
            'dispatched_at' => Refs::at($transfer->dispatched_at),
            'received_at' => Refs::at($transfer->received_at),
            'cancelled_at' => Refs::at($transfer->cancelled_at),
            'cancel_reason' => $transfer->cancel_reason,
            'loss_reason' => $transfer->loss_reason,
            'created_at' => Refs::at($transfer->created_at),
            ...($this->detail ? [
                'lines' => $transfer->lines->map(fn (TransferLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'item' => Refs::item($line->item),
                    'uom' => Refs::uom($line->uom),
                    'qty' => Qty::show($line->qty),
                    'qty_base' => Qty::show($line->qty_base),
                    'batch' => Refs::batch($line->batch),
                    'serials' => $line->serials ?? [],
                    'qty_received' => Qty::show($line->qty_received),
                    'qty_lost' => Qty::show($line->qty_lost),
                ])->all(),
                'can' => $this->can($request, $transfer),
            ] : []),
        ];
    }

    /** @return array{dispatch: bool, receive: bool, cancel: bool} */
    private function can(Request $request, Transfer $transfer): array
    {
        $resolver = app(ScopeResolver::class);
        $user = $request->user();
        $from = Scope::location($transfer->from_location_id);

        return [
            'dispatch' => $transfer->status === Transfer::APPROVED && $resolver->can($user, 'inventory.transfer.dispatch', $from),
            'receive' => $transfer->status === Transfer::IN_TRANSIT && $resolver->can($user, 'inventory.transfer.receive', Scope::location($transfer->to_location_id)),
            'cancel' => in_array($transfer->status, [Transfer::PENDING, Transfer::APPROVED], true) && $resolver->can($user, 'inventory.transfer.cancel', $from),
        ];
    }
}
