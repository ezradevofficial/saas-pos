<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\CompanyClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Modules\Pharmacy\Http\Lists\RegisterList;
use Modules\Pharmacy\Http\Requests\ListRegisterRequest;
use Modules\Pharmacy\Http\Requests\PrintRegisterRequest;
use Modules\Pharmacy\Http\Requests\RegisterAdjustmentRequest;
use Modules\Pharmacy\Http\Resources\Refs;
use Modules\Pharmacy\Http\Resources\RegisterEntryResource;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Qty;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-18, PHA-19: the controlled-drug register: entries, a printable page and corrections. */
class RegisterController
{
    /** A printed register stays a manageable page set. */
    public const PRINT_MAX = 5000;

    public function __construct(
        private readonly ControlledRegister $register,
        private readonly Profiles $profiles,
        private readonly Places $places,
        private readonly Auditor $auditor,
    ) {}

    public function index(ListRegisterRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = RegisterEntry::query()->with(RegisterList::RELATIONS);
        $request->applyFilters($query, 'location_id', 'occurred_at');

        foreach (['item_id', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('batch_number', 'ilike', $like)->orWhere('patient_name', 'ilike', $like)->orWhere('prescriber_name', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RegisterEntryResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /**
     * PHA-19: the register of one item at one place for a period, as data for
     * a page of its own: opening balance, entries in entry order, closing
     * balance. Audited as a print.
     */
    public function print(PrintRegisterRequest $request): JsonResponse
    {
        $place = $this->places->of($request->validated('location_id'));
        $zone = CompanyClock::timezone($place['company_id'], $place['branch_id']);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->validated('from'), $zone)->startOfDay() : null;
        $to = $request->filled('to') ? CarbonImmutable::parse($request->validated('to'), $zone)->endOfDay() : null;
        $base = RegisterEntry::query()->where('location_id', $request->validated('location_id'))->where('item_id', $request->validated('item_id'));
        $entries = (clone $base)->when($from, fn (Builder $q) => $q->where('occurred_at', '>=', $from))->when($to, fn (Builder $q) => $q->where('occurred_at', '<=', $to))
            ->with(RegisterList::RELATIONS)->orderBy('entry_no');

        if ((clone $entries)->count() > self::PRINT_MAX) {
            throw PharmacyError::make(422, 'register_too_long', ['max' => self::PRINT_MAX], 'from');
        }

        $rows = $entries->get();
        $opening = $from === null ? '0' : (string) ((clone $base)->where('occurred_at', '<', $from)->orderByDesc('entry_no')->value('balance') ?? '0');
        $closing = $rows->isEmpty() ? $opening : (string) $rows->last()->balance;
        $this->auditor->record('pharmacy.register.print', null, null, ['item_id' => $request->validated('item_id'), 'location_id' => $request->validated('location_id'), 'from' => $request->validated('from'), 'to' => $request->validated('to'), 'entries' => $rows->count()]);

        return new JsonResponse(['data' => [
            'item' => Refs::item(Item::query()->find($request->validated('item_id'))),
            'location' => ['id' => $request->validated('location_id'), 'name' => $place['name']],
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
            'opening_balance' => Qty::show($opening),
            'closing_balance' => Qty::show($closing),
            'printed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'printed_by' => Refs::user($request->user()),
            'entries' => RegisterEntryResource::collection($rows)->resolve($request),
        ]]);
    }

    /** PHA-18: a correction: a new `adjustment` entry with a reason (the register is never edited). */
    public function adjust(RegisterAdjustmentRequest $request): JsonResponse
    {
        $place = $this->places->require($request->validated('location_id'));

        if (! $this->profiles->of($request->validated('item_id'))?->isControlled()) {
            throw PharmacyError::make(422, 'item_not_controlled', field: 'item_id');
        }

        $lot = $request->filled('lot_id') ? Lot::query()->find($request->validated('lot_id')) : null;

        if ($request->filled('lot_id') && ($lot === null || $lot->item_id !== $request->validated('item_id') || $lot->company_id !== $place['company_id'])) {
            throw PharmacyError::make(422, 'lot_unknown', field: 'lot_id');
        }
        $entry = $this->register->append(
            $place['company_id'], $request->validated('location_id'), $request->validated('item_id'), RegisterEntry::ADJUSTMENT, Qty::of($request->validated('qty')),
            'pharmacy.register', (string) Str::uuid7(), null, $lot, null, null, $request->user()->id, $request->validated('reason'),
        );
        $this->auditor->record('pharmacy.register.adjust', $entry, null, ['item_id' => $entry->item_id, 'qty' => Qty::show($entry->qty), 'balance' => Qty::show($entry->balance), 'reason' => $entry->reason]);

        return RegisterEntryResource::make($entry->load(RegisterList::RELATIONS))->response()->setStatusCode(201);
    }
}
