<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Support\Places;

/**
 * GET procurement/{requisitions|rfqs|tenders|orders|receipts|invoices|landed-costs}/{id}
 * (and the bid and bidder routes): one document for a holder of its view
 * permission where it is (its branch, or its location; a GRN at its
 * location; a bid or bidder where its tender is, PRC-22). Anything else is
 * not found, so ids out of scope are never confirmed. Also the base of the
 * documents' action requests.
 */
class ShowDocumentRequest extends FormRequest
{
    public const PARAMETERS = [
        'procurement_requisition', 'procurement_rfq', 'procurement_tender', 'procurement_bidder', 'procurement_bid', 'procurement_decided_bid',
        'procurement_order', 'procurement_receipt', 'procurement_invoice', 'procurement_landed_cost',
    ];

    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function document(): Model
    {
        foreach (self::PARAMETERS as $parameter) {
            $document = $this->route($parameter);

            if ($document instanceof Model) {
                return $document;
            }
        }

        abort(404);
    }

    /** The tender a bid or bidder belongs to, else the document itself. */
    public function placed(): Model
    {
        $document = $this->document();

        return $document instanceof Bid || $document instanceof Bidder ? Tender::query()->findOrFail($document->tender_id) : $document;
    }

    /** The permission prefix of the document's resource (`procurement.po`, …). */
    public function resource(): string
    {
        return match (true) {
            $this->document() instanceof Requisition => 'procurement.requisition',
            $this->document() instanceof Rfq => 'procurement.rfq',
            $this->document() instanceof Tender, $this->document() instanceof Bid, $this->document() instanceof Bidder => 'procurement.tender',
            $this->document() instanceof Order => 'procurement.po',
            $this->document() instanceof Receipt => 'procurement.receipt',
            $this->document() instanceof Invoice => 'procurement.invoice',
            $this->document() instanceof LandedCost => 'procurement.landed_cost',
        };
    }

    /** Whether the user holds the resource's $action permission where the document is. */
    public function mayAt(string $action): bool
    {
        $place = $this->placed();
        $places = app(Places::class);
        $permission = "{$this->resource()}.{$action}";

        if ($place instanceof Receipt) {
            return $places->atLocation($this->user(), $permission, $place->location_id);
        }

        return $places->sees($this->user(), $permission, $place->branch_id, $place->getAttribute('location_id'));
    }

    protected function sees(): bool
    {
        return $this->mayAt('view');
    }
}
