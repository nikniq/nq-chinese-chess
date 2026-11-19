@extends('layouts.app')
@section('title', 'My Games')
@section('content')
<div class="card">
    <h1>My Games</h1>
    <div class="stats">
        <div><strong>{{ $stats['total'] }}</strong><span>Points</span></div>
        <div><strong>{{ $stats['wins'] }}</strong><span>Wins</span></div>
        <div><strong>{{ $stats['draws'] }}</strong><span>Draws</span></div>
        <div><strong>{{ $stats['losses'] }}</strong><span>Losses</span></div>
    </div>
    @if ($games->isEmpty())
        <p class="muted">No games yet. <a href="{{ route('play') }}">Play one!</a></p>
    @else
    <table>
        <thead><tr><th>Date</th><th>Result</th><th>Level</th><th>Moves</th><th>Time</th><th>Points</th></tr></thead>
        <tbody>
        @foreach ($games as $g)
            <tr>
                <td>{{ $g->created_at->format('Y-m-d H:i') }}</td>
                <td class="res-{{ $g->result }}">{{ ucfirst($g->result) }}</td>
                <td>{{ \App\Models\Game::DIFFICULTIES[$g->difficulty] ?? $g->difficulty }}</td>
                <td>{{ $g->moves }}</td>
                <td>{{ gmdate('i:s', $g->duration_seconds) }}</td>
                <td>{{ $g->points }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="row" style="margin-top:12px">
        @if ($games->previousPageUrl())<a href="{{ $games->previousPageUrl() }}">&larr; Newer</a>@endif
        @if ($games->nextPageUrl())<a href="{{ $games->nextPageUrl() }}">Older &rarr;</a>@endif
    </p>
    @endif
</div>
@endsection
