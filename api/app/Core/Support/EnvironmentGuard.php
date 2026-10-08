<?php

namespace App\Core\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use RuntimeException;

/**
 * NFR-06: development drivers never serve a real environment. Outside
 * `local` and `testing`, the runtime entry points refuse to run when mail
 * goes to the log or an array, SMS goes to the log, or the cache or queue
 * is not Redis (README, pre-deploy checklist).
 *
 * Enforced only where the app does real work: every HTTP request
 * (EnforceEnvironment middleware), a queue worker's first loop, and the
 * scheduler and worker commands. Never while the app boots: deploy and
 * bootstrap commands (`package:discover` run by `composer install`,
 * `config:*`, `optimize*`, `down`, `up`) must work even with the previous
 * release's cached config, or a host cannot recover. `app:preflight`
 * reports the same checks and more without throwing, and the deploy runs
 * it before migrating.
 *
 * It applies once the environment is declared: config is cached, an `.env`
 * exists, or APP_ENV is set in the process. A fresh checkout without `.env`
 * (`composer install` in CI or `composer setup`) is not an environment yet.
 */
final class EnvironmentGuard
{
    public const DEVELOPMENT = ['local', 'testing'];

    /** Long-running console entry points, checked before they start. */
    public const RUNTIME_COMMANDS = ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen'];

    /** Mail transports that deliver nothing. */
    private const DEVELOPMENT_MAIL_TRANSPORTS = ['log', 'array'];

    /**
     * Wire the console entry points: the runtime commands above, and the
     * first loop of any queue worker (however it was started, e.g. Horizon).
     */
    public static function listen(Application $app, Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($app) {
            if (in_array($event->command, self::RUNTIME_COMMANDS, true)) {
                self::enforce($app);
            }
        });

        $checked = false;
        $events->listen(Looping::class, function () use ($app, &$checked) {
            if (! $checked) {
                self::enforce($app);
                $checked = true;
            }
        });
    }

    public static function enforce(Application $app): void
    {
        if (! self::applies($app)) {
            return;
        }

        $problems = self::problems($app->make('config'));

        if ($problems !== []) {
            throw new RuntimeException(sprintf(
                'The %s environment uses development drivers: %s. See the pre-deploy checklist in README.md.',
                $app->environment(),
                implode('; ', $problems),
            ));
        }
    }

    public static function applies(Application $app): bool
    {
        if ($app->environment(self::DEVELOPMENT)) {
            return false;
        }

        return $app->configurationIsCached()
            || is_file($app->environmentFilePath())
            || getenv('APP_ENV') !== false;
    }

    /** @return list<string> what is wrong, empty when ready */
    public static function problems(Repository $config): array
    {
        return array_values(array_filter(self::checks($config)));
    }

    /**
     * Each driver check by label, with its problem or null when it passes.
     *
     * @return array<string, ?string>
     */
    public static function checks(Repository $config): array
    {
        $cache = $config->get('cache.default');
        $queue = $config->get('queue.default');

        return [
            'Mail transport' => self::mailProblem($config),
            'SMS driver' => $config->get('services.sms.driver') === 'log'
                ? 'SMS driver is log (set SMS_DRIVER)'
                : null,
            'Cache store' => $cache === 'redis'
                ? null
                : 'cache store is '.var_export($cache, true).', not redis (set CACHE_STORE=redis)',
            'Queue connection' => $queue === 'redis'
                ? null
                : 'queue connection is '.var_export($queue, true).', not redis (set QUEUE_CONNECTION=redis)',
        ];
    }

    /**
     * The default mailer's transport, and every mailer behind a failover or
     * round robin, must deliver.
     */
    private static function mailProblem(Repository $config): ?string
    {
        $mailer = $config->get('mail.default');
        $pending = [$mailer];
        $seen = [];

        while ($pending !== []) {
            $name = array_shift($pending);

            if (! is_string($name) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $transport = $config->get("mail.mailers.{$name}.transport");

            if ($transport === null) {
                return "mail mailer {$name} is not configured (set MAIL_MAILER)";
            }

            if (in_array($transport, self::DEVELOPMENT_MAIL_TRANSPORTS, true)) {
                return $name === $mailer
                    ? "mail transport is {$transport} (set MAIL_MAILER)"
                    : "mail transport is {$transport} behind mailer {$mailer} (set MAIL_MAILER)";
            }

            if (in_array($transport, ['failover', 'roundrobin'], true)) {
                array_push($pending, ...(array) $config->get("mail.mailers.{$name}.mailers", []));
            }
        }

        return null;
    }
}
