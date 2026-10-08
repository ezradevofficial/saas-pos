<?php

namespace App\Core\MasterData\Taxes\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\MasterData\Taxes\Http\Requests\ApplyCountryPackRequest;
use App\Core\MasterData\Taxes\Http\Requests\ListTaxCodesRequest;
use App\Core\MasterData\Taxes\Http\Requests\StoreTaxCodeRequest;
use App\Core\MasterData\Taxes\Http\Requests\StoreTaxRateRequest;
use App\Core\MasterData\Taxes\Http\Requests\TaxCodeActionRequest;
use App\Core\MasterData\Taxes\Http\Requests\TaxCodeRequest;
use App\Core\MasterData\Taxes\Http\Requests\UpdateTaxCodeRequest;
use App\Core\MasterData\Taxes\Http\Resources\TaxCodeResource;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\MasterData\Taxes\TaxRates;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-03, CP-01, CP-02: a company's tax codes, their effective-dated rates,
 * and copying the country pack's codes. Archived, never deleted (TEN-06).
 */
class TaxCodeController
{
    public function __construct(
        private readonly Archiver $archiver,
        private readonly TaxRates $rates,
        private readonly ApplyCountryPack $packs,
        private readonly Auditor $auditor,
    ) {}

    public function index(ListTaxCodesRequest $request, Company $company): AnonymousResourceCollection
    {
        $query = TaxCode::query()->where('company_id', $company->id)->with(['rates', 'company:id,timezone']);

        return TaxCodeResource::collection(
            $request->applyStatus($query)->orderBy('code')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreTaxCodeRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        try {
            $code = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data) {
                $this->archiver->lockActive(Company::class, $company->id);

                $code = TaxCode::create([
                    'company_id' => $company->id,
                    'code' => $data['code'],
                    'name_en' => $data['name_en'],
                    'name_fr' => $data['name_fr'],
                    'kind' => $data['kind'],
                    'fiscal_code' => $data['fiscal_code'] ?? null,
                ]);

                if (! $code->isExempt()) {
                    $rate = $data['kind'] === 'zero_rated' ? '0' : ($data['rate'] ?? null);

                    TaxRate::create([
                        'tax_code_id' => $code->id,
                        'rate' => $rate === null ? null : TaxCode::normaliseRate($rate),
                        'effective_from' => $data['effective_from'],
                        'needs_confirmation' => $rate === null,
                        'source' => TaxRate::SOURCE_TENANT,
                    ]);
                }

                return $code;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->codeTaken();
        }

        return $this->respond($code, 201);
    }

    public function show(TaxCodeRequest $request, TaxCode $taxCode): JsonResponse
    {
        return $this->respond($taxCode);
    }

    public function update(UpdateTaxCodeRequest $request, TaxCode $taxCode): JsonResponse
    {
        try {
            $taxCode->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->codeTaken();
        }

        return $this->respond($taxCode);
    }

    /** CP-02: a new rate from a date; the previous rate ends the day before. */
    public function storeRate(StoreTaxRateRequest $request, TaxCode $taxCode): JsonResponse
    {
        $this->rates->add($taxCode, $request->validated('rate'), CarbonImmutable::parse($request->validated('effective_from')));

        return $this->respond($taxCode->refresh(), 201);
    }

    /** CP-01: copy the codes of the company's country pack it does not have yet; never overwrites. */
    public function applyPack(ApplyCountryPackRequest $request, Company $company): JsonResponse
    {
        $result = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company) {
            $locked = $this->archiver->lockActive(Company::class, $company->id);
            $result = $this->packs->apply($locked);

            if ($result['pack'] === null) {
                throw new ApiException(422, 'country_pack_missing', __('core.tax.pack_missing', ['country' => $company->country]));
            }

            if ($result['added'] !== []) {
                $this->auditor->record('core.tax_code.apply_pack', $locked, null, [
                    'pack' => $result['pack']->code,
                    'version' => $result['pack']->version,
                    'added' => $result['added'],
                ]);
            }

            return $result;
        });

        return response()->json(['data' => [
            'pack' => $result['pack']->code,
            'version' => $result['pack']->version,
            'added' => $result['added'],
            'skipped' => $result['skipped'],
        ]]);
    }

    public function archive(TaxCodeActionRequest $request, TaxCode $taxCode): JsonResponse
    {
        if (! $taxCode->isArchived()) {
            $taxCode->archive();
        }

        return $this->respond($taxCode);
    }

    public function restore(TaxCodeActionRequest $request, TaxCode $taxCode): JsonResponse
    {
        if ($taxCode->isArchived()) {
            try {
                DB::connection(TenantContext::CONNECTION)->transaction(fn () => $taxCode->restore());
            } catch (UniqueConstraintViolationException) {
                $taxCode->archived_at = $taxCode->getOriginal('archived_at');

                throw $this->codeTaken();
            }
        }

        return $this->respond($taxCode);
    }

    private function respond(TaxCode $code, int $status = 200): JsonResponse
    {
        return TaxCodeResource::make($code->load(['rates', 'company:id,timezone']))->response()->setStatusCode($status);
    }

    private function codeTaken(): ValidationException
    {
        return ValidationException::withMessages(['code' => __('core.tax.code_taken')]);
    }
}
