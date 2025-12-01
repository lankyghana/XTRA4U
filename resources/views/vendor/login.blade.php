<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Login</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="w-full max-w-md bg-white shadow p-8 rounded">
        <h1 class="text-2xl font-semibold mb-6 text-center">Vendor Login</h1>
        @if ($errors->any())
            <div class="mb-4 text-sm text-red-600">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('vendor.login') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700" for="email">Email</label>
                <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}" class="mt-1 w-full border rounded px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700" for="password">Password</label>
                <input id="password" name="password" type="password" required class="mt-1 w-full border rounded px-3 py-2" />
            </div>
            <label class="inline-flex items-center text-sm">
                <input type="checkbox" name="remember" class="mr-2" />
                Remember me
            </label>
            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Sign in</button>
        </form>
    </div>
</body>
</html>
