<?php

namespace App\Core\Identity\Pin\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Pin\Http\Requests\ChangePinRequest;
use App\Core\Identity\Pin\Http\Requests\OverrideRequest;
use App\Core\Identity\Pin\Http\Requests\ReportPinAttemptsRequest;
use App\Core\Identity\Pin\Http\Requests\VerifyPinRequest;
use App\Core\Identity\Pin\OverrideTokens;
use App\Core\Identity\Pin\Pins;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\StaffDirectory;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-06..AUTH-08: the device side of PINs.
 *
 * POST pos/pin/verify: a staff sign-in checked online (lockout after 5
 * wrong attempts per user and device). Only staff of the device's
 * location (StaffDirectory) can sign in there.
 * POST pos/pin/attempts: wrong attempts the device counted offline, for
 * staff of its location only.
 * POST pos/pin/change: a new PIN chosen at the till (clears `must_change`).
 * POST pos/override: a manager's PIN entered on the cashier's device,
 * answered with a signed short-lived override token when the manager holds
 * the permission at the device's location.
 */
class DevicePinController
{
    public function __construct(
        private readonly Pins $pins,
        private readonly StaffDirectory $staff,
    ) {}

    public function verify(VerifyPinRequest $request): JsonResponse
    {
        $device = $request->device();
        $user = $this->staffMember($device, (string) $request->validated('user_id'));
        $this->pins->verify($device, $user, $request->secret(), $request->kind());

        return response()->json(['data' => [
            'user_id' => $user->id,
            'name' => $user->name,
            'verified_at' => CarbonImmutable::now()->toIso8601String(),
            'must_change' => $this->pins->status($user)['must_change'],
        ]]);
    }

    /** AUTH-06: a new PIN chosen at the till, after the current one is checked (with lockout). */
    public function change(ChangePinRequest $request): JsonResponse
    {
        $device = $request->device();
        $user = $this->staffMember($device, (string) $request->validated('user_id'));
        $this->pins->verify($device, $user, (string) $request->validated('pin'));
        $this->pins->set($user, (string) $request->validated('new_pin'), null);

        return response()->json(['message' => __('auth.pin.saved'), 'data' => $this->pins->status($user)]);
    }

    public function attempts(ReportPinAttemptsRequest $request): JsonResponse
    {
        $device = $request->device();
        $states = [];

        foreach ($request->validated('reports') as $report) {
            // Only staff of this device's location can have tried a PIN here. Someone who
            // left the location since is skipped, not a reason to refuse the batch: the
            // device drops that report.
            if ($this->staff->at(DeviceScope::of($device), $report['user_id']) === []) {
                $states[] = ['user_id' => $report['user_id'], 'skipped' => 'not_staff_here'];

                continue;
            }

            $states[] = $this->pins->report(
                $device,
                $report['user_id'],
                (int) $report['failed_attempts'],
                (bool) $report['locked'],
                isset($report['occurred_at']) ? CarbonImmutable::parse($report['occurred_at']) : null,
            );
        }

        return response()->json(['data' => $states]);
    }

    public function override(OverrideRequest $request, OverrideTokens $tokens, ScopeResolver $resolver): JsonResponse
    {
        $device = $request->device();
        $manager = $this->staffMember($device, (string) $request->validated('manager_user_id'));
        $this->pins->verify($device, $manager, $request->secret(), $request->kind());

        $permission = (string) $request->validated('permission');

        if (! $resolver->can($manager, $permission, Scope::location($device->location_id))) {
            throw new ApiException(403, 'override_not_permitted', __('auth.override.not_permitted'));
        }

        $issued = $tokens->issue($device, $manager, $request->validated('cashier_user_id'), $permission, (string) $request->validated('reference'));

        return response()->json(['data' => [
            'token' => $issued['token'],
            'override_id' => $issued['override_id'],
            'expires_at' => $issued['expires_at'],
            'manager_user_id' => $manager->id,
            'permission' => $permission,
        ]]);
    }

    /** A user who works this device's location (422 `not_staff_here` otherwise). */
    private function staffMember(Device $device, string $userId): User
    {
        if ($this->staff->at(DeviceScope::of($device), $userId) === []) {
            throw new ApiException(422, 'not_staff_here', __('auth.pin.not_staff_here'));
        }

        return User::query()->findOrFail($userId);
    }
}
