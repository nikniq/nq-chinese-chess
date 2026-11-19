@extends('layouts.app')
@section('title', 'Play Chinese Chess (Xiangqi) Online Free')
@section('mainClass', 'home')
@section('content')
<section class="hero">
    <div class="hero-inner">
        <div class="hero-copy">
            <p class="eyebrow">象棋 · Xiangqi</p>
            <h1>China's classic game of strategy, <span>one click away.</span></h1>
            <p class="lead">Play Chinese Chess free in your browser. Challenge a computer that thinks in three strengths, or pass the board to a friend. No download. No sign-up to start.</p>
            <div class="cta-row">
                <a href="{{ route('play') }}" class="btn big">Play now — it's free</a>
                @guest
                    <a href="{{ route('register') }}" class="btn big ghost">Sign up to keep score</a>
                @else
                    <a href="{{ route('history') }}" class="btn big ghost">My games</a>
                @endguest
            </div>
            <ul class="trust">
                <li>✓ Works on phone, tablet and desktop</li>
                <li>✓ Standard Xiangqi rules</li>
                <li>✓ Sign in with Google or nikniq.com</li>
            </ul>
        </div>
        <div class="hero-board" aria-hidden="true">
            <svg viewBox="0 0 300 330" class="mini-board">
                <defs>
                    <radialGradient id="pc" cx="40%" cy="35%"><stop offset="0" stop-color="#fffaf0"/><stop offset="1" stop-color="#e8d2a6"/></radialGradient>
                    <filter id="sh"><feDropShadow dx="0" dy="2" stdDeviation="2" flood-opacity=".35"/></filter>
                </defs>
                <rect x="0" y="0" width="300" height="330" rx="14" fill="#e9c88c"/>
                <g stroke="#6b4220" stroke-width="1.3">
                    @for ($r = 0; $r < 10; $r++)<line x1="30" y1="{{ 30 + $r * 30 }}" x2="270" y2="{{ 30 + $r * 30 }}"/>@endfor
                    @for ($c = 0; $c < 9; $c++)
                        @if ($c === 0 || $c === 8)<line x1="{{ 30 + $c * 30 }}" y1="30" x2="{{ 30 + $c * 30 }}" y2="300"/>
                        @else<line x1="{{ 30 + $c * 30 }}" y1="30" x2="{{ 30 + $c * 30 }}" y2="150"/><line x1="{{ 30 + $c * 30 }}" y1="180" x2="{{ 30 + $c * 30 }}" y2="300"/>@endif
                    @endfor
                    <line x1="120" y1="30" x2="180" y2="90"/><line x1="180" y1="30" x2="120" y2="90"/>
                    <line x1="120" y1="240" x2="180" y2="300"/><line x1="180" y1="240" x2="120" y2="300"/>
                </g>
                <text x="90" y="171" font-size="17" text-anchor="middle" fill="#6b4220" font-family="serif">楚 河</text>
                <text x="210" y="171" font-size="17" text-anchor="middle" fill="#6b4220" font-family="serif">漢 界</text>
                @php($pieces = [[0,0,'車',0],[0,1,'馬',0],[0,3,'士',0],[0,4,'將',0],[2,4,'砲',0],[3,2,'卒',0],[3,6,'卒',0],[1,4,'士',0],[4,4,'俥',1],[6,0,'兵',1],[6,4,'兵',1],[7,7,'炮',1],[9,4,'帥',1],[9,3,'仕',1],[7,2,'傌',1],[9,8,'俥',1],[2,7,'馬',0],[5,6,'傌',1]])
                @foreach ($pieces as [$r, $c, $ch, $red])
                    <g class="pc" filter="url(#sh)" style="--d: {{ $loop->index * 60 }}ms">
                        <circle cx="{{ 30 + $c * 30 }}" cy="{{ 30 + $r * 30 }}" r="13" fill="url(#pc)" stroke="{{ $red ? '#b3261e' : '#222' }}" stroke-width="1.5"/>
                        <text x="{{ 30 + $c * 30 }}" y="{{ 35 + $r * 30 }}" font-size="14" font-weight="700" text-anchor="middle" fill="{{ $red ? '#b3261e' : '#222' }}" font-family="'Kaiti SC','STKaiti',serif">{{ $ch }}</text>
                    </g>
                @endforeach
                <circle cx="150" cy="150" r="17" fill="none" stroke="#2f7de1" stroke-width="2.5" class="pulse"/>
            </svg>
        </div>
    </div>
    @if ($games >= 50)
        <div class="stats-strip">
            <div><strong>{{ number_format($games) }}</strong><span>ranked games played</span></div>
            <div><strong>{{ number_format($players) }}</strong><span>registered players</span></div>
            <div><strong>3</strong><span>computer strengths</span></div>
        </div>
    @endif
