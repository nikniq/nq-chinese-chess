<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Support lookup: a player's account and recent games. cx.sh option 41. */
class UserLookup extends Command
{
    protected $signature = 'chess:user {email : Email address of the player}';

    protected $description = "Show a player's account, score and recent games";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', $user->name],
            ['Registered', $user->created_at],
            ['Games', $user->games()->count()],
            ['Total points', (int) $user->games()->sum('points')],
        ]);

        $this->table(['Date', 'Result', 'Level', 'Moves', 'Points'],
            $user->games()->latest()->limit(10)->get()
                ->map(fn ($g) => [$g->created_at, $g->result, $g->difficulty, $g->moves, $g->points])->all());

        return self::SUCCESS;
    }
}
