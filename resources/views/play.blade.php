@extends('layouts.app')
@section('title', 'Play')
@section('content')
<div class="game">
    <div class="board-wrap">
        <canvas id="board" width="540" height="600" aria-label="Chinese chess board"></canvas>
    </div>
    <aside class="panel card">
        <h2 id="status">Your move (Red)</h2>
        <label>Difficulty
            <select id="difficulty">
                <option value="1">Easy</option>
                <option value="2" selected>Medium</option>
                <option value="3">Hard</option>
            </select>
        </label>
        <div class="row">
            <button class="btn" id="newGame">New game</button>
            <button class="btn ghost" id="undo">Undo</button>
            <button class="btn ghost" id="resign">Resign</button>
        </div>
        <p class="muted">Moves: <span id="moveCount">0</span></p>
        <p id="scoreMsg" class="muted">
            @auth
                Logged in as <strong>{{ auth()->user()->name }}</strong> — results are saved automatically.
                Win: 10 × level, draw: 3 × level.
            @else
                Playing as guest. <a href="{{ route('login') }}">Log in</a> or <a href="{{ route('register') }}">register</a> to record your scores.
            @endauth
        </p>
        <ol id="moveList" class="moves"></ol>
    </aside>
</div>
@endsection
@push('scripts')
<script>
    window.APP = {
        loggedIn: @json(auth()->check()),
        saveUrl: @json(route('games.store')),
        csrf: document.querySelector('meta[name=csrf-token]').content,
    };
</script>
<script src="{{ asset('js/xiangqi.js') }}"></script>
@endpush
