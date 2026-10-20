<?php

namespace App\Core\Platform\Console;

use App\Core\Http\ApiException;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * ADR 013: create a platform staff account (the first one, or more) and
 * print a one-time setup link, valid 24 hours. The person sets a password
 * and an authenticator app there; nothing works before both are done.
 * `--reset` starts an existing account over (password, two-factor and
 * every session cleared). Runs on the runtime connection: the tables are
 * global, so the owner's credentials are not needed.
 */
class CreatePlatformUser extends Command
{
    protected $signature = 'platform:user
        {email : The staff member\'s email}
        {--name= : Their name (defaults to the part of the email before @)}
        {--role=owner : owner, support, sales, finance, compliance or engineering}
        {--reset : Start an existing account over with a new setup link}';

    protected $description = 'Create a platform staff account and print its one-time setup link';

    public function handle(PlatformAuth $auth): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: strstr($email, '@', true)));
        $role = (string) $this->option('role');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'role' => $role],
            ['email' => ['required', 'email:rfc', 'max:255'], 'name' => ['required', 'string', 'max:120'], 'role' => ['required', 'in:'.implode(',', PlatformUser::ROLES)]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $token = $auth->invite($email, $name, $role, (bool) $this->option('reset'));
        } catch (ApiException $e) {
            $this->error($e->getMessage().' Use --reset to start it over.');

            return self::FAILURE;
        }

        $this->info("Platform account ready for {$email} ({$role}).");
        $this->line('One-time setup link, valid '.PlatformAuth::SETUP_HOURS.' hours:');
        $this->line(rtrim((string) config('platform.url'), '/').'/platform/setup/'.$token);

        return self::SUCCESS;
    }
}
