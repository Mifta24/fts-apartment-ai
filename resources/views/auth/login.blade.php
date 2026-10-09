<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-stone-50 p-4">
    <div class="w-full max-w-sm rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold text-stone-900">Apartment Admin</h1>
        <p class="mt-1 text-sm text-stone-500">Sign in to manage your apartment's AI Concierge.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-stone-700" for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-stone-700" for="password">Password</label>
                <input id="password" type="password" name="password" required
                    class="mt-1 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-stone-500 focus:outline-none">
            </div>
            <label class="flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" name="remember" class="rounded border-stone-300">
                Remember me
            </label>
            <button type="submit" class="w-full rounded-lg bg-stone-900 px-3 py-2 text-sm font-medium text-white hover:bg-stone-700">
                Sign in
            </button>
        </form>
    </div>
</body>
</html>
