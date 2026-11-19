<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Play Chinese Chess Online') · NQ Chinese Chess</title>
    <meta name="description" content="@yield('description', 'Play Chinese Chess (Xiangqi) free in your browser against a computer opponent or a friend. No download, no sign-up needed — sign in to climb the leaderboard.')">
    <meta property="og:title" content="NQ Chinese Chess — play Xiangqi free in your browser">
    <meta property="og:description" content="Three AI levels, a friend mode and a global leaderboard. No download, no sign-up to play.">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#3b2417">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><circle cx='32' cy='32' r='30' fill='%23fdf3dc' stroke='%23b3261e' stroke-width='4'/><text x='32' y='44' font-size='34' text-anchor='middle' fill='%23b3261e'>帥</text></svg>">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
</head>
<body class="@yield('bodyClass')">
<a href="#main" class="skip">Skip to content</a>
<header class="topbar">
    <div class="topbar-inner">
        <a href="{{ route('home') }}" class="brand" aria-label="NQ Chinese Chess home">
            <span class="brand-mark" aria-hidden="true">帥</span>
            <span class="brand-text">NQ <strong>Chinese Chess</strong></span>
        </a>
        <button class="menu-toggle" aria-expanded="false" aria-controls="menu" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <nav id="menu" class="menu" aria-label="Main">
            <a href="{{ route('play') }}" @class(['active' => request()->routeIs('play')])>Play</a>
            <a href="{{ route('rules') }}" @class(['active' => request()->routeIs('rules')])>How to play</a>
            <a href="{{ route('leaderboard') }}" @class(['active' => request()->routeIs('leaderboard')])>Leaderboard</a>
            @auth
                <a href="{{ route('history') }}" @class(['active' => request()->routeIs('history')])>My games</a>
                <span class="menu-user">
                    @if (auth()->user()->avatar)
                        <img src="{{ auth()->user()->avatar }}" alt="" class="avatar" referrerpolicy="no-referrer">
                    @else
                        <span class="avatar avatar-letter">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    @endif
                    {{ auth()->user()->name }}
                </span>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="linkish">Log out</button>
                </form>
            @else
                <a href="{{ route('login') }}" @class(['active' => request()->routeIs('login')])>Log in</a>
                <a href="{{ route('register') }}" class="btn small">Sign up free</a>
            @endauth
        </nav>
    </div>
</header>

<main id="main" class="@yield('mainClass', 'container')">
    @yield('content')
</main>

<footer class="footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <a href="{{ route('home') }}" class="brand"><span class="brand-mark" aria-hidden="true">帥</span><span class="brand-text">NQ <strong>Chinese Chess</strong></span></a>
            <p>Xiangqi, China's classic game of strategy — free to play in your browser.</p>
        </div>
        <div>
            <h4>Play</h4>
            <a href="{{ route('play') }}">Against the computer</a>
            <a href="{{ route('play') }}?mode=friend">With a friend</a>
            <a href="{{ route('leaderboard') }}">Leaderboard</a>
        </div>
        <div>
            <h4>Learn</h4>
            <a href="{{ route('rules') }}">How to play</a>
            <a href="{{ route('rules') }}#pieces">The pieces</a>
            <a href="{{ route('rules') }}#scoring">Scoring</a>
        </div>
        <div>
            <h4>Account</h4>
            @auth
                <a href="{{ route('history') }}">My games</a>
            @else
                <a href="{{ route('register') }}">Sign up</a>
                <a href="{{ route('login') }}">Log in</a>
            @endauth
            <a href="{{ route('privacy') }}">Privacy</a>
        </div>
    </div>
    <p class="footer-legal">&copy; {{ date('Y') }} NQ Chinese Chess · Part of the <a href="https://nikniq.com">nikniq.com</a> games family.</p>
</footer>

<script>
    (() => {
        const t = document.querySelector('.menu-toggle'), m = document.getElementById('menu');
        t.addEventListener('click', () => {
            const open = t.getAttribute('aria-expanded') !== 'true';
            t.setAttribute('aria-expanded', open); m.classList.toggle('open', open);
        });
    })();
</script>
@stack('scripts')
</body>
</html>
