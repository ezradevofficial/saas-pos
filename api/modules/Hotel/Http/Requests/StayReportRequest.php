<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Http\Lists\StayList;
use Modules\Hotel\Support\Access;

/**
 * GET hotel/reports/arrivals, /departures, /in-house, /no-shows (HOT-31): `hotel.report.view` at the reader's branches. `?branch=`, `?date=`
 * (arrivals, departures and no-shows: that day, the property's business date by default; in-house: those staying that night), `?sort=`, pages and an export.
 */
class StayReportRequest extends HotelRequest
{
    use SortsAndExports;

    public const KINDS = ['arrivals', 'departures', 'in-house', 'no-shows'];

    protected function permission(): string
    {
        return 'hotel.report.view';
    }

    public function list(): StayList
    {
        return new StayList;
    }

    public function kind(): string
    {
        return (string) $this->route('report');
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'branch' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'date' => HotelRules::day(false),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->require($this->user(), 'hotel.report.view', (string) $this->input('branch'));
            }
        }];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
