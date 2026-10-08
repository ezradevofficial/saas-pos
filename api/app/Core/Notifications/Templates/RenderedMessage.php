<?php

namespace App\Core\Notifications\Templates;

/** A template with its placeholders filled (plain text); html() escapes it for an email. */
final class RenderedMessage
{
    public function __construct(
        public readonly ?string $subject,
        public readonly string $body,
    ) {}

    public function html(): string
    {
        return TemplateRenderer::toHtml($this->body);
    }
}
