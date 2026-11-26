<?php

namespace App\Console\Commands;

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\NikniqController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What sign-in providers are live, and the exact redirect URIs each provider's
 * console must have registered. A mismatch there is the usual reason a
 * sign-in "works locally but not in production". cx.sh option 37.
 */
class OAuthStatus extends Command
{
    protected $signature = 'ops:oauth {--ping : Also check the nikniq.com portal is reachable}';

    protected $description = 'Show sign-in provider configuration and the redirect URIs to register';

    public function handle(): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        $google = config('services.google.redirect');
        $googleUri = str_starts_with((string) $google, 'http') ? $google : $appUrl.'/'.ltrim((string) $google, '/');

        $this->table(['Provider', 'Status', 'Redirect URI to register'], [
            ['Google', GoogleController::enabled() ? '<info>on</info>' : 'off (GOOGLE_CLIENT_ID/SECRET empty)', $googleUri],
            ['nikniq.com', NikniqController::enabled() ? '<info>on</info>' : 'off (NIKNIQ_CLIENT_ID empty)', NikniqController::redirectUri()],
        ]);

        $problems = OAuthChecks::problems();
        foreach ($problems as $p) {
            $this->warn('⚠ '.$p);
        }

        if ($this->option('ping') && NikniqController::enabled()) {
            $portal = rtrim((string) config('services.nikniq.url'), '/');
            try {
                $status = Http::timeout(8)->get($portal)->status();
                $this->line("nikniq.com portal $portal → HTTP $status");
            } catch (Throwable $e) {
                $this->error("nikniq.com portal $portal unreachable: ".$e->getMessage());
                $problems[] = 'portal unreachable';
            }
        }

        $this->line('');
        $this->line('Accounts linked: Google '.\App\Models\User::whereNotNull('google_id')->count()
            .', nikniq.com '.\App\Models\User::whereNotNull('nikniq_id')->count()
            .', email/password only '.\App\Models\User::whereNull('google_id')->whereNull('nikniq_id')->count());

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
