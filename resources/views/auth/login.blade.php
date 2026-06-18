<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Antigravity Console</title>
    <!-- Outfit Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style>
        .glass-panel {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="h-full font-sans antialiased overflow-x-hidden flex items-center justify-center">
    <!-- Decorative background elements -->
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-indigo-600/10 rounded-full blur-3xl -z-10"></div>
    <div class="absolute bottom-10 right-10 w-96 h-96 bg-purple-600/10 rounded-full blur-3xl -z-10"></div>

    <div class="w-full max-w-md p-6">
        <!-- Dashboard Branding -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold tracking-tight bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
                Antigravity Console
            </h1>
            <p class="text-slate-400 text-sm mt-2">
                Multi-Tenant Bitrix24 Reporting Dashboard Admin Panel
            </p>
        </div>

        <div class="glass-panel p-8 rounded-2xl">
            <h2 class="text-xl font-semibold mb-6 flex items-center gap-2 text-slate-200">
                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 animate-pulse"></span>
                Administrator Sign In
            </h2>

            @if($errors->any())
                <div class="mb-5 p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('login') }}" method="POST" class="space-y-5">
                @csrf
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Email Address</label>
                    <input type="email" name="email" required value="{{ old('email') }}" placeholder="admin@example.com" 
                        class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Password</label>
                    <input type="password" name="password" required placeholder="••••••••" 
                        class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center text-xs text-slate-400 cursor-pointer">
                        <input type="checkbox" name="remember" class="mr-2 rounded border-slate-850 bg-slate-900 text-indigo-600 focus:ring-0 focus:ring-offset-0">
                        Keep me signed in
                    </label>
                </div>

                <button type="submit" 
                    class="w-full bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-semibold rounded-xl py-3 text-sm transition-all shadow-lg shadow-indigo-500/10">
                    Sign In
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-slate-600 mt-6">
            Default credentials: <span class="text-slate-500">admin@example.com / password</span>
        </p>
    </div>
</body>
</html>
