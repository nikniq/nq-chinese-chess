@extends('layouts.app')
@section('title', 'Log in')
@section('content')
<div class="card narrow auth-card">
    <h1>Log in</h1>
    @include('auth._oauth')
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required autofocus></label>
        <label>Password <input type="password" name="password" required></label>
        <label class="check"><input type="checkbox" name="remember"> Remember me</label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <button class="btn" type="submit">Log in</button>
    </form>
    <p class="muted">No account? <a href="{{ route('register') }}">Register</a> to save your scores.</p>
</div>
@endsection
