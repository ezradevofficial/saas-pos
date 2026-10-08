<?php

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Http\Requests\PreviewTemplateRequest;
use App\Core\Notifications\Http\Requests\ResetTemplateRequest;
use App\Core\Notifications\Http\Requests\UpdateTemplateRequest;
use App\Core\Notifications\Http\Requests\ViewTemplatesRequest;
use App\Core\Notifications\Models\NotificationTemplate;
use App\Core\Notifications\Templates\Template;
use App\Core\Notifications\Templates\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * NOT-03: the tenant's notification texts. Each event type has a text for
 * `all` channels and for each of its channels; each shows the text in use,
 * where it comes from (default, the tenant's text for all channels, or for
 * this channel) and the built-in default. A tenant's text is one text in
 * the organisation's language, sent as is to everyone; the defaults are
 * translated and shown in the requesting user's language (owner decision
 * 2026-10-08). Edits and resets are audited (`core.notification_template.*`).
 */
class TemplateController
{
    public function __construct(
        private readonly EventTypes $types,
        private readonly Templates $templates,
    ) {}

    public function index(ViewTemplatesRequest $request): JsonResponse
    {
        $overrides = NotificationTemplate::query()->get()->groupBy('event_type');

        return new JsonResponse(['data' => array_values(array_map(
            fn (EventType $type) => $this->present($type, $overrides->get($type->key, collect())->keyBy('channel')),
            $this->types->active(),
        ))]);
    }

    public function show(ViewTemplatesRequest $request, string $eventType): JsonResponse
    {
        abort_unless($this->types->isActive($eventType), 404);
        $type = $this->types->get($eventType);

        return new JsonResponse(['data' => $this->present($type, $this->templates->overrides($type))]);
    }

    public function update(UpdateTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = $this->types->get($data['event_type']);
        $template = NotificationTemplate::query()->firstOrNew([
            'event_type' => $type->key, 'channel' => $data['channel'],
        ]);
        $subject = trim((string) ($data['subject'] ?? ''));
        $template->fill([
            'subject' => $subject === '' ? null : $subject,
            'body' => $data['body'],
            'updated_by' => $request->user()->id,
        ]);

        if (! $template->exists || $template->isDirty(['subject', 'body'])) {
            $template->save();
        }

        return new JsonResponse(['data' => $this->present($type, $this->templates->overrides($type))]);
    }

    public function reset(ResetTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = $this->types->get($data['event_type']);

        NotificationTemplate::query()
            ->where(['event_type' => $type->key, 'channel' => $data['channel']])
            ->first()
            ?->delete();

        return new JsonResponse(['data' => $this->present($type, $this->templates->overrides($type))]);
    }

    public function preview(PreviewTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = $this->types->get($data['event_type']);
        $effective = $this->templates->effective($type, $data['channel'], $this->readerLocale());
        $template = new Template(
            array_key_exists('subject', $data) && $data['subject'] !== null && trim($data['subject']) !== '' ? $data['subject'] : $effective->subject,
            array_key_exists('body', $data) && $data['body'] !== null ? $data['body'] : $effective->body,
        );
        $message = $template->render($type->samples(), $data['channel']);

        return new JsonResponse(['data' => [
            'event_type' => $type->key,
            'channel' => $data['channel'],
            'subject' => $message->subject,
            'body' => $message->body,
            'html' => $message->html(),
        ]]);
    }

    /** The requesting user's language: the defaults are shown and previewed in it. */
    private function readerLocale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, Channels::LOCALES, true) ? $locale : 'en';
    }

    /**
     * @param  Collection<string, NotificationTemplate>  $overrides
     * @return array<string, mixed>
     */
    private function present(EventType $type, Collection $overrides): array
    {
        $templates = [];
        $locale = $this->readerLocale();

        foreach ([Channels::ANY, ...$type->channels] as $channel) {
            $effective = $this->templates->effective($type, $channel, $locale, $overrides);
            $default = $this->templates->default($type, $channel === Channels::ANY ? Channels::EMAIL : $channel, $locale);
            $own = $overrides->get($channel);

            $templates[] = [
                'channel' => $channel,
                'subject' => $effective->subject,
                'body' => $effective->body,
                'source' => $effective->source,
                'overridden' => $own !== null,
                'updated_at' => $own?->updated_at?->toIso8601String(),
                // The built-in text, in the requesting user's language; each
                // recipient gets it in their own when there is no override.
                'default' => ['locale' => $locale, 'subject' => $default->subject, 'body' => $default->body],
            ];
        }

        return [
            'event_type' => $type->key,
            'label' => $type->label(),
            'module' => $type->module,
            'channels' => $type->channels,
            'placeholders' => array_map(
                fn (string $name) => ['name' => $name, 'sample' => $type->samples()[$name] ?? ''],
                $type->placeholderNames(),
            ),
            'templates' => $templates,
        ];
    }
}
