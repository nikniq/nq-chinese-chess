<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Chinese Chess') · 象棋</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="nav">
    <a href="{{ route('play') }}" class="brand">象棋 <span>Chinese Chess</span></a>
    <nav>
        <a href="{{ route('play') }}">Play</a>
        <a href="{{ route('leaderboard') }}">Leaderboard</a>
        @auth
            <a href="{{ route('history') }}">My Games</a>
            <form method="POST" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="linkish">Log out ({{ auth()->user()->name }})</button>
            </form>
        @else
            <a href="{{ route('login') }}">Log in</a>
            <a href="{{ route('register') }}" class="btn small">Register</a>
        @endauth
    </nav>
</header>
<main class="container">
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
