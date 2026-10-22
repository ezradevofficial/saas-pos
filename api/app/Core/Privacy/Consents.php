<?php

namespace App\Core\Privacy;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Privacy\Models\ConsentRecord;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NFR-08: marketing consent for customers (CRM and E-commerce share the
 * party). Each grant or withdrawal is a new, audited record; the current
 * state per purpose and channel is the latest record. Modules record
 * consent through this service (a storefront sign-up, a lead form, an
 * import) with their own `source`, the version of the wording the person
 * saw (`wording_version`) and the evidence. Every marketing send checks
 * `allows()` / `allowedAmong()` first; the Notifier does it for marketing
 * event types (EventType::$marketing), at queueing and again at hand-over.
 */
class Consents
{
    public const SUBJECT_TYPES = ['customer'];

    public const MARKETING = 'marketing';

    public function __construct(private readonly SubjectData $data, private readonly Auditor $auditor) {}

    public function record(?User $by, string $type, string $subjectId, string $purpose, string $channel, bool $granted, string $source, ?string $evidence = null, ?string $wordingVersion = null): ConsentRecord
    {
        if (! $this->data->exists($type, $subjectId)) {
            throw new ApiException(422, 'subject_not_found', __('privacy.errors.subject_not_found'), ['subject_id' => [__('privacy.errors.subject_not_found')]]);
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($by, $type, $subjectId, $purpose, $channel, $granted, $source, $evidence, $wordingVersion) {
            $record = ConsentRecord::query()->create([
                'subject_type' => $type,
                'subject_id' => $subjectId,
                'purpose' => $purpose,
                'channel' => $channel,
                'granted' => $granted,
                'source' => $source,
                'recorded_by' => $by?->id,
                'recorded_at' => CarbonImmutable::now(),
                'evidence' => $evidence,
                'wording_version' => $wordingVersion,
            ]);
            $this->auditor->record($granted ? 'core.consent.grant' : 'core.consent.withdraw', $record, null, [
                'subject_type' => $type, 'subject_id' => $subjectId, 'purpose' => $purpose, 'channel' => $channel, 'source' => $source,
                'wording_version' => $wordingVersion,
            ]);

            return $record;
        });
    }

    /**
     * The current state per purpose and channel (latest record wins), and the history, newest first.
     *
     * @return array{current: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function forSubject(string $type, string $subjectId): array
    {
        $history = ConsentRecord::query()->with('recorder')
            ->where('subject_type', $type)->where('subject_id', $subjectId)
            ->orderByDesc('recorded_at')->orderByDesc('id')->limit(200)->get();

        $current = [];
        foreach ($history as $record) {
            $current[$record->purpose.'|'.$record->channel] ??= $record;
        }

        return [
            'current' => array_values(array_map(fn (ConsentRecord $r) => $this->present($r), $current)),
            'history' => $history->map(fn (ConsentRecord $r) => $this->present($r))->all(),
        ];
    }

    /** Whether the subject may be contacted for $purpose on $channel now (an `any` record counts for every channel). */
    public function allows(string $type, string $subjectId, string $purpose, string $channel): bool
    {
        return $this->allowedAmong($type, [$subjectId], $purpose, $channel) !== [];
    }

    /**
     * The subjects among $subjectIds that may be contacted for $purpose on
     * $channel now, in one query (a campaign's audience): for each, the latest
     * record on that channel or `any` decides; no record means no.
     *
     * @param  iterable<string>  $subjectIds
     * @return list<string>
     */
    public function allowedAmong(string $type, iterable $subjectIds, string $purpose, string $channel): array
    {
        $ids = [];
        foreach ($subjectIds as $id) {
            if (Str::isUuid((string) $id)) {
                $ids[(string) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $latest = ConsentRecord::query()
            ->selectRaw('distinct on (subject_id) subject_id, granted')
            ->where('subject_type', $type)->whereIn('subject_id', array_keys($ids))
            ->where('purpose', $purpose)->whereIn('channel', [$channel, 'any'])
            ->orderBy('subject_id')->orderByDesc('recorded_at')->orderByDesc('id')
            ->toBase()->get();

        return $latest->filter(fn ($row) => (bool) $row->granted)->pluck('subject_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(ConsentRecord $record): array
    {
        return [
            'id' => $record->id,
            'purpose' => $record->purpose,
            'channel' => $record->channel,
            'granted' => $record->granted,
            'source' => $record->source,
            'evidence' => $record->evidence,
            'wording_version' => $record->wording_version,
            'recorded_at' => $record->recorded_at->toIso8601String(),
            'recorded_by' => $record->recorder ? ['id' => $record->recorder->id, 'name' => $record->recorder->name] : null,
        ];
    }
}
