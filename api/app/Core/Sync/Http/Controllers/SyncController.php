<?php

namespace App\Core\Sync\Http\Controllers;

use App\Core\Identity\Pin\Pins;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Sync\DeviceSyncStatus;
use App\Core\Sync\Http\Requests\ActivateDeviceSecretRequest;
use App\Core\Sync\Http\Requests\BootstrapRequest;
use App\Core\Sync\Http\Requests\DeviceSecretChallengeRequest;
use App\Core\Sync\Http\Requests\PullRequest;
use App\Core\Sync\Http\Requests\RotateDeviceSecretRequest;
use App\Core\Sync\Sources\SettingsSource;
use App\Core\Sync\SyncPuller;
use App\Core\Sync\SyncSources;
use App\Core\Tenancy\Http\Resources\DeviceResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * NFR-04, ADR 004: the device side of master data sync.
 *
 * GET sync/bootstrap: what a new install needs to start: the server time
 * (clock-skew check), the device and where it sits, the entities to pull
 * in order (key, mode, module, version) and the page sizes.
 * GET sync/pull: changes per entity since the device's cursors
 * (SyncPuller). Device secret rotation in three steps (DeviceSecrets):
 * GET sync/device-secret/challenge, POST sync/device-secret/rotate,
 * POST sync/device-secret/activate.
 */
class SyncController
{
    public function __construct(
        private readonly SyncSources $sources,
        private readonly SyncPuller $puller,
        private readonly DeviceSyncStatus $status,
    ) {}

    public function bootstrap(BootstrapRequest $request, SettingsSource $settings, DeviceSecrets $secrets): JsonResponse
    {
        $device = $request->device();
        $scope = DeviceScope::of($device);
        $this->status->recordBootstrap($device);

        $entities = [];

        foreach ($this->sources->available() as $key => $source) {
            $entities[] = ['key' => $key, 'mode' => $this->sources->mode($source), 'module' => $source->module(), 'version' => $source->version()];
        }

        return response()->json([
            'server_time' => self::now(),
            'device' => DeviceResource::make($device)->resolve($request),
            // The kid of the device's current secret (null: unpair and pair again).
            'device_secret_kid' => $secrets->current($device)?->kid,
            'settings' => $settings->rows($scope)[0],
            'entities' => $entities,
            'page_size' => (int) config('sync.page_size'),
            'max_page_size' => (int) config('sync.max_page_size'),
            'pin' => ['scheme' => Pins::SCHEME, 'max_attempts' => Pins::maxAttempts()],
        ]);
    }

    public function pull(PullRequest $request): JsonResponse
    {
        $device = $request->device();
        $entities = $this->puller->pull(DeviceScope::of($device), $request->entities(), $request->cursors(), $request->limit());
        $this->status->recordPull($device);

        return response()->json(['server_time' => self::now(), 'entities' => (object) $entities]);
    }

    public function secretChallenge(DeviceSecretChallengeRequest $request, DeviceSecrets $secrets): JsonResponse
    {
        return response()->json(['nonce' => $secrets->challenge($request->device()), 'expires_in' => DeviceSecrets::CHALLENGE_SECONDS]);
    }

    /** Step 2: a pending secret. The current one stays current until activate(). */
    public function rotateSecret(RotateDeviceSecretRequest $request, DeviceSecrets $secrets): JsonResponse
    {
        $pending = $secrets->rotate($request->device(), (string) $request->validated('kid'), (string) $request->validated('nonce'), (string) $request->validated('proof'));

        return response()->json(['device_secret' => $pending['secret'], 'kid' => $pending['kid'], 'status' => 'pending']);
    }

    public function activateSecret(ActivateDeviceSecretRequest $request, DeviceSecrets $secrets): JsonResponse
    {
        $secret = $secrets->activate($request->device(), (string) $request->validated('kid'), (string) $request->validated('proof'));

        return response()->json(['kid' => $secret->kid, 'status' => 'current']);
    }

    private static function now(): string
    {
        return CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
