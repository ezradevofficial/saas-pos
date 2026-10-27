<?php

namespace Modules\Salon\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Salon\Appointments\Pricing;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\CommissionBatch;
use Modules\Salon\Models\CommissionEntry;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\Payout;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\TimeOff;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Queue\Queue;
use Modules\Salon\Support\Places;

/**
 * The JSON of Salon records (docs/modules/salon.md): amounts as digit strings, times ISO 8601 UTC, names resolved in a
 * few queries for a whole list. Personal data follows the reader's rights: contact details, allergies and formulas are
 * left out unless their field rules allow and, for notes, `salon.client.notes` is held at the record's place (RBAC-05,
 * NFR-08).
 */
class Views
{
    /** The field rules resource of client data (RBAC-05): `allergies`, `formulas`, `contact`, `notes`. */
    public const CLIENT_RESOURCE = 'salon_client';

    public function __construct(
        private readonly Pricing $pricing,
        private readonly Deposits $deposits,
        private readonly ScopeResolver $resolver,
        private readonly Queue $queue,
    ) {}

    private static function at(mixed $time): ?string
    {
        return $time?->toIso8601String();
    }

    /** @param Collection<int, Service> $services @return list<array<string, mixed>> */
    public function services(Collection $services, ?Company $company = null): array
    {
        $items = Item::query()->whereKey($services->pluck('item_id')->merge($services->flatMap(fn (Service $s) => array_column((array) $s->products, 'item_id')))->unique())->pluck('name', 'id');
        $companies = Company::query()->whereKey($services->pluck('company_id')->unique())->get()->keyBy('id');

        return $services->map(function (Service $s) use ($items, $companies) {
            $company = $companies->get($s->company_id);
            $price = $company === null ? null : $this->pricing->unit($s, $company, now());

            return [
                'id' => $s->id, 'company_id' => $s->company_id, 'item_id' => $s->item_id, 'item_name' => $items[$s->item_id] ?? '', 'name' => $s->name, 'category' => $s->category,
                'kind' => $s->kind, 'duration_minutes' => $s->duration_minutes, 'buffer_minutes' => $s->buffer_minutes, 'online_bookable' => $s->online_bookable,
                'staff_ids' => $s->staff_ids, 'resource_ids' => $s->resource_ids, 'addon_ids' => $s->addon_ids,
                'products' => array_map(fn (array $p) => ['item_id' => $p['item_id'], 'item_name' => $items[$p['item_id']] ?? '', 'qty' => (string) $p['qty']], (array) $s->products),
                'price_minor' => $price === null ? null : (string) $price, 'currency' => $company?->base_currency, 'archived_at' => self::at($s->archived_at),
            ];
        })->values()->all();
    }

    /** @param Collection<int, PackageType> $types @return list<array<string, mixed>> */
    public function packageTypes(Collection $types): array
    {
        $items = Item::query()->whereKey($types->pluck('item_id'))->pluck('name', 'id');

        return $types->map(fn (PackageType $t) => [
            'id' => $t->id, 'company_id' => $t->company_id, 'item_id' => $t->item_id, 'item_name' => $items[$t->item_id] ?? '', 'name' => $t->name, 'kind' => $t->kind,
            'sessions' => $t->sessions, 'validity_days' => $t->validity_days, 'service_ids' => $t->service_ids, 'archived_at' => self::at($t->archived_at),
        ])->values()->all();
    }

    /** @param Collection<int, Staff> $staff @return list<array<string, mixed>> */
    public function staff(Collection $staff): array
    {
        $users = User::query()->whereKey($staff->pluck('user_id')->filter())->pluck('name', 'id');

        return $staff->map(fn (Staff $s) => [
            'id' => $s->id, 'company_id' => $s->company_id, 'branch_id' => $s->branch_id, 'user_id' => $s->user_id, 'user_name' => $users[$s->user_id] ?? null, 'employee_id' => $s->employee_id,
            'name' => $s->name, 'title' => $s->title, 'color' => $s->color, 'accepts_online' => $s->accepts_online, 'archived_at' => self::at($s->archived_at),
        ])->values()->all();
    }

    /** @param Collection<int, resource> $resources @return list<array<string, mixed>> */
    public function resources(Collection $resources): array
    {
        return $resources->map(fn (Resource $r) => [
            'id' => $r->id, 'company_id' => $r->company_id, 'branch_id' => $r->branch_id, 'name' => $r->name, 'kind' => $r->kind, 'archived_at' => self::at($r->archived_at),
        ])->values()->all();
    }

