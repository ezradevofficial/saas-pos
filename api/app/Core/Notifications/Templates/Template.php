<?php

namespace App\Core\Notifications\Templates;

use App\Core\Notifications\Channels;

/**
 * NOT-03: the text of one event type for one channel and language, before
 * placeholders are filled. `source`: `default` (language files), `all`
 * (the tenant's override for every channel) or `channel` (the tenant's
 * override for this channel).
 */
final class Template
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_ALL = 'all';

    public const SOURCE_CHANNEL = 'channel';

    public function __construct(
        public readonly ?string $subject,
        public readonly string $body,
        public readonly string $source = self::SOURCE_DEFAULT,
    ) {}

    /**
     * The subject is one line (whitespace, line breaks included, collapsed);
     * on SMS and WhatsApp the body is cut to Channels::SHORT_MAX, also when
     * a text for all channels applies to them.
     *
     * @param  array<string, mixed>  $values
     */
    public function render(array $values, ?string $channel = null): RenderedMessage
    {
        $body = TemplateRenderer::render($this->body, $values);

        if (in_array($channel, Channels::SHORT, true)) {
            $body = TemplateRenderer::truncate($body, Channels::SHORT_MAX);
        }

        return new RenderedMessage(
            $this->subject === null ? null : TemplateRenderer::oneLine(TemplateRenderer::render($this->subject, $values)),
            $body,
        );
    }
}
