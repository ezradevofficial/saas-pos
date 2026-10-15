<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Payroll\Http\Requests\ReturnRequest;
use Modules\Payroll\Http\Resources\ReturnResource;
use Modules\Payroll\Models\StatutoryReturn;
use Modules\Payroll\Returns\Returns;
use Modules\Payroll\Support\Access;

/** PAY-21: statutory returns of the reader's companies. */
class ReturnController
{
    public function __construct(private readonly Returns $returns) {}

    public function types(ReturnRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->returns->types(Company::query()->findOrFail($request->validated('company')))]);
    }

    public function index(ReturnRequest $request): AnonymousResourceCollection
    {
        $query = StatutoryReturn::query();
        $visible = app(Access::class)->visible($request->user(), ReturnRequest::VIEW);

        if (! $visible->all) {
            $query->whereIn('company_id', $visible->companyIds);
        }

        foreach (['company' => 'company_id', 'type' => 'type'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        return ReturnResource::collection($query->orderByDesc('period_start')->orderByDesc('created_at')->paginate($request->perPage())->withQueryString());
    }

    public function store(ReturnRequest $request): JsonResponse
    {
        $return = $this->returns->generate(Company::query()->findOrFail($request->validated('company_id')), $request->validated('type'), $request->validated('period'), $request->user());

        return ReturnResource::make($return)->response()->setStatusCode(201);
    }

    public function file(ReturnRequest $request, StatutoryReturn $payrollReturn): Response
    {
        $file = $this->returns->file($payrollReturn, $request->user());

        return new Response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Needs-Confirmation' => $payrollReturn->needs_confirmation ? '1' : '0',
        ]);
    }
}
