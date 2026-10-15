<?php

namespace Modules\CRM\Activities;

use App\Core\Branding\BrandingSettings;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;
use Modules\CRM\Models\Activity;

/**
 * CRM-29: one logged email, sent by the core mailer in its tenant's
 * context (the tenant's verified sender when set, BR-06; plain text and
 * black-on-white HTML, NotificationMail). Encrypted on the queue (it
 * carries the address and the text). The activity records `sent`.
 */
class SendCrmEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $tenantId,
        public string $activityId,
        public string $email,
        public string $subject,
        public string $body,
        public string $locale,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(BrandingSettings $branding): void
    {
        Mail::to($this->email)->send(new NotificationMail($this->subject, $this->body, null, $this->locale, [], $branding->sender()));

        $activity = Activity::query()->find($this->activityId);

        if ($activity !== null) {
            $activity->forceFill(['email' => [...(array) $activity->email, 'sent' => 'sent']])->save();
        }
    }
}
