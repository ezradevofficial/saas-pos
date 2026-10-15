<?php

namespace Modules\CRM\Http\Resources;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\QuoteLine;
use Modules\CRM\Support\CrmAccess;

/**
 * CRM-17: a quote, its customer, currency, totals (and base totals once
 * submitted, CUR-04), its status and what it became; in the detail its
 * lines with discounts, tax rates and "Rate needed" marks; `can` says
 * what the reader may do (RBAC-09).
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    public const RELATIONS = ['company', 'branch', 'owner', 'team', 'party', 'opportunity', 'priceList'];

    public const DETAIL = [...self::RELATIONS, 'lines.item', 'lines.uom', 'lines.taxCode'];

    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Quote $quote */
        $quote = $this->resource;
        $user = $request->user();
        $access = app(CrmAccess::class);
        $may = fn (string $permission) => $user !== null && $access->canAt($user, "crm.quote.{$permission}", $quote->branch_id);
        $zone = $quote->company?->timezone ?: (Company::query()->whereKey($quote->company_id)->value('timezone') ?: 'UTC');
        $status = $quote->status;

        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'company' => Refs::named($quote->company),
            'branch' => Refs::named($quote->branch),
            'owner' => Refs::user($quote->owner),
            'team' => Refs::named($quote->team),
            'party' => Refs::named($quote->party),
            'opportunity' => Refs::named($quote->opportunity),
            'status' => $status,
            'currency' => $quote->currency,
            'price_list' => Refs::named($quote->priceList),
            'tax_inclusive' => (bool) $quote->tax_inclusive,
            'quote_date' => Refs::date($quote->quote_date),
            'valid_until' => Refs::date($quote->valid_until),
            'expired' => $quote->isExpired($zone),
            'terms' => $quote->terms,
            'notes' => $quote->notes,
            'totals' => [
                'subtotal_minor' => (string) $quote->subtotal_minor,
                'discount_minor' => (string) $quote->discount_minor,
                'tax_minor' => (string) $quote->tax_minor,
                'total_minor' => (string) $quote->total_minor,
            ],
            'base_totals' => $quote->base_total_minor === null ? null : [
                'currency' => $quote->base_currency,
                'subtotal_minor' => (string) $quote->base_subtotal_minor,
                'discount_minor' => (string) $quote->base_discount_minor,
                'tax_minor' => (string) $quote->base_tax_minor,
                'total_minor' => (string) $quote->base_total_minor,
            ],
            'fx' => $quote->fx?->jsonSerialize(),
            'max_discount_percent' => Refs::decimal($quote->max_discount_percent),
            'needs_approval' => (bool) $quote->needs_approval,
            'discount_over_limit' => (bool) $quote->discount_over_limit,
            'approval_reason' => $quote->approval_reason,
            'submitted_at' => Refs::at($quote->submitted_at),
            'decided_at' => Refs::at($quote->decided_at),
            'sent_at' => Refs::at($quote->sent_at),
            'accepted_at' => Refs::at($quote->accepted_at),
            'declined_at' => Refs::at($quote->declined_at),
            'decline_reason' => $quote->decline_reason,
            'converted_to' => $quote->converted_to,
            'converted_ref' => $quote->converted_ref,
            'converted_at' => Refs::at($quote->converted_at),
            'cancelled_at' => Refs::at($quote->cancelled_at),
            'cancel_reason' => $quote->cancel_reason,
            'created_at' => Refs::at($quote->created_at),
            'can' => [
                'edit' => in_array($status, [Quote::DRAFT, Quote::REJECTED], true) && $may('create'),
                'submit' => $status === Quote::DRAFT && $may('create'),
                'send' => in_array($status, Quote::RELEASED, true) && $may('send'),
                'accept' => in_array($status, [Quote::APPROVED, Quote::SENT], true) && $may('create'),
                'convert' => $status === Quote::ACCEPTED && $may('convert'),
                'cancel' => in_array($status, [Quote::DRAFT, Quote::PENDING, Quote::APPROVED, Quote::REJECTED, Quote::SENT], true) && $may('cancel'),
            ],
            ...($this->detail ? ['lines' => $quote->lines->map(fn (QuoteLine $line) => self::line($line))->values()->all()] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function line(QuoteLine $line): array
    {
        return [
            'id' => $line->id,
            'line_no' => $line->line_no,
            'item' => Refs::item($line->item),
            'description' => $line->description,
            'uom' => Refs::uom($line->uom),
            'qty' => Refs::decimal($line->qty),
            'list_price_minor' => $line->list_price_minor === null ? null : (string) $line->list_price_minor,
            'unit_price_minor' => (string) $line->unit_price_minor,
            'discount_percent' => Refs::decimal($line->discount_percent),
            'gross_minor' => (string) $line->gross_minor,
            'discount_minor' => (string) $line->discount_minor,
            'net_minor' => (string) $line->net_minor,
            'tax_minor' => (string) $line->tax_minor,
            'total_minor' => (string) $line->total_minor,
            'tax_code' => Refs::taxCode($line->taxCode),
            'tax_rate' => Refs::decimal($line->tax_rate),
            'rate_needed' => self::rateNeeded($line->taxCode, $line->tax_rate),
        ];
    }

    /** MD-03: a code that is not exempt and has no rate on the line ("Rate needed"). */
    public static function rateNeeded(?TaxCode $code, mixed $rate): bool
    {
        return $code !== null && $rate === null && ! $code->isExempt() && $code->kind !== 'withholding';
    }
}
