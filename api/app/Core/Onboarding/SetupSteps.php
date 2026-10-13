<?php

namespace App\Core\Onboarding;

use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;

/**
 * ONB-05: each setup step's status. What the owner marked (done or
 * skipped) is kept on the onboarding record; a step whose work is
 * visibly done in the data (a tax ID, a second branch, a real item, a
 * colleague, a paired till) counts as done even when nobody marked it.
 */
class SetupSteps
{
    /** @return list<array{key: string, status: string, detected: bool}> */
    public function all(OnboardingState $state): array
    {
        $marked = $state->steps ?? [];

        return array_map(function (string $step) use ($marked) {
            $detected = $this->detected($step);

            return [
                'key' => $step,
                'status' => $detected ? Onboarding::STEP_DONE : ($marked[$step] ?? Onboarding::STEP_TODO),
                'detected' => $detected,
            ];
        }, Onboarding::STEPS);
    }

    private function detected(string $step): bool
    {
        return match ($step) {
            'company' => self::firstCompany()?->tax_id !== null,
            'branches' => Branch::query()->whereNull('archived_at')->count() > 1 || Location::query()->whereNull('archived_at')->count() > 1,
            'items' => self::hasRealItem(),
            'users' => User::query()->where('is_platform_staff', false)->count() > 1
                || Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists(),
            'devices' => self::hasPairedTill(),
            default => false,
        };
    }

    public static function firstCompany(): ?Company
    {
        return Company::query()->whereNull('archived_at')->orderBy('created_at')->orderBy('id')->first();
    }

    /** An active item that is not sample data (ONB-06). */
    public static function hasRealItem(): bool
    {
        return Item::query()->whereNull('archived_at')->whereNotIn('id', SampleData::ids('item'))->exists();
    }

    public static function hasPairedTill(): bool
    {
        return Device::query()->where('status', Device::STATUS_ACTIVE)->exists();
    }
}
