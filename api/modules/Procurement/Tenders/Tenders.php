<?php

namespace Modules\Procurement\Tenders;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Procurement\Documents\DocumentNumbers;
use Modules\Procurement\Documents\Requisitions;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\RequisitionLine;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderCriterion;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Portal\PortalAuth;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-06: tenders.
 *
 * - create() / update(): a draft at a branch (open or invited), from an
 *   approved requisition (its lines when none are given) or blank; lines,
 *   technical criteria, the technical and financial weights (summing 100),
 *   the pass mark, the submission deadline and the opening time (not
 *   before the deadline), the committee: openers and evaluators, users who
 *   hold `procurement.tender.open` / `.evaluate` at the branch. The
 *   tender's sealing key is made at creation (Seal).
 * - publish(): numbered; needs lines, criteria weights summing 100 when the
 *   technical weight is above 0, at least two openers and one evaluator
 *   (when scored technically), a deadline in the future. Published tenders
 *   keep their lines, criteria and times.
 * - cancel(): any tender not awarded; every portal session ends.
 * - noticeToken(): an open tender's public notice token: random at
 *   publication, its sha256 looked up by the portal, the token kept
 *   encrypted so staff can share the link again (ADR 019).
 */
class Tenders
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly DocumentNumbers $numbers,
        private readonly Requisitions $requisitions,
        private readonly ScopeResolver $scopes,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Tender
    {
        return Tx::run(function () use ($data, $by) {
            $branch = Branch::query()->with('company')->findOrFail($data['branch_id']);
            $requisition = empty($data['requisition_id']) ? null : $this->requisitions->approved($branch, $data['requisition_id'], $by);
            $tender = new Tender([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'requisition_id' => $requisition?->id,
                'status' => Tender::DRAFT,
                'seal_secret' => Seal::newSecret(),
                'created_by' => $by->id,
                'currency' => $this->resolver->currency($data['currency'] ?? $branch->company->base_currency),
            ]);
            $this->fill($tender, $data, creating: true);
            $tender->save();
            $this->writeLines($tender, $data['lines'] ?? $this->linesOf($requisition));
            $this->writeCriteria($tender, $data['criteria'] ?? []);
            $this->writeMembers($tender, $data['openers'] ?? [], $data['evaluators'] ?? []);

            return $tender->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Tender $tender, array $data): Tender
    {
        return Tx::run(function () use ($tender, $data) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if ($tender->status !== Tender::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (array_key_exists('currency', $data)) {
                $tender->currency = $this->resolver->currency((string) $data['currency']);
            }

            $this->fill($tender, $data, creating: false);
            $tender->save();

            if (isset($data['lines'])) {
                TenderLine::query()->where('tender_id', $tender->id)->delete();
                $this->writeLines($tender, $data['lines']);
            }

            if (isset($data['criteria'])) {
                TenderCriterion::query()->where('tender_id', $tender->id)->delete();
                $this->writeCriteria($tender, $data['criteria']);
            }

            if (isset($data['openers']) || isset($data['evaluators'])) {
                $members = TenderMember::query()->where('tender_id', $tender->id)->get();
                $openers = $data['openers'] ?? $members->where('role', TenderMember::OPENER)->pluck('user_id')->all();
                $evaluators = $data['evaluators'] ?? $members->where('role', TenderMember::EVALUATOR)->pluck('user_id')->all();
                TenderMember::query()->where('tender_id', $tender->id)->delete();
                $this->writeMembers($tender, $openers, $evaluators);
            }

            return $tender->refresh();
        });
    }

    public function publish(Tender $tender): Tender
    {
        return Tx::run(function () use ($tender) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if ($tender->status !== Tender::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (! $tender->lines()->exists()) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            if ($tender->technical_weight > 0) {
                $weights = (int) $tender->criteria()->sum('weight');

                if ($weights !== 100) {
                    throw ProcurementError::make('criteria_weights', ['sum' => $weights], 'criteria');
                }

                if (! $tender->members()->where('role', TenderMember::EVALUATOR)->exists()) {
                    throw ProcurementError::make('evaluators_required', field: 'evaluators');
                }
            }

            if ($tender->members()->where('role', TenderMember::OPENER)->count() < 2) {
                throw ProcurementError::make('openers_required', field: 'openers');
            }

            if ($tender->deadline_at->isPast()) {
                throw ProcurementError::make('deadline_in_past', field: 'deadline_at');
            }

            $notice = $tender->kind === Tender::OPEN ? Str::random(48) : null;
            $tender->fill([
                'number' => $tender->number ?? $this->numbers->next(DocumentNumbers::TENDER, $tender->company_id, $tender->branch_id),
                'status' => Tender::PUBLISHED,
                'published_at' => CarbonImmutable::now(),
                // PRC-08, ADR 019: a random notice token; its hash is looked up, the token kept encrypted for staff.
                'public_token_hash' => $notice === null ? null : hash('sha256', $notice),
                'public_token_secret' => $notice === null ? null : Crypt::encryptString($notice),
            ])->save();

            if ($tender->requisition_id !== null) {
                $this->requisitions->markOrdered(Requisition::query()->lockForUpdate()->findOrFail($tender->requisition_id));
            }

            // PRC-07: bidders added while it was a draft get their links now.
            app(Bidders::class)->sendLinks($tender);

            return $tender->refresh();
        });
    }

    public function cancel(Tender $tender, User $by, string $reason): Tender
    {
        return Tx::run(function () use ($tender, $by, $reason) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if (in_array($tender->status, [Tender::AWARDED, Tender::CANCELLED], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $tender->fill(['status' => Tender::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();
            app(PortalAuth::class)->revokeTender($tender);

            return $tender->refresh();
        });
    }

    /** PRC-08: an open tender's public notice token (random, kept encrypted), or null. */
    public static function noticeToken(Tender $tender): ?string
    {
        return $tender->public_token_secret === null ? null : Crypt::decryptString($tender->public_token_secret);
    }

    /** @param array<string, mixed> $data */
    private function fill(Tender $tender, array $data, bool $creating): void
    {
        foreach (['title', 'description', 'kind', 'deadline_at', 'opening_at', 'technical_weight', 'pass_mark'] as $field) {
            if (array_key_exists($field, $data)) {
                $tender->{$field} = $data[$field];
            }
        }

        if ($creating) {
            $tender->technical_weight ??= 70;
            $tender->pass_mark ??= 0;
        }

        $tender->financial_weight = 100 - (int) $tender->technical_weight;

        if ($tender->opening_at === null || $tender->opening_at->lessThan($tender->deadline_at)) {
            throw ProcurementError::make('opening_before_deadline', field: 'opening_at');
        }
    }

    /** @return list<array<string, mixed>> */
    private function linesOf(?Requisition $requisition): array
    {
        if ($requisition === null) {
            return [];
        }

        return RequisitionLine::query()->where('requisition_id', $requisition->id)->orderBy('line_no')->get()
            ->map(fn (RequisitionLine $line) => ['item_id' => $line->item_id, 'description' => $line->description, 'uom_id' => $line->uom_id, 'qty' => (string) $line->qty])->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Tender $tender, array $lines): void
    {
        foreach (array_values($lines) as $index => $input) {
            $line = $this->resolver->line($tender->company_id, $input, "lines.{$index}");
            TenderLine::query()->create([
                'tender_id' => $tender->id, 'line_no' => $index + 1, 'item_id' => $line['item_id'],
                'description' => $line['description'], 'uom_id' => $line['uom_id'], 'qty' => $line['qty'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $criteria */
    private function writeCriteria(Tender $tender, array $criteria): void
    {
        foreach (array_values($criteria) as $index => $input) {
            TenderCriterion::query()->create([
                'tender_id' => $tender->id, 'line_no' => $index + 1, 'name' => $input['name'],
                'weight' => (int) $input['weight'], 'max_score' => (int) ($input['max_score'] ?? 10),
            ]);
        }
    }

    /**
     * @param  list<string>  $openers
     * @param  list<string>  $evaluators
     */
    private function writeMembers(Tender $tender, array $openers, array $evaluators): void
    {
        foreach ([TenderMember::OPENER => $openers, TenderMember::EVALUATOR => $evaluators] as $role => $ids) {
            $permission = $role === TenderMember::OPENER ? 'procurement.tender.open' : 'procurement.tender.evaluate';
            $field = $role === TenderMember::OPENER ? 'openers' : 'evaluators';

            foreach (array_values(array_unique($ids)) as $index => $userId) {
                $user = User::query()->whereKey($userId)->where('status', User::STATUS_ACTIVE)->first();

                if ($user === null || ! $this->scopes->can($user, $permission, Scope::branch($tender->branch_id))) {
                    throw ProcurementError::make('member_not_allowed', field: "{$field}.{$index}");
                }

                // PRC-08: a bidder never sits on the committee.
                if ($user->email !== null && Bidder::query()->where('tender_id', $tender->id)->whereNull('removed_at')->whereRaw('lower(email) = ?', [strtolower($user->email)])->exists()) {
                    throw ProcurementError::make('conflict_of_interest', field: "{$field}.{$index}");
                }

                TenderMember::query()->create(['tender_id' => $tender->id, 'user_id' => $user->id, 'role' => $role]);
            }
        }
    }
}
