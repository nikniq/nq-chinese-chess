<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Post-deploy checks. deploy.sh ends with this, and cx.sh option 70 runs it
 * on its own. Exits non-zero if any check fails so a script can stop on it.
 */
class VerifyDeploy extends Command
{
    protected $signature = 'ops:verify-deploy';

    protected $description = 'Check that a deploy left the app in a working state';

    private int $failures = 0;

    public function handle(): int
    {
        $this->check('database connects', fn () => DB::connection()->getPdo() !== null);

        $this->check('no pending migrations', function () {
            Artisan::call('migrate:status', ['--pending' => true]);
            return ! str_contains(Artisan::output(), 'Pending');
        });

        foreach (['users', 'games', 'sessions', 'cache'] as $table) {
            $this->check("table '$table' exists", fn () => Schema::hasTable($table));
        }

        $this->check('cache read/write', function () {
            $key = 'ops-verify-'.uniqid();
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);
            return $ok;
        });

        $this->check('storage writable', fn () => is_writable(storage_path('logs')) && is_writable(storage_path('framework/sessions')));
        $this->check('bootstrap/cache writable', fn () => is_writable(base_path('bootstrap/cache')));

        $this->check('public path is public_html', fn () => basename(public_path()) === 'public_html');
        $this->check('game assets present', fn () => is_file(public_path('js/xiangqi.js')) && is_file(public_path('js/engine.js')) && is_file(public_path('css/app.css')));

        $this->check('APP_KEY set', fn () => filled(config('app.key')));
        $this->check('sign-in configuration', function () {
            $problems = OAuthChecks::problems();
            if ($problems) {
                throw new \RuntimeException(implode(' ', $problems));
            }
            return true;
        });

        if (app()->environment('production')) {
            $this->check('APP_DEBUG off in production', fn () => config('app.debug') === false);
            $this->check('APP_URL is https', fn () => str_starts_with((string) config('app.url'), 'https://'));
        } else {
            $this->line('  · not production ('.app()->environment().') — skipped debug/https checks');
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("✗ {$this->failures} check(s) failed");
            return self::FAILURE;
        }
        $this->info('✓ all checks passed');
        return self::SUCCESS;
    }

    private function check(string $label, callable $test): void
    {
        try {
            $ok = (bool) $test();
            $detail = '';
        } catch (Throwable $e) {
            $ok = false;
            $detail = ' — '.$e->getMessage();
        }
        if (! $ok) {
            $this->failures++;
        }
        $this->line(($ok ? '  <info>✓</info> ' : '  <error>✗</error> ').$label.$detail);
    }
}
