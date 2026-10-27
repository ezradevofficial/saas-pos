<?php

namespace Modules\Salon\Reminders;

use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\NotificationAddress;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Reminder;
use Modules\Salon\Models\Service;
use Modules\Salon\Support\Places;

/**
 * SAL-14, NOT-01, NOT-02: what a client hears about an appointment goes through the core notifier as transactional
 * system messages to the client's contacts (never marketing: marketing consent is not asked and no offer is carried).
 * Email always; SMS where the platform can send it (in email-only mode it is skipped and the notifier falls back to
 * WhatsApp where configured, else email). A phone gets at most `salon.notifications.per_phone_per_day` messages a day.
 * A message is sent once per appointment, kind and version (a move is a new version), and a transport failure never
 * fails the booking.
 */
final class Notices
{
    public const CONFIRMED = 'salon.appointment.confirmed';

    public const REMINDER = 'salon.appointment.reminder';

    public const CANCELLED = 'salon.appointment.cancelled';

    public const TYPES = [self::CONFIRMED => 'confirmed', self::REMINDER => 'reminder', self::CANCELLED => 'cancelled'];

    public function __construct(private readonly Notifier $notifier, private readonly Places $places) {}

    public static function register(EventTypes $types): void
    {
        foreach (self::TYPES as $key => $name) {
            $types->register(new EventType(
                key: $key,
                placeholders: ['branch_name' => 'Westlands salon', 'when' => 'Sat 14 Feb, 10:30', 'services' => 'Haircut, Beard trim', 'number' => 'SAL-WL-2027-00012'],
                defaultChannels: [Channels::SMS, Channels::EMAIL],
                channels: [Channels::SMS, Channels::EMAIL, Channels::WHATSAPP],
                langKey: "salon.notifications.{$name}",
                system: true,
                contactsOnly: true,
            ));
        }
    }

    /** Sends $type about $appointment once per version; returns whether anything was handed to the notifier. */
    public function send(string $type, Appointment $appointment): bool
    {
        $live = $type === self::CANCELLED ? $appointment->status === Appointment::CANCELLED : $appointment->status === Appointment::BOOKED;

        if (! $live) {
            return false;
        }

        $dedupe = $type === self::CANCELLED ? 'x' : substr(hash('sha256', $appointment->starts_at->toIso8601String()), 0, 12);
        $row = Reminder::query()->insertOrIgnore([[
            'id' => (string) Str::uuid7(), 'tenant_id' => $appointment->tenant_id, 'appointment_id' => $appointment->id,
            'kind' => self::TYPES[$type], 'dedupe' => $dedupe, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]]);

        if ($row === 0) {
            return false;
        }

        $addresses = $this->addresses($appointment);

        if ($addresses === []) {
            return false;
        }

        try {
            $branch = Branch::query()->find($appointment->branch_id);
            $zone = $this->places->zone($appointment->branch_id);
            $services = Service::query()->whereKey(Line::query()->where('appointment_id', $appointment->id)->pluck('service_id'))->orderBy('name')->pluck('name')->implode(', ');

            $this->notifier->send(new NotificationEvent(
                $type,
                [],
                [
                    'branch_name' => $branch?->name ?? '',
                    'when' => $appointment->starts_at->setTimezone($zone)->locale($appointment->locale)->isoFormat('ddd D MMM, HH:mm'),
                    'services' => $services,
                    'number' => $appointment->number,
                ],
                null,
                $addresses,
            ));
        } catch (\Throwable $e) {
            report($e);
            // Not sent: let the next try send it.
            Reminder::query()->where('appointment_id', $appointment->id)->where('kind', self::TYPES[$type])->where('dedupe', $dedupe)->delete();

            return false;
        }

        return true;
    }

    /** @return list<NotificationAddress> */
    private function addresses(Appointment $appointment): array
    {
        $name = $appointment->guest_name ?? '';
        $phone = $appointment->guest_phone;
        $email = $appointment->guest_email;

        if ($appointment->client_id !== null) {
            $party = Party::query()->find($appointment->client_id);
            $name = $party?->name ?? $name;
            $phone ??= $party?->phoneNumbers()[0] ?? null;
            $email ??= $party?->emails[0]['address'] ?? null;
        }

        $locale = in_array($appointment->locale, Channels::LOCALES, true) ? $appointment->locale : 'en';
        $addresses = [];

        if ($phone !== null && $phone !== '' && $this->phoneAllowed((string) $appointment->tenant_id, (string) $phone)) {
            $addresses[] = new NotificationAddress(Channels::SMS, (string) $phone, $name, $locale);
        }

        if ($email !== null && $email !== '') {
            $addresses[] = new NotificationAddress(Channels::EMAIL, (string) $email, $name, $locale);
        }

        return $addresses;
    }

    private function phoneAllowed(string $tenantId, string $phone): bool
    {
        $key = 'salon-notices|'.$tenantId.'|'.hash('sha256', $phone);

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('salon.notifications.per_phone_per_day', 20)))) {
            return false;
        }

        RateLimiter::hit($key, 86400);

        return true;
    }
}
