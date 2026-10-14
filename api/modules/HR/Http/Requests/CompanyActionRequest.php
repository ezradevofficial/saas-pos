<?php

namespace Modules\HR\Http\Requests;

/**
 * POST hr/leave-types/apply-pack and hr/leave-accruals/run (HR-12, HR-13),
 * POST hr/timesheets/recompute (HR-25): an action on a company's data,
 * `hr.leave.manage` / `hr.attendance.manage` at the company.
 */
abstract class CompanyActionRequest extends SetupStoreRequest {}
