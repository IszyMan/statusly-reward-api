@extends('admin.layout')

@section('title', 'Admin login')

@section('content')
    <section class="card login-card">
        <h1>Welcome back</h1>
        <p class="muted">Sign in to manage Statusly reward campaigns.</p>

        @if ($errors->any())
            <div class="error" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.authenticate') }}">
            @csrf

            <label for="email">Email address</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                maxlength="255"
                required
                autofocus
            >

            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button class="login-button" type="submit">
                Sign in
            </button>
        </form>
    </section>
@endsection