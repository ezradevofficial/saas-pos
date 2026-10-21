<?php

namespace App\Core\Privacy;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Privacy\Models\PrivacyRequest;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NFR-08: the data subject request workflow (docs/privacy.md).
 *
 * - create: received, with the legal deadline from the subject's country.
 * - approve / reject: by someone holding `core.privacy_request.approve`,
 *   never the person who logged the request (four eyes).
 * - execute: an approved request is carried out (access: the report is
 *   available to download; correction: the new values applied;
 *   deletion: personal data anonymised) and completed.
 *
 * Every step is audited (AUD-01). The audit entries name fields, never the
 * personal values an anonymisation removes.
 */
class PrivacyRequests
{
    public function __construct(
        private readonly SubjectData $data,
        private readonly PrivacySubjects $subjects,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed>|null $corrections */
    public function create(User $by, string $type, string $subjectId, string $kind, ?string $note, ?array $corrections): PrivacyRequest
    {
        if (! $this->data->exists($type, $subjectId)) {
            throw new ApiException(422, 'subject_not_found', __('privacy.errors.subject_not_found'), ['subject_id' => [__('privacy.errors.subject_not_found')]]);
        }

        // Review M4: deleting a user is user management; the requester must be allowed to deactivate them.
        if ($kind === PrivacyRequest::DELETION && ! $this->subjects->mayDelete($type, $by, $subjectId)) {
            throw new ApiException(403, 'deletion_not_allowed', __('privacy.errors.deletion_not_allowed'));
        }

        $received = CarbonImmutable::now();
        $country = $this->data->country($type, $subjectId);
        $days = (int) (config("privacy.deadline_days.{$country}") ?? config('privacy.default_deadline_days', 7));

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($by, $type, $subjectId, $kind, $note, $corrections, $received, $days) {
            $request = PrivacyRequest::query()->create([
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'kind' => $kind,
                'status' => PrivacyRequest::RECEIVED,
                'note' => $note,
                'corrections' => $kind === PrivacyRequest::CORRECTION ? $corrections : null,
                'received_at' => $received,
                'due_at' => $received->addDays($days),
                'requested_by' => $by->id,
            ]);
            // The note and corrections may hold personal data: named, not copied.
            $this->auditor->record('core.privacy_request.create', $request, null, [
                'subject_type' => $type, 'subject_id' => $subjectId, 'kind' => $kind,
                'due_at' => $request->due_at->toIso8601String(), 'fields' => array_keys($corrections ?? []),
            ]);

            return $request;
        });
    }

