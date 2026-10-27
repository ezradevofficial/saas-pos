<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Lists\DispenseList;
use Modules\Pharmacy\Http\Requests\ListDispensesRequest;
use Modules\Pharmacy\Http\Requests\ReviewDispenseRequest;
use Modules\Pharmacy\Http\Resources\DispenseResource;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Support\PharmacyError;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-16, PHA-17, PHA-24, PHA-26: dispensed lines, the prescription log and the review of flagged ones. */
class DispenseController
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(ListDispensesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Dispense::query()->with(DispenseList::RELATIONS);
        $request->applyFilters($query, 'location_id', 'dispensed_at');

        if ($request->filled('review') && $request->validated('review') !== 'all') {
            $query->where('review_status', $request->validated('review'));
        }

        if ($request->filled('class')) {
            $query->where('class', $request->validated('class'));
        }

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->validated('item_id'));
        }

        if ($request->boolean('with_prescription')) {
            $query->whereNotNull('prescription_id');
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            // PHA-28: patient and prescriber names are searched only where the reader may see prescriptions.
            $readable = app(ScopeResolver::class)->visibleIds($request->user(), 'pharmacy.prescription.view');
            $query->where(fn (Builder $q) => $q->where('receipt_number', 'ilike', $like)->orWhere(fn (Builder $r) => $r
                ->when(! $readable->all, fn (Builder $w) => $w->whereIn('pharmacy_dispenses.location_id', $readable->idsFor(Scope::LOCATION)))
                ->whereHas('prescription', fn (Builder $p) => $p->where('patient_name', 'ilike', $like)->orWhere('prescriber_name', 'ilike', $like))));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return DispenseResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** PHA-24: the prescription log: the dispenses made against a prescription. */
    public function log(ListDispensesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $request->merge(['with_prescription' => true]);

        return $this->index($request, $export);
    }

    /** PHA-26: a reviewer's note closes a flagged dispense (audited). */
    public function review(ReviewDispenseRequest $request, Dispense $pharmacyDispense): DispenseResource
    {
        if ($pharmacyDispense->review_status !== Dispense::REVIEW_OPEN) {
            throw PharmacyError::make(409, 'dispense_not_open');
        }

        $pharmacyDispense->fill(['review_status' => Dispense::REVIEWED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => CarbonImmutable::now(), 'review_note' => $request->validated('note')])->save();
        $this->auditor->record('pharmacy.dispense.review', $pharmacyDispense, ['review_status' => Dispense::REVIEW_OPEN], ['review_status' => Dispense::REVIEWED, 'flags' => $pharmacyDispense->flags, 'note' => $request->validated('note')]);

        return DispenseResource::make($pharmacyDispense->load(DispenseList::RELATIONS));
    }
}
