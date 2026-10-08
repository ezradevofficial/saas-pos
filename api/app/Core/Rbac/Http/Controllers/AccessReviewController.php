<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\SpreadsheetCell;
use App\Core\Rbac\Http\Requests\AccessReviewRequest;
use App\Core\Rbac\Http\Resources\AccessReviewResource;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RBAC-11: who holds which (active) role where, granted by whom and when,
 * within the actor's scope. JSON is paginated; `?format=csv` streams every
 * row and is audited as `core.access_review.export`.
 */
class AccessReviewController
{
    public const COLUMNS = ['user_name', 'user_contact', 'user_status', 'role', 'scope_type', 'scope_name', 'granted_by', 'granted_at'];

    private const CHUNK = 500;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly ScopeNames $scopeNames,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function __invoke(AccessReviewRequest $request): AnonymousResourceCollection|StreamedResponse
    {
        $visible = $this->resolver->visibleIds($request->user(), $request->permission());

        if ($request->wantsCsv()) {
            return $this->csv($visible);
        }

        $page = $this->query($visible)->paginate($request->perPage())->withQueryString();
        $this->scopeNames->attach($page->getCollection());

        return AccessReviewResource::collection($page);
    }

    private function query(VisibleScope $visible): Builder
    {
        $query = RoleAssignment::query()
            ->select('role_assignments.*')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->whereNull('roles.archived_at')
            ->with(['user', 'role', 'creator'])
            ->orderBy('users.name')->orderBy('users.id')->orderBy('roles.name')->orderBy('role_assignments.id');

        return ScopeNames::constrain($query, $visible);
    }

    private function csv(VisibleScope $visible): StreamedResponse
    {
        $tenantId = $this->tenants->require();
        $this->auditor->record('core.access_review.export', null, null, [
            'rows' => (clone $this->query($visible))->toBase()->getCountForPagination(),
            'format' => 'csv',
        ]);

        $filename = 'access-review-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($visible, $tenantId) {
            // The response is sent after the request's tenant context may be
            // gone: read the rows in the tenant's own context (RLS).
            $this->tenants->run($tenantId, function () use ($visible) {
                $out = fopen('php://output', 'w');
                fputcsv($out, self::COLUMNS, escape: '');

                $this->query($visible)->chunk(self::CHUNK, function ($rows) use ($out) {
                    $this->scopeNames->attach($rows);

                    foreach ($rows as $a) {
                        fputcsv($out, array_map(SpreadsheetCell::safe(...), [
                            $a->user->name,
                            $a->user->email ?? $a->user->phone,
                            $a->user->status,
                            $a->role->name,
                            $a->scope_type,
                            $a->scopeName,
                            $a->creator?->name,
                            $a->created_at?->toIso8601String(),
                        ]), escape: '');
                    }
                });

                fclose($out);
            });
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