    public function approve(PrivacyRequest $request, User $by): PrivacyRequest
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $by) {
            // Locked and checked again: two approvers (or an approver and a rejecter) never both win.
            $request = $this->locked($request);
            $this->assertDecidable($request, $by);
            $request->forceFill(['status' => PrivacyRequest::APPROVED, 'approved_by' => $by->id, 'approved_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('core.privacy_request.approve', $request, ['status' => PrivacyRequest::RECEIVED], ['status' => PrivacyRequest::APPROVED]);

            return $request;
        });
    }

    public function reject(PrivacyRequest $request, User $by, string $reason): PrivacyRequest
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $by, $reason) {
            $request = $this->locked($request);
            $this->assertDecidable($request, $by);
            $request->forceFill([
                'status' => PrivacyRequest::REJECTED, 'rejected_by' => $by->id,
                'rejected_at' => CarbonImmutable::now(), 'rejection_reason' => $reason,
            ])->save();
            // Review M5: the reason is free text (it may name the person): kept on the request, never in the audit log.
            $this->auditor->record('core.privacy_request.reject', $request, ['status' => PrivacyRequest::RECEIVED], ['status' => PrivacyRequest::REJECTED]);

            return $request;
        });
    }

    public function execute(PrivacyRequest $request, User $by): PrivacyRequest
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $by) {
            $request = $this->locked($request);

            if ($request->status !== PrivacyRequest::APPROVED) {
                throw new ApiException(422, 'not_approved', __('privacy.errors.not_approved'));
            }

            if (! $this->data->exists($request->subject_type, $request->subject_id)) {
                throw new ApiException(422, 'subject_not_found', __('privacy.errors.subject_not_found'));
            }

            // Review M4: whoever carries out a user's deletion must be allowed to deactivate them.
            if ($request->kind === PrivacyRequest::DELETION && ! $this->subjects->mayDelete($request->subject_type, $by, $request->subject_id)) {
                throw new ApiException(403, 'deletion_not_allowed', __('privacy.errors.deletion_not_allowed'));
            }

            $type = $request->subject_type;
            $id = $request->subject_id;

            [$result, $before, $after] = match ($request->kind) {
                PrivacyRequest::ACCESS => [['report' => 'available'], null, null],
                PrivacyRequest::CORRECTION => $this->correction($request),
                PrivacyRequest::DELETION => $this->deletion($request),
            };

            $request->forceFill([
                'status' => PrivacyRequest::COMPLETED, 'completed_by' => $by->id,
                'completed_at' => CarbonImmutable::now(), 'result' => $result,
            ]);
            // Review M5: once a deletion is done, the request's own free text about the person goes too.
            if ($request->kind === PrivacyRequest::DELETION) {
                $request->forceFill(['note' => null, 'corrections' => null, 'rejection_reason' => null]);
            }
            $request->save();

            $this->auditor->record('core.privacy_request.execute', $request, $before, $after ?? ['result' => $result], [
                'subject_type' => $type, 'subject_id' => $id, 'kind' => $request->kind,
            ]);

            return $request;
        });
    }

    /** @return array<string, mixed> the access report (audited as a download) */
    public function report(PrivacyRequest $request, User $by): array
    {
        if ($request->kind !== PrivacyRequest::ACCESS || $request->status !== PrivacyRequest::COMPLETED) {
            throw new ApiException(422, 'report_unavailable', __('privacy.errors.report_unavailable'));
        }

        $report = $this->data->report($request->subject_type, $request->subject_id, $by);
        $this->auditor->record('core.privacy_request.download', $request, null, [
            'tables' => array_keys($report['records']), 'related' => array_keys($report['related']), 'by' => $by->id,
        ]);

        return $report;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function correction(PrivacyRequest $request): array
    {
        $change = $this->data->correct($request->subject_type, $request->subject_id, (array) $request->corrections);

        // A correction is an ordinary edit: before and after are audited (AUD-01).
        return [['corrected' => array_keys($change['after'])], $change['before'], $change['after']];
    }

    /** @return array{0: array<string, mixed>, 1: null, 2: array<string, mixed>} */
    private function deletion(PrivacyRequest $request): array
    {
        $ref = strtoupper(Str::substr(str_replace('-', '', $request->id), -8));
        $label = __('privacy.anonymised_label', ['ref' => $ref]);
        $done = $this->data->anonymise($request->subject_type, $request->subject_id, $label);
        $retained = $this->data->retained($request->subject_type, $request->subject_id);

        $result = [
            'anonymised' => $done['changed'],
            'archived' => $done['archived'],
            'retained' => $retained,
            'retained_for_legal_obligation' => $done['kept'],
            'notes' => $done['notes'],
            'label' => $label,
        ];

        // Fields only: the old values must not survive in the audit log.
        return [$result, null, ['anonymised' => $done['changed'], 'archived' => $done['archived'], 'retained' => $retained, 'retained_for_legal_obligation' => $done['kept']]];
    }

    /** The request re-read under a row lock, in the current transaction. */
    private function locked(PrivacyRequest $request): PrivacyRequest
    {
        $fresh = PrivacyRequest::query()->lockForUpdate()->findOrFail($request->getKey());
        $request->setRawAttributes($fresh->getAttributes(), true);

        return $request;
    }

    private function assertDecidable(PrivacyRequest $request, User $by): void
    {
        if ($request->status !== PrivacyRequest::RECEIVED) {
            throw new ApiException(422, 'already_decided', __('privacy.errors.already_decided'));
        }

        if ($request->requested_by === $by->id) {
            throw new ApiException(403, 'own_request', __('privacy.errors.own_request'));
        }
    }

    /**
     * @param  array<string, string>|null  $names  subject names by id, prefetched for a list (SubjectData::displayMany)
     * @return array<string, mixed>
     */
    public function present(PrivacyRequest $request, User $viewer, ?array $names = null): array
    {
        return [
            'id' => $request->id,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'subject_name' => $request->result['label'] ?? ($names !== null ? ($names[$request->subject_id] ?? null) : $this->data->display($request->subject_type, $request->subject_id)),
            'kind' => $request->kind,
            'status' => $request->status,
            'note' => $request->note,
            'corrections' => $request->corrections,
            'received_at' => $request->received_at->toIso8601String(),
            'due_at' => $request->due_at->toIso8601String(),
            'overdue' => $request->isOverdue(),
            'requested_by' => $request->requester ? ['id' => $request->requester->id, 'name' => $request->requester->name] : null,
            'approved_by' => $request->approver ? ['id' => $request->approver->id, 'name' => $request->approver->name] : null,
            'approved_at' => $request->approved_at?->toIso8601String(),
            'rejected_at' => $request->rejected_at?->toIso8601String(),
            'rejection_reason' => $request->rejection_reason,
            'completed_at' => $request->completed_at?->toIso8601String(),
            'result' => $request->result,
            'own' => $request->requested_by === $viewer->id,
        ];
    }

    public function subjects(): PrivacySubjects
    {
        return $this->subjects;
    }
}
