<?php

namespace App\Core\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * NFR-10: the Sentry hooks (config/sentry.php). Every event, transaction
 * and breadcrumb is scrubbed with the shared rules before it is sent: the
 * message, exception values, request (URL, query, headers, cookies, body),
 * extra data, contexts, tags (the tenant id is kept), breadcrumbs and
 * stack frame variables. A user, if any integration attached one, keeps
 * only its id. Static methods, so `config:cache` can store them.
 */
final class SentryEvents
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $out = new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage() === null ? null : Scrubber::scrubString($breadcrumb->getMessage()),
            (array) Scrubber::scrub($breadcrumb->getMetadata()),
            $breadcrumb->getTimestamp(),
        );

        return $out;
    }

    public static function scrub(Event $event): Event
    {
        if (($message = $event->getMessage()) !== null) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                Scrubber::scrubString($message),
                (array) Scrubber::scrub($event->getMessageParams()),
                $formatted === null ? null : Scrubber::scrubString($formatted),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(Scrubber::scrubString($exception->getValue()));

            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                if ($frame->getVars() !== []) {
                    $frame->setVars((array) Scrubber::scrub($frame->getVars()));
                }
            }
        }

        $event->setRequest((array) Scrubber::scrub($event->getRequest()));
        $event->setExtra((array) Scrubber::scrub($event->getExtra()));
        $event->setTags(array_map(
            fn ($value) => is_string($value) ? Scrubber::scrubString($value) : $value,
            array_filter((array) Scrubber::scrub($event->getTags()), fn ($value, $key) => ! Scrubber::isSensitiveKey((string) $key), ARRAY_FILTER_USE_BOTH),
        ));

        foreach ($event->getContexts() as $name => $data) {
            if (is_array($data)) {
                $event->setContext((string) $name, (array) Scrubber::scrub($data));
            }
        }

        $event->setBreadcrumb(array_map(fn (Breadcrumb $crumb) => self::beforeBreadcrumb($crumb), $event->getBreadcrumbs()));

        $user = $event->getUser();
        $event->setUser($user?->getId() !== null ? UserDataBag::createFromUserIdentifier($user->getId()) : null);

        return $event;
    }
}
