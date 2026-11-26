@extends('layouts.app')
@section('title', 'Play')
@section('content')
<div class="game">
    <div class="board-col">
        <div class="tray"><span class="tray-label">Black lost</span><span id="capByRed" class="caps"></span></div>
        <div class="board-wrap">
            <canvas id="board" aria-label="Chinese chess board. Click a piece, then a highlighted point to move it."></canvas>
            <div id="resultDialog" class="result" hidden role="dialog" aria-labelledby="resultTitle">
                <div class="result-card">
                    <div id="resultIcon" class="result-icon"></div>
                    <h2 id="resultTitle"></h2>
                    <p id="resultScore" class="muted"></p>
                    <div class="row center">
                        <button class="btn" id="again">Play again</button>
                        <button class="btn ghost" id="closeResult">View board</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="tray"><span class="tray-label">Red lost</span><span id="capByBlack" class="caps"></span></div>
    </div>

    <aside class="panel card">
        <h2 id="status" class="status">Your move</h2>
        <p id="ranked" class="badge"></p>
        <p id="resumeNote" class="muted small" hidden>Resumed your unfinished game.</p>

        <div class="controls-grid">
            <label>Opponent
                <select id="mode">
                    <option value="computer" @selected(request('mode') !== 'friend')>Computer</option>
                    <option value="friend" @selected(request('mode') === 'friend')>Friend (same device)</option>
                </select>
            </label>
            <label>Strength
                <select id="difficulty">
                    <option value="1">Easy</option>
                    <option value="2" selected>Medium</option>
                    <option value="3">Hard</option>
                </select>
            </label>
        </div>

        <div class="row">
            <button class="btn" id="newGame">New game</button>
            <button class="btn ghost" id="hint" title="Shortcut: H">Hint</button>
            <button class="btn ghost" id="undo" title="Shortcut: U">Undo</button>
            <button class="btn ghost" id="flip" title="Shortcut: F">Flip</button>
            <button class="btn ghost danger" id="resign">Resign</button>
        </div>
        <label class="check"><input type="checkbox" id="sound" checked> Sound</label>

        <div @class(["score-box", "guest" => auth()->guest()])>
            @if (auth()->check() && ! auth()->user()->hasVerifiedEmail())
                <span>Verify your email to save scores.</span>
                <a href="{{ route('verification.notice') }}">Resend the link</a>
            @elseauth
                <span>Your points</span><strong id="scoreTotal">{{ number_format($total) }}</strong>
            @else
                <span>Playing as a guest.</span>
                <a href="{{ route('register') }}">Sign up</a> or <a href="{{ route('login') }}">log in</a> to put your wins on the leaderboard.
            @endauth
        </div>

        <details class="moves-box" open>
            <summary>Moves (<span id="moveCount">0</span>)</summary>
            <ol id="moveList" class="moves"></ol>
        </details>
        <p class="muted small">New to the game? <a href="{{ route('rules') }}">How the pieces move</a>.</p>
    </aside>
</div>
@endsection
@push('scripts')
<script>
    window.APP = {
        loggedIn: @json(auth()->check() && auth()->user()->hasVerifiedEmail()),
        unverified: @json(auth()->check() && ! auth()->user()->hasVerifiedEmail()),
        verifyUrl: @json(route('verification.notice')),
        saveUrl: @json(route('games.store')),
        leaderboardUrl: @json(route('leaderboard')),
        engineUrl: @json(asset('js/engine.js').'?v='.@filemtime(public_path('js/engine.js'))),
        csrf: document.querySelector('meta[name=csrf-token]').content,
    };
</script>
<script src="{{ asset('js/engine.js') }}?v={{ @filemtime(public_path('js/engine.js')) }}"></script>
<script src="{{ asset('js/xiangqi.js') }}?v={{ @filemtime(public_path('js/xiangqi.js')) }}"></script>
@endpush
