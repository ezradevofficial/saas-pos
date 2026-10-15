<?php

namespace Modules\CRM\Http\Resources;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Modules\CRM\Models\Target;
use Modules\CRM\Models\Team;
use Modules\CRM\Targets\Targets;

/**
 * CRM-30: a target, its subject, period and amount, with its actual and
 * the share reached (computed when read, for the reader; null with the
 * reason in `unavailable` when there is none for them).
 *
 * @mixin Target
 */
class TargetResource extends JsonResource
{
    /** @var array{subjects: array<string, ?array{id: string, name: string}>, members: array<string, list<string>>}|null the page's subjects and teams, read in a few queries */
    public static ?array $batch = null;

    /**
     * Read the subjects and team members of $targets at once (the list).
     *
     * @param  Collection<int, Target>  $targets
     */
    public static function prepare(Collection $targets): void
    {
        $ids = fn (string $type) => $targets->where('subject_type', $type)->pluck('subject_id')->unique()->values()->all();
        $named = fn ($rows) => $rows->mapWithKeys(fn ($row) => [(string) $row->getKey() => ['id' => (string) $row->getKey(), 'name' => (string) $row->getAttribute('name')]])->all();

        self::$batch = [
            'subjects' => [
                ...$named(User::query()->whereKey($ids('user'))->get(['id', 'name'])),
                ...$named(Team::query()->whereKey($ids('team'))->get(['id', 'name'])),
                ...$named(Branch::query()->whereKey($ids('branch'))->get(['id', 'name'])),
            ],
            'members' => app(Targets::class)->members($ids('team')),
        ];
    }

    public function toArray(Request $request): array
    {
        /** @var Target $target */
        $target = $this->resource;
        $targets = app(Targets::class);
        $subject = self::$batch['subjects'][$target->subject_id] ?? $this->subject($target);

        return [
            'id' => $target->id,
            'company' => Refs::named($target->company),
            'branch' => Refs::named($target->branch),
            'subject_type' => $target->subject_type,
            'subject' => $subject,
            'metric' => $target->metric,
            'period_start' => Refs::date($target->period_start),
            'period_end' => Refs::date($target->period_end),
            'currency' => $target->currency,
            'amount_minor' => (string) $target->amount_minor,
            ...$targets->actual($target, $request->user(), self::$batch['members'] ?? []),
            'archived_at' => Refs::at($target->archived_at),
            'can' => ['manage' => $request->user() !== null && $targets->mayManage($request->user(), $target)],
        ];
    }

    /** @return array{id: string, name: string}|null */
    private function subject(Target $target): ?array
    {
        $subject = match ($target->subject_type) {
            'user' => User::query()->find($target->subject_id),
            'team' => Team::query()->find($target->subject_id),
            default => Branch::query()->find($target->subject_id),
        };

        return $subject === null ? null : ['id' => (string) $subject->getKey(), 'name' => (string) $subject->getAttribute('name')];
    }
}
