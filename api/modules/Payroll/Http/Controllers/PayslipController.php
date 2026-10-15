<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\DocumentTemplates\DocumentOutput;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Payroll\Http\Requests\PayslipRequest;
use Modules\Payroll\Http\Resources\PayslipResource;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Payslips\Payslips;
use Modules\Payroll\Payslips\PayslipSource;

/**
 * PAY-19, PAY-24: payslips for payroll readers (`payroll/payslips`) and
 * for the employee themself (`payroll/me/payslips`, "My payslips"). Every
 * view and download is audited.
 */
class PayslipController
{
    public function __construct(private readonly Payslips $payslips) {}

    public function index(PayslipRequest $request): AnonymousResourceCollection
    {
        $query = Payslip::query()->with(['run', 'line']);

        if ($request->own()) {
            $query->whereIn('employee_id', $this->payslips->ownEmployeeIds($request->user()));
        } else {
            $this->payslips->limit($query, $request->user());

            foreach (['run' => 'run_id', 'employee' => 'payroll_employee_id'] as $filter => $column) {
                if ($request->filled($filter)) {
                    $query->where($column, $request->validated($filter));
                }
            }

            $search = trim((string) $request->validated('search', ''));

            if ($search !== '') {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->whereIn('run_line_id', fn ($q) => $q->select('id')->from('payroll_run_lines')->where(fn ($w) => $w->where('number', 'ilike', $like)->orWhere('name', 'ilike', $like)));
            }
        }

        return PayslipResource::collection($query->orderByDesc('issued_at')->orderBy('number')->paginate($request->perPage())->withQueryString());
    }

    public function show(PayslipRequest $request, Payslip $payrollPayslip): PayslipResource
    {
        $this->payslips->recordView($payrollPayslip, 'view');

        return PayslipResource::make($payrollPayslip->load(['run', 'line']))->detail($request->own());
    }

    public function pdf(PayslipRequest $request, Payslip $payrollPayslip, DocumentOutput $output): Response
    {
        $document = (new PayslipSource)->load($payrollPayslip->id) ?? abort(404);
        $this->payslips->recordView($payrollPayslip, 'download');

        return new Response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->fileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
