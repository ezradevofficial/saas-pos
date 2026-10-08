<?php

namespace App\Core\Audit;

use App\Core\Identity\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Who, where and on whose behalf the current request acts (AUD-02).
 * Request-scoped: auth and device middleware call the setters; anything not
 * set falls back to the current request (IP, user agent) and the
 * authenticated user.
 */
class AuditContext
{
    private ?string $userId = null;

    private ?string $onBehalfOfUserId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    private ?string $deviceId = null;

    private ?string $locationId = null;

    private DateTimeInterface|string|null $deviceTime = null;

    /** @var array<string, mixed> recorded with each entry under `after.metadata` (e.g. the automation rule and run) */
    private array $metadata = [];

    /** Forget everything set so far (a new request starts empty). */
    public function reset(): void
    {
        $this->userId = $this->onBehalfOfUserId = $this->ip = $this->userAgent = null;
        $this->deviceId = $this->locationId = null;
        $this->deviceTime = null;
        $this->metadata = [];
    }

    /**
     * M6 (AUD-02): run $fn with $userId as the acting user and $metadata
     * recorded with every entry, then restore what was set before (an
     * automation rule acting as its user, with the rule and run ids).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function actingAs(?string $userId, array $metadata, callable $fn): mixed
    {
        [$userId, $this->userId] = [$this->userId, $userId];
        [$metadata, $this->metadata] = [$this->metadata, $metadata];

        try {
            return $fn();
        } finally {
            $this->userId = $userId;
            $this->metadata = $metadata;
        }
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function setUserId(?string $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function setOnBehalfOfUserId(?string $userId): static
    {
        $this->onBehalfOfUserId = $userId;

        return $this;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function setDeviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function setLocationId(?string $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    /** The device clock for offline actions (AUD-02). */
    public function setDeviceTime(DateTimeInterface|string|null $deviceTime): static
    {
        $this->deviceTime = $deviceTime;

        return $this;
    }

    public function userId(): ?string
    {
        if ($this->userId !== null) {
            return $this->userId;
        }

        // Only people are users; a POS device is recorded as the device (AUD-02).
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        // Only UUID keys fit the audit columns.
        $id = $user->getAuthIdentifier();

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    public function onBehalfOfUserId(): ?string
    {
        return $this->onBehalfOfUserId;
    }

    public function ip(): ?string
    {
        return $this->ip ?? $this->request()?->ip();
    }

    public function userAgent(): ?string
    {
        return $this->userAgent ?? $this->request()?->userAgent();
    }

    public function deviceId(): ?string
    {
        return $this->deviceId;
    }

    public function locationId(): ?string
    {
        return $this->locationId;
    }

    public function deviceTime(): DateTimeInterface|string|null
    {
        return $this->deviceTime;
    }

    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