</section>

<section class="section">
    <div class="section-inner">
        <h2 class="section-title">Everything you need to enjoy a game</h2>
        <div class="features">
            <article class="feature">
                <div class="feature-icon">🤖</div>
                <h3>An opponent at your level</h3>
                <p>Easy for learning the moves, Medium for a real game, Hard when you want to be pushed. The computer thinks in the background, so the board never freezes.</p>
            </article>
            <article class="feature">
                <div class="feature-icon">👥</div>
                <h3>Play a friend</h3>
                <p>Pass-and-play on one device. Flip the board so each side plays from their own end.</p>
            </article>
            <article class="feature">
                <div class="feature-icon">💡</div>
                <h3>Learn as you go</h3>
                <p>Legal moves light up when you pick a piece. Stuck? Ask for a hint, or take a move back.</p>
            </article>
            <article class="feature">
                <div class="feature-icon">🏆</div>
                <h3>Climb the leaderboard</h3>
                <p>Sign in and every ranked win counts. Harder levels are worth more: 10, 20 or 30 points a win.</p>
            </article>
            <article class="feature">
                <div class="feature-icon">💾</div>
                <h3>Pick up where you left off</h3>
                <p>Close the tab mid-game and your position is waiting when you come back.</p>
            </article>
            <article class="feature">
                <div class="feature-icon">📱</div>
                <h3>Any screen</h3>
                <p>A sharp, responsive board on phones, tablets and big monitors. Nothing to install.</p>
            </article>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="section-inner split">
        <div>
            <h2 class="section-title left">New to Xiangqi?</h2>
            <p class="lead small">Chinese Chess is like international chess with a twist: a river splits the board, generals are confined to their palace, and cannons capture by jumping. Most players learn the moves in ten minutes.</p>
            <ol class="steps">
                <li><strong>Read the one-page rules</strong> — every piece, with how it moves.</li>
                <li><strong>Start on Easy</strong> — the board shows every legal move as you play.</li>
                <li><strong>Trap the enemy general</strong> — checkmate wins, and so does leaving them with no legal move.</li>
            </ol>
            <a href="{{ route('rules') }}" class="btn ghost">Learn the rules</a>
        </div>
        <div class="leaders card">
            <h3>🏆 Top players</h3>
            @if (count($top))
                <ol class="leader-list">
                    @foreach ($top as $p)
                        <li>
                            <span class="rank">{{ $loop->iteration }}</span>
                            <span class="who">{{ $p['name'] }}</span>
                            <span class="pts">{{ number_format($p['points']) }} pts</span>
                        </li>
                    @endforeach
                </ol>
                <a href="{{ route('leaderboard') }}" class="more">Full leaderboard →</a>
            @else
                <p class="muted">The leaderboard is wide open. Win one game on Hard and you're on top.</p>
                <a href="{{ route('play') }}" class="btn small">Claim the #1 spot</a>
            @endif
        </div>
    </div>
</section>

<section class="section">
    <div class="section-inner narrow-inner">
        <h2 class="section-title">Questions</h2>
        <details class="faq"><summary>Is it really free?</summary><p>Yes. Every mode is free. An account is only needed to save scores to the leaderboard.</p></details>
        <details class="faq"><summary>Do I need to create an account?</summary><p>No. Press Play and you're in. Sign up — with email, Google or nikniq.com — when you want your wins recorded.</p></details>
        <details class="faq"><summary>How is the score worked out?</summary><p>A ranked win is worth 10 points on Easy, 20 on Medium and 30 on Hard; a draw is worth a third of that. Games where you used undo or a hint still count as played, but don't score.</p></details>
        <details class="faq"><summary>Which rules do you use?</summary><p>Standard Xiangqi movement rules, including the flying-general rule (the two generals may never face each other on an open file). A player with no legal move loses, and a position repeated three times is a draw.</p></details>
    </div>
</section>

<section class="final-cta">
    <h2>Your move.</h2>
    <p>A game takes about fifteen minutes. The first one starts in a second.</p>
    <a href="{{ route('play') }}" class="btn big light">Play Chinese Chess</a>
</section>
@endsection
