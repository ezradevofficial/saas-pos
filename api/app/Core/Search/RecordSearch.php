<?php

namespace App\Core\Search;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LAY-01, RBAC-04, RBAC-08: the record search behind the quick search. Modules
 * register a RecordSearcher each; a search asks only the searchers whose
 * module is active for the tenant and whose view permission the user holds,
 * hands each the places the user holds it at, and groups the hits by type.
 * Everything runs on the tenant connection, so row-level security applies.
 *
 * The whole search has a hard time budget: each query is cancelled by the
 * database when the budget is spent, a searcher that fails or times out is
 * skipped (the answer says it is partial), and no further searcher starts
 * after the budget.
 */
class RecordSearch
{
    /** The hard total timeout, in milliseconds (config `search.budget_ms`). */
    public const BUDGET_MS = 2000;

    /** @var array<string, RecordSearcher> */
    private array $searchers = [];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ScopeResolver $resolver,
        private readonly FieldRules $fieldRules,
    ) {}

    public function register(RecordSearcher $searcher): void
    {
        $this->searchers[$searcher->type()] = $searcher;
    }

    /** @return list<string> the registered result types */
    public function types(): array
    {
        return array_keys($this->searchers);
    }

    /**
     * @return array{groups: list<array{type: string, results: list<RecordHit>}>, partial: bool}
     */
    public function search(User $user, SearchTerm $term): array
    {
        $started = hrtime(true);
        $budget = (int) config('search.budget_ms', self::BUDGET_MS);
        $groups = [];
        $partial = false;

        DB::connection(TenantContext::CONNECTION)->transaction(function ($connection) use ($user, $term, $started, $budget, &$groups, &$partial) {
            // Local to this transaction (put back afterwards, as a caller's own transaction may outlive it).
            $previous = (string) ($connection->selectOne('show statement_timeout')->statement_timeout ?? '0');
            $connection->statement('set local statement_timeout = '.$budget);

            try {
                foreach ($this->searchers as $searcher) {
                    // RBAC-08: an inactive module's records are never searched; RBAC-01: nor without the view permission.
                    if (! $this->modules->isActive($searcher->module()) || ! $this->resolver->can($user, $searcher->permission())) {
                        continue;
                    }

                    if ((hrtime(true) - $started) / 1_000_000 >= $budget) {
                        $partial = true;

                        continue;
                    }

                    try {
                        // A savepoint, so one failed or cancelled query does not poison the rest.
                        $hits = $connection->transaction(fn () => $searcher->search(
                            $term,
                            new SearchContext($user, $this->resolver->visibleIds($user, $searcher->permission()), $this->fieldRules),
                        ));
                    } catch (Throwable $e) {
                        report($e);
                        $partial = true;

                        continue;
                    }

                    if ($hits !== []) {
                        $groups[] = ['type' => $searcher->type(), 'results' => array_slice(array_values($hits), 0, $term->limit)];
                    }
                }

            } finally {
                $connection->statement("set local statement_timeout = '".$previous."'");
            }
        });

        return ['groups' => $groups, 'partial' => $partial];
    }
}
