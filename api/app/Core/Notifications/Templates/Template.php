<?php

namespace App\Core\Notifications\Templates;

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

    /** @param array<string, mixed> $values */
    public function render(array $values): RenderedMessage
    {
        return new RenderedMessage(
            $this->subject === null ? null : TemplateRenderer::render($this->subject, $values),
            TemplateRenderer::render($this->body, $values),
        );
    }
}
