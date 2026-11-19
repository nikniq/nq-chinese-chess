<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function home()
    {
        // Cached briefly: the front page is the most-hit route and these are
        // aggregate counts that do not need to be live to the second.
        $data = cache()->remember('home.stats', 300, fn () => [
            'players' => User::count(),
            'games' => Game::count(),
            'top' => User::query()->withSum('games as points', 'points')->has('games')
                ->orderByDesc('points')->limit(5)->get(['id', 'name', 'avatar'])
                ->map(fn ($u) => ['name' => $u->name, 'avatar' => $u->avatar, 'points' => (int) $u->points])->all(),
        ]);

        return view('home', $data);
    }

    public function play()
    {
        return view('play', [
            'total' => auth()->check() ? (int) auth()->user()->games()->sum('points') : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'result' => ['required', 'in:win,loss,draw'],
            'difficulty' => ['required', 'integer', 'in:1,2,3'],
            'moves' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $data['points'] = Game::pointsFor($data['result'], $data['difficulty']);
        $game = $request->user()->games()->create($data);

        return response()->json([
            'points' => $game->points,
            'total' => $request->user()->games()->sum('points'),
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();

        return view('history', [
            'games' => $user->games()->latest()->paginate(20),
            'stats' => [
                'total' => $user->games()->sum('points'),
                'wins' => $user->games()->where('result', 'win')->count(),
                'losses' => $user->games()->where('result', 'loss')->count(),
                'draws' => $user->games()->where('result', 'draw')->count(),
            ],
        ]);
    }

    public function leaderboard()
    {
        $leaders = User::query()
            ->withSum('games as points', 'points')
            ->withCount(['games as wins' => fn ($q) => $q->where('result', 'win')])
            ->withCount('games')
            ->has('games')
            ->orderByDesc('points')
            ->limit(50)
            ->get();

        return view('leaderboard', compact('leaders'));
    }
}
