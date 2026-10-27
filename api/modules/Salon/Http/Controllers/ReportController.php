<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ReportRequest;
use Modules\Salon\Reports\Reports;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Places;

/** SAL-27: utilisation, revenue per service, no-shows, commissions and package liability. */
class ReportController
{
    public function __construct(private readonly Reports $reports, private readonly Places $places) {}

    public function utilisation(ReportRequest $request): JsonResponse
    {
        [$company, $branches] = $this->place($request);
        [$from, $to] = $this->period($request, $company);

        return response()->json(['data' => $this->reports->utilisation($company, $branches, $from, $to)]);
    }

    public function services(ReportRequest $request): JsonResponse
    {
        [$company, $branches] = $this->place($request);
        [$from, $to] = $this->period($request, $company);

        return response()->json(['data' => $this->reports->services($company, $branches, $from, $to)]);
    }

    public function noShows(ReportRequest $request): JsonResponse
    {
        [$company, $branches] = $this->place($request);
        [$from, $to] = $this->period($request, $company);

        return response()->json(['data' => $this->reports->noShows($company, $branches, $from, $to)]);
    }

    public function commissions(ReportRequest $request): JsonResponse
    {
        [$company] = $this->place($request);
        [$from, $to] = $this->period($request, $company);

        return response()->json(['data' => $this->reports->commissions($company, $from, $to)]);
    }

    public function liability(ReportRequest $request): JsonResponse
    {
        [$company] = $this->place($request);

        return response()->json(['data' => $this->reports->liability($company)]);
    }

    /**
     * The company asked for (or the branch's) and the branches of it the reader may report on.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function place(ReportRequest $request): array
    {
        if ($request->filled('branch_id')) {
            $branch = $this->places->openBranch((string) $request->input('branch_id'));

            return [$branch->company_id, [$branch->id]];
        }

        $company = (string) ($request->input('company_id') ?: throw Errors::make(422, 'company_required', field: 'company_id'));
        $branches = Branch::query()->where('company_id', $company)->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (Branch $b) => $this->places->at($request->user(), 'salon.report.view', Scope::branch($b->id)))->pluck('id')->all();

        if ($branches === []) {
            throw Errors::make(403, 'not_permitted');
        }

        return [$company, array_values($branches)];
    }

    /** @return array{0: string, 1: string} local days, inclusive; the last 30 days by default */
    private function period(ReportRequest $request, string $companyId): array
    {
        $zone = (string) (Company::query()->whereKey($companyId)->value('timezone') ?: 'UTC');
        $to = (string) ($request->input('to') ?: CarbonImmutable::now($zone)->toDateString());
        $from = (string) ($request->input('from') ?: CarbonImmutable::parse($to)->subDays(29)->toDateString());

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw Errors::make(422, 'period_too_long', field: 'from');
        }

        return [$from, $to];
    }
}
