<?php

namespace App\Core\Notifications;

/** NOT-01: the channels a notification can go out on. */
final class Channels
{
    public const IN_APP = 'in_app';

    public const EMAIL = 'email';

    public const PUSH = 'push';

    public const SMS = 'sms';

    public const WHATSAPP = 'whatsapp';

    public const ALL = [self::IN_APP, self::EMAIL, self::PUSH, self::SMS, self::WHATSAPP];

    /** Channels sent through a provider adapter (ChannelDrivers); unavailable until one is configured. */
    public const DRIVEN = [self::PUSH, self::SMS, self::WHATSAPP];

    /** Channels whose default text is the short one (`sms` in the language file). */
    public const SHORT = [self::SMS, self::WHATSAPP];

    /** Longest text for SMS and WhatsApp (about three SMS parts); longer rendered text is cut. */
    public const SHORT_MAX = 480;

    /** A template override that applies to every channel without its own (NOT-03). */
    public const ANY = 'all';

    public const DIGEST_IMMEDIATE = 'immediate';

    public const DIGEST_DAILY = 'daily';

    public const DIGEST_WEEKLY = 'weekly';

    public const DIGESTS = [self::DIGEST_IMMEDIATE, self::DIGEST_DAILY, self::DIGEST_WEEKLY];

    public const LOCALES = ['en', 'fr'];
}
