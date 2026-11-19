@extends('layouts.app')
@section('title', 'Leaderboard')
@section('content')
<div class="card">
    <h1>Leaderboard</h1>
    @if ($leaders->isEmpty())
        <p class="muted">No scores yet — be the first!</p>
    @else
    <table>
        <thead><tr><th>#</th><th>Player</th><th>Points</th><th>Wins</th><th>Games</th></tr></thead>
        <tbody>
        @foreach ($leaders as $i => $u)
            <tr @if(auth()->id() === $u->id) class="me" @endif>
                <td>{{ $i + 1 }}</td><td>{{ $u->name }}</td><td>{{ (int) $u->points }}</td><td>{{ $u->wins }}</td><td>{{ $u->games_count }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection
