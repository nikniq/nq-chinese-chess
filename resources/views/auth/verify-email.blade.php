@extends('layouts.app')
@section('title', 'Verify your email')
@section('content')
<div class="card narrow auth-card">
    <h1>Check your inbox</h1>
    <p>We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
       Click it to activate your account and start saving scores to the leaderboard.</p>
    <p class="muted">You can keep <a href="{{ route('play') }}">playing</a> in the meantime; games just won't be scored until you verify.</p>
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button class="btn" type="submit">Resend verification email</button>
    </form>
    <p class="muted small">Wrong address? <a href="#" onclick="event.preventDefault();document.getElementById('logout-form')?.submit()">Log out</a> and register again, or sign in with Google or Nikniq.</p>
</div>
@endsection
