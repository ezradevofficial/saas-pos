<?php

namespace App\Core\Onboarding\Checklist;

use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * ONB-07: the getting-started checklist, driven by real state. Core
 * registers its items (company, tax ID, an item, a paired till) and
 * modules theirs (POS: the first sale), each a check over the module's
 * own tables, shown only while the module is active (RBAC-08).
 *
 * The checklist shows until the first sale (an item marked `ends`) or
 * until everything is done, unless the owner dismissed it.
 */
class Checklist
{
    /** @var array<string, array{key: string, module: string, check: Closure(OnboardingState): bool, ends: bool, sort: int}> */
    private array $items = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  Closure(OnboardingState): bool  $check  true once done (runs in the tenant's context)
     * @param  bool  $ends  the checklist goes away once this one is done (the first sale)
     */
    public function register(string $key, string $module, Closure $check, int $sort, bool $ends = false): void
    {
        $this->items[$key] = ['key' => $key, 'module' => $module, 'check' => $check, 'ends' => $ends, 'sort' => $sort];
    }

    /** @return array{visible: bool, dismissed: bool, done: int, total: int, items: list<array{key: string, module: string, done: bool, video_url: ?string}>} */
    public function state(): array
    {
        $state = OnboardingState::current();
        $items = collect($this->items)
            ->filter(fn (array $item) => $this->modules->isActive($item['module']))
            ->sortBy('sort')
            ->map(fn (array $item) => [...$item, 'done' => (bool) ($item['check'])($state)])
            ->values();

        $done = $items->where('done', true)->count();
        $ended = $items->contains(fn (array $item) => $item['ends'] && $item['done']);
        $dismissed = $state->checklist_dismissed_at !== null;

        return [
            'visible' => ! $dismissed && ! $ended && $done < $items->count(),
            'dismissed' => $dismissed,
            'done' => $done,
            'total' => $items->count(),
            'items' => $items->map(fn (array $item) => [
                'key' => $item['key'],
                'module' => $item['module'],
                'done' => $item['done'],
                'video_url' => config("onboarding.help_videos.{$item['key']}"),
            ])->all(),
        ];
    }

    public function dismiss(): OnboardingState
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () {
            $state = OnboardingState::locked();

            if ($state->checklist_dismissed_at === null) {
                $state->checklist_dismissed_at = now();
                $state->save();
            }

            return $state;
        });
    }
}