    /** @param Collection<int, TimeOff> $rows @return list<array<string, mixed>> */
    public function timeOff(Collection $rows): array
    {
        return $rows->map(fn (TimeOff $t) => [
            'id' => $t->id, 'staff_id' => $t->staff_id, 'starts_at' => self::at($t->starts_at), 'ends_at' => self::at($t->ends_at), 'reason' => $t->reason, 'cancelled_at' => self::at($t->cancelled_at),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function online(SalonBranch $page): array
    {
        return [
            'branch_id' => $page->branch_id, 'company_id' => $page->company_id, 'location_id' => $page->location_id, 'enabled' => $page->online_enabled, 'public_name' => $page->public_name,
            'lead_minutes' => $page->lead_minutes, 'max_days_ahead' => $page->max_days_ahead, 'public_code' => $page->public_code,
            'path' => '/book/'.$page->public_code,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  bool  $detail  payments, the formula notes and contact details
     * @return list<array<string, mixed>>
     */
    public function appointments(Collection $appointments, Request $request, bool $detail = false): array
    {
        $lines = Line::query()->whereIn('appointment_id', $appointments->pluck('id')->all())->orderBy('position')->orderBy('id')->get()->groupBy('appointment_id');
        $services = Service::query()->whereKey($lines->flatten()->pluck('service_id')->unique())->pluck('name', 'id');
        $staff = Staff::query()->whereKey($lines->flatten()->pluck('staff_id')->unique())->pluck('name', 'id');
        $resources = Resource::query()->whereKey($lines->flatten()->pluck('resource_id')->filter()->unique())->pluck('name', 'id');
        $parties = Party::query()->whereKey($appointments->pluck('client_id')->filter()->unique())->get()->keyBy('id');
        $user = $request->user();
        $hidden = HidesFields::hidden($request, self::CLIENT_RESOURCE);

        return $appointments->map(function (Appointment $a) use ($lines, $services, $staff, $resources, $parties, $detail, $user, $hidden) {
            $party = $a->client_id === null ? null : $parties->get($a->client_id);
            $contact = ! in_array('contact', $hidden, true);
            $notes = $user !== null && $this->resolver->can($user, 'salon.client.notes', $a->scope()) && ! in_array('formulas', $hidden, true);

            return [
                'id' => $a->id, 'number' => $a->number, 'company_id' => $a->company_id, 'branch_id' => $a->branch_id, 'location_id' => $a->location_id,
                'client_id' => $a->client_id, 'client_name' => $party?->name ?? $a->guest_name,
                'guest_phone' => $contact ? ($party?->phoneNumbers()[0] ?? $a->guest_phone) : null, 'guest_email' => $contact ? ($party?->emails[0]['address'] ?? $a->guest_email) : null,
                'source' => $a->source, 'status' => $a->status, 'starts_at' => self::at($a->starts_at), 'ends_at' => self::at($a->ends_at),
                'total_minor' => (string) $a->total_minor, 'currency' => $a->currency, 'deposit_required_minor' => (string) $a->deposit_required_minor,
                'deposit_balance_minor' => (string) $this->deposits->balance($a), 'deposit_hold_until' => self::at($a->deposit_hold_until),
                'fee_minor' => (string) $a->fee_minor, 'fee_status' => $a->fee_status, 'fee_reason' => $a->fee_reason, 'fee_waive_reason' => $a->fee_waive_reason,
                'notes' => $a->notes, 'service_notes' => $detail && $notes ? $a->service_notes : null,
                'checked_in_at' => self::at($a->checked_in_at), 'started_at' => self::at($a->started_at), 'finished_at' => self::at($a->finished_at),
                'cancelled_at' => self::at($a->cancelled_at), 'cancel_reason' => $a->cancel_reason, 'sale_id' => $a->sale_id, 'paid_at' => self::at($a->paid_at),
                'lines' => ($lines->get($a->id) ?? collect())->map(fn (Line $l) => [
                    'id' => $l->id, 'service_id' => $l->service_id, 'item_id' => $l->item_id, 'service_name' => $services[$l->service_id] ?? '', 'kind' => $l->kind,
                    'staff_id' => $l->staff_id, 'staff_name' => $staff[$l->staff_id] ?? '', 'resource_id' => $l->resource_id, 'resource_name' => $resources[$l->resource_id] ?? null,
                    'starts_at' => self::at($l->starts_at), 'ends_at' => self::at($l->ends_at), 'price_minor' => (string) $l->price_minor, 'net_minor' => $l->net_minor === null ? null : (string) $l->net_minor,
                ])->values()->all(),
                ...($detail ? ['payments' => $this->payments(Payment::query()->where('appointment_id', $a->id)->orderBy('created_at')->orderBy('id')->get())] : []),
            ];
        })->values()->all();
    }

    /** @param Collection<int, Payment> $payments @return list<array<string, mixed>> */
    public function payments(Collection $payments): array
    {
        return $payments->map(fn (Payment $p) => [
            'id' => $p->id, 'appointment_id' => $p->appointment_id, 'kind' => $p->kind, 'status' => $p->status, 'method_type' => $p->method_type, 'currency' => $p->currency,
            'amount_minor' => (string) $p->amount_minor, 'reference' => $p->reference, 'failure' => $p->failure, 'sale_id' => $p->sale_id, 'created_at' => self::at($p->created_at),
        ])->values()->all();
    }

    /** @param Collection<int, QueueEntry> $entries @return list<array<string, mixed>> */
    public function queue(Collection $entries, string $branchId): array
    {
        $waits = [];

        foreach ($entries->pluck('branch_id')->unique() as $branch) {
            $waits += $this->queue->waits((string) $branch);
        }

        $position = [];

        return $entries->map(function (QueueEntry $e) use ($waits, &$position) {
            $waiting = $e->status === QueueEntry::WAITING;
            $position[$e->branch_id] = ($position[$e->branch_id] ?? 0) + ($waiting ? 1 : 0);

            return [
                'id' => $e->id, 'branch_id' => $e->branch_id, 'client_id' => $e->client_id, 'guest_name' => $e->guest_name, 'guest_phone' => $e->guest_phone, 'service_ids' => $e->service_ids,
                'staff_id' => $e->staff_id, 'status' => $e->status, 'appointment_id' => $e->appointment_id, 'joined_at' => self::at($e->joined_at),
                'position' => $waiting ? $position[$e->branch_id] : null, 'wait_minutes' => $waiting ? ($waits[$e->id] ?? null) : null,
            ];
        })->values()->all();
    }

    /**
     * A client's profile. Allergies and formulas are shown only with `salon.client.notes` at the company and when the
     * reader's field rules do not hide them; contact details follow `contact`.
     *
     * @return array<string, mixed>
     */
    public function client(Party $party, ?Client $client, Request $request, string $companyId): array
    {
        $notes = $request->user() !== null && app(Places::class)->inCompany($request->user(), 'salon.client.notes', $companyId);

        return HidesFields::apply($request, self::CLIENT_RESOURCE, [
            'party_id' => $party->id, 'company_id' => $companyId, 'name' => $party->name, 'phone' => $party->phoneNumbers()[0] ?? null, 'email' => $party->emails[0]['address'] ?? null,
            'preferred_staff_id' => $client?->preferred_staff_id, 'preferences' => $client?->preferences,
            'allergies' => $notes ? $client?->allergies : null, 'formulas' => $notes ? $client?->formulas : null, 'notes' => $client?->notes,
            'has_profile' => $client !== null, 'has_alerts' => $client !== null && (filled($client->allergies) || filled($client->formulas)),
        ], ['phone' => ['contact'], 'email' => ['contact']]);
    }

    /** @param Collection<int, Package> $packages @return list<array<string, mixed>> */
    public function packages(Collection $packages): array
    {
        $names = Party::query()->whereKey($packages->pluck('customer_id')->unique())->pluck('name', 'id');

        return $packages->map(fn (Package $p) => [
            'id' => $p->id, 'company_id' => $p->company_id, 'type_id' => $p->type_id, 'name' => $p->name, 'customer_id' => $p->customer_id, 'customer_name' => $names[$p->customer_id] ?? '',
            'sessions_total' => $p->sessions_total, 'sessions_used' => $p->sessions_used, 'sessions_left' => $p->sessionsLeft(), 'currency' => $p->currency,
            'price_minor' => (string) $p->price_minor, 'remaining_minor' => (string) $p->remaining_minor, 'session_value_minor' => (string) $p->sessionValue(),
            'status' => $p->status, 'expires_on' => $p->expires_on?->toDateString(), 'service_ids' => $p->service_ids, 'sale_id' => $p->sale_id,
        ])->values()->all();
    }

    /** @param Collection<int, SalonException> $rows @return list<array<string, mixed>> */
    public function exceptions(Collection $rows): array
    {
        return $rows->map(fn (SalonException $e) => [
            'id' => $e->id, 'company_id' => $e->company_id, 'branch_id' => $e->branch_id, 'kind' => $e->kind, 'appointment_id' => $e->appointment_id, 'sale_id' => $e->sale_id,
            'package_id' => $e->package_id, 'amount_minor' => $e->amount_minor === null ? null : (string) $e->amount_minor, 'currency' => $e->currency, 'detail' => $e->detail,
            'status' => $e->status, 'note' => $e->note, 'resolved_at' => self::at($e->resolved_at), 'created_at' => self::at($e->created_at),
        ])->values()->all();
    }

    /** @param Collection<int, CommissionRule> $rules @return list<array<string, mixed>> */
    public function rules(Collection $rules): array
    {
        $staff = Staff::query()->whereKey($rules->pluck('staff_id')->filter())->pluck('name', 'id');
        $services = Service::query()->whereKey($rules->pluck('service_id')->filter())->pluck('name', 'id');

        return $rules->map(fn (CommissionRule $r) => [
            'id' => $r->id, 'company_id' => $r->company_id, 'staff_id' => $r->staff_id, 'staff_name' => $staff[$r->staff_id] ?? null, 'service_id' => $r->service_id,
            'service_name' => $services[$r->service_id] ?? null, 'applies_to' => $r->applies_to, 'kind' => $r->kind, 'rate' => $r->rate === null ? null : rtrim(rtrim((string) $r->rate, '0'), '.'),
            'amount_minor' => $r->amount_minor === null ? null : (string) $r->amount_minor, 'currency' => $r->currency,
            'effective_from' => $r->effective_from->toDateString(), 'effective_to' => $r->effective_to?->toDateString(), 'archived_at' => self::at($r->archived_at),
        ])->values()->all();
    }

    /** @param Collection<int, CommissionEntry> $entries @return list<array<string, mixed>> */
    public function entries(Collection $entries): array
    {
        $staff = Staff::query()->whereKey($entries->pluck('staff_id')->unique())->pluck('name', 'id');
        $items = Item::query()->whereKey($entries->pluck('item_id')->filter()->unique())->pluck('name', 'id');

        return $entries->map(fn (CommissionEntry $e) => [
            'id' => $e->id, 'staff_id' => $e->staff_id, 'staff_name' => $staff[$e->staff_id] ?? '', 'kind' => $e->kind, 'line_kind' => $e->line_kind, 'sale_id' => $e->sale_id,
            'appointment_id' => $e->appointment_id, 'item_name' => $items[$e->item_id] ?? null, 'base_minor' => (string) $e->base_minor, 'currency' => $e->currency,
            'amount_minor' => (string) $e->amount_minor, 'source_type' => $e->source_type, 'earned_at' => self::at($e->earned_at), 'batch_id' => $e->batch_id,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function batch(CommissionBatch $b, bool $detail = false): array
    {
        $payouts = Payout::query()->where('batch_id', $b->id)->get();
        $staff = Staff::query()->whereKey($payouts->pluck('staff_id'))->pluck('name', 'id');
        $users = User::query()->whereKey([$b->created_by, $b->submitted_by, $b->decided_by, $b->exported_by])->pluck('name', 'id');

        return [
            'id' => $b->id, 'company_id' => $b->company_id, 'branch_id' => $b->branch_id, 'number' => $b->number, 'period_start' => $b->period_start->toDateString(), 'period_end' => $b->period_end->toDateString(),
            'currency' => $b->currency, 'status' => $b->status, 'total_minor' => (string) $payouts->sum('amount_minor'), 'staff_count' => $payouts->count(),
            'created_by' => $b->created_by, 'created_by_name' => $users[$b->created_by] ?? null, 'submitted_by_name' => $users[$b->submitted_by] ?? null, 'submitted_at' => self::at($b->submitted_at),
            'decided_by_name' => $users[$b->decided_by] ?? null, 'decided_at' => self::at($b->decided_at), 'reject_reason' => $b->reject_reason,
            'exported_at' => self::at($b->exported_at), 'export_mode' => $b->export_mode,
            'payouts' => $payouts->sortBy(fn (Payout $p) => $staff[$p->staff_id] ?? '')->map(fn (Payout $p) => [
                'id' => $p->id, 'staff_id' => $p->staff_id, 'staff_name' => $staff[$p->staff_id] ?? '', 'employee_id' => $p->employee_id, 'amount_minor' => (string) $p->amount_minor,
                'currency' => $p->currency, 'entry_count' => $p->entry_count, 'status' => $p->status,
            ])->values()->all(),
            ...($detail ? ['entries' => $this->entries(CommissionEntry::query()->where('batch_id', $b->id)->orderBy('earned_at')->orderBy('id')->limit(2000)->get())] : []),
        ];
    }
}
