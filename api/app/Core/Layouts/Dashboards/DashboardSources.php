<?php

namespace App\Core\Layouts\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * LAY-01: the registry of dashboard data sources. Modules register theirs
 * in their provider's boot(); a source whose module is not active for the
 * tenant is not found (RBAC-08), so its widgets are skipped (LAY-07).
 */
class DashboardSources
{
    /** @var array<string, DashboardSource> */
    private array $sources = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(DashboardSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    public function find(string $key): ?DashboardSource
    {
        $source = $this->sources[$key] ?? null;

        return $source !== null && $this->modules->isActive($source->module()) ? $source : null;
    }

    /** @return list<DashboardSource> active sources $user may read, by key */
    public function availableTo(User $user): array
    {
        $sources = array_filter(array_keys($this->sources), fn (string $key) => ($source = $this->find($key)) !== null && $source->available($user));
        sort($sources);

        return array_map(fn (string $key) => $this->sources[$key], $sources);
    }

    /**
     * The parameters' problems (field => first message), empty when valid.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function paramProblems(DashboardSource $source, array $params): array
    {
        $validator = Validator::make($params, $source->rules());

        return $validator->fails() ? array_map(fn (array $messages) => $messages[0], $validator->errors()->toArray()) : [];
    }
}
