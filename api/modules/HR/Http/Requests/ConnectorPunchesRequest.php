<?php

namespace Modules\HR\Http\Requests;

/**
 * POST hr/connector/devices/{hr_attendance_device}/punches (HR-24,
 * MKT-04): a biometric device's punches sent by a connector, as the device
 * sends them itself (DevicePunchesRequest): up to 500, each with the
 * device's own event id (`external_id`, the key a resend is matched on),
 * the employee's enrolment reference (`ref`), `in`, `out` or `auto`, and
 * the instant.
 */
class ConnectorPunchesRequest extends DevicePunchesRequest {}
