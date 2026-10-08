<?php

namespace App\Core\Notifications\Templates;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\Models\NotificationTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * NOT-03: which text an event type uses on a channel for a recipient. The
 * tenant's override for that channel wins, then its override for `all`
 * channels, then the default from the language files (`subject`, `body`,
 * and `sms` for SMS and WhatsApp when the event has a short text) in the
 * recipient's language. A tenant's text is one text in the organisation's
 * language, used as is for every recipient (owner decision 2026-10-08);
 * only the defaults are translated.
 */
class Templates
{
    public function default(EventType $type, string $channel, string $locale): Template
    {
        $short = in_array($channel, Channels::SHORT, true) && Lang::has("{$type->langKey}.sms", $locale, false);

        return new Template(
            Lang::get("{$type->langKey}.subject", [], $locale),
            Lang::get($type->langKey.($short ? '.sms' : '.body'), [], $locale),
        );
    }

    /**
     * The current tenant's overrides of $type, keyed by channel.
     *
     * @return Collection<string, NotificationTemplate>
     */
    public function overrides(EventType $type): Collection
    {
        return NotificationTemplate::query()
            ->where('event_type', $type->key)
            ->get()
            ->keyBy('channel');
    }

    /**
     * The text $type uses on $channel (or `all`) for a reader of $locale:
     * the tenant's override as is, else the default in $locale. A blank
     * subject on an override keeps the default subject.
     *
     * @param  Collection<string, NotificationTemplate>|null  $overrides  from overrides(), to save a query per channel
     */
    public function effective(EventType $type, string $channel, string $locale, ?Collection $overrides = null): Template
    {
        $overrides ??= $this->overrides($type);
        $default = $this->default($type, $channel === Channels::ANY ? Channels::EMAIL : $channel, $locale);

        foreach ([[$channel, Template::SOURCE_CHANNEL], [Channels::ANY, Template::SOURCE_ALL]] as [$key, $source]) {
            $override = $overrides->get($key);

            if ($override !== null) {
                $source = $channel === Channels::ANY ? Template::SOURCE_ALL : $source;

                return new Template($override->subject ?? $default->subject, $override->body, $source);
            }
        }

        return $default;
    }
}
