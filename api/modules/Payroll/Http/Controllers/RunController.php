<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Payroll\Http\Lists\RunList;
use Modules\Payroll\Http\Requests\ListRunsRequest;
use Modules\Payroll\Http\Requests\RunLinesRequest;
use Modules\Payroll\Http\Requests\RunRequest;
use Modules\Payroll\Http\Requests\StoreRunRequest;
use Modules\Payroll\Http\Requests\ViewRequest;
use Modules\Payroll\Http\Resources\RunLineResource;
use Modules\Payroll\Http\Resources\RunResource;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunLine;
use Modules\Payroll\Payments\PaymentFiles;
use Modules\Payroll\Payments\PaymentFormats;
use Modules\Payroll\Runs\Runs;
use Modules\Payroll\Support\PayFields;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PAY-14 to PAY-20: runs, their lines and their payment files, at the reader's companies. */
class RunController
{
    public function __construct(private readonly Runs $runs) {}

    public function index(ListRunsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Run::query()->with(RunList::RELATIONS);
        $request->applyFilters($query, 'companies');
        $request->applySearch($query, ['number' => 'number']);

        foreach (['pay_group' => 'pay_group_id', 'kind' => 'kind'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return RunResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreRunRequest $request): JsonResponse
    {
        $run = $this->runs->create($request->validated(), $request->user());

        return RunResource::make($run->load(RunList::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(RunRequest $request, Run $payrollRun): RunResource
    {
        return RunResource::make($payrollRun->load(RunList::RELATIONS));
    }

    /** PAY-15, PAY-23: the lines with their variances; reading pay is audited once per run and request. */
    public function lines(RunLinesRequest $request, Run $payrollRun, ListExport $export, PayFields $fields): AnonymousResourceCollection|StreamedResponse
    {
        $query = RunLine::query()->where('run_id', $payrollRun->id)->with('department');
        $request->applySearch($query, ['number' => 'number', 'name' => 'name']);

        if ($request->boolean('errors')) {
            $query->where('errors', '<>', '[]');
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        if (! $fields->hides(PayFields::AMOUNTS)) {
            $fields->recordView($payrollRun, 'run_lines');
        }

        return RunLineResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function calculate(RunRequest $request, Run $payrollRun): RunResource
    {
        return RunResource::make($this->runs->calculate($payrollRun, $request->user())->load(RunList::RELATIONS));
    }

    public function submit(RunRequest $request, Run $payrollRun): RunResource
    {
        return RunResource::make($this->runs->submit($payrollRun, $request->user())->load(RunList::RELATIONS));
    }

    public function cancel(RunRequest $request, Run $payrollRun): RunResource
    {
        return RunResource::make($this->runs->cancel($payrollRun, $request->user(), $request->validated('reason'))->load(RunList::RELATIONS));
    }

    public function post(RunRequest $request, Run $payrollRun): RunResource
    {
        return RunResource::make($this->runs->post($payrollRun, $request->user())->load(RunList::RELATIONS));
    }

    public function correction(RunRequest $request, Run $payrollRun): JsonResponse
    {
        $run = $this->runs->correction($payrollRun, $request->validated(), $request->user());

        return RunResource::make($run->load(RunList::RELATIONS))->response()->setStatusCode(201);
    }

    /** PAY-20: the payment file, built now and never stored. */
    public function paymentFile(RunRequest $request, Run $payrollRun, PaymentFiles $files): Response
    {
        $file = $files->build($payrollRun, $request->validated('format', 'generic_csv'), $request->user());

        return new Response($file['content'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Needs-Confirmation' => $file['needs_confirmation'] ? '1' : '0',
        ]);
    }

    /** PAY-20: who a file would pay and who is left out, without account numbers. */
    public function paymentSummary(RunRequest $request, Run $payrollRun, PaymentFiles $files): JsonResponse
    {
        $summary = $files->summary($payrollRun, $request->validated('format', 'generic_csv'), $request->user());
        $summary['missing'] = array_map(fn (array $m) => [...$m, 'message' => __('payroll.payments.missing.'.$m['reason'])], $summary['missing']);

        return response()->json(['data' => $summary]);
    }

    public function formats(ViewRequest $request, PaymentFormats $formats): JsonResponse
    {
        return response()->json(['data' => array_values(array_map(fn ($f) => ['key' => $f->key(), 'name' => __($f->label()), 'methods' => $f->methods()], $formats->all()))]);
    }
}
