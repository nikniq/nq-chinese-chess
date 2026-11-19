@extends('layouts.app')
@section('title', 'Register')
@section('content')
<div class="card narrow auth-card">
    <h1>Create your free account</h1>
    <p class="muted">Save every win and climb the leaderboard.</p>
    @include('auth._oauth')
    <form method="POST" action="{{ route('register') }}">
        @csrf
        <label>Name <input type="text" name="name" value="{{ old('name') }}" required autofocus maxlength="50"></label>
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <label>Email <input type="email" name="email" value="{{ old('email') }}" required></label>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <label>Password <input type="password" name="password" required minlength="8"></label>
        @error('password')<p class="error">{{ $message }}</p>@enderror
        <label>Confirm password <input type="password" name="password_confirmation" required></label>
        <button class="btn" type="submit">Create account</button>
    </form>
    <p class="muted">Already registered? <a href="{{ route('login') }}">Log in</a></p>
</div>
@endsection
