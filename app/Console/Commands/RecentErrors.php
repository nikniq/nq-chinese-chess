<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Count (and optionally show) error-level log lines in a recent window. cx.sh 39. */
class RecentErrors extends Command
{
    protected $signature = 'ops:errors {--hours=24} {--show=0 : Also print the last N error lines}';

    protected $description = 'Count ERROR/CRITICAL/ALERT/EMERGENCY lines in laravel.log for the last N hours';

    public function handle(): int
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            $this->line('  0 errors (no laravel.log)');
            return self::SUCCESS;
        }

        $since = now()->subHours((int) $this->option('hours'));
        $hits = [];
        $fh = fopen($file, 'r');
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $m)
                && strtotime($m[1]) >= $since->getTimestamp()) {
                $hits[] = rtrim(mb_strimwidth($line, 0, 220, '…'));
            }
        }
        fclose($fh);

        $this->line('  '.count($hits).' error line(s) in the last '.$this->option('hours').'h'.(count($hits) ? ' — option 32 shows them' : ''));
        foreach (array_slice($hits, -max(0, (int) $this->option('show'))) as $h) {
            if ((int) $this->option('show') > 0) {
                $this->line('    '.$h);
            }
        }

        return self::SUCCESS;
    }
}
