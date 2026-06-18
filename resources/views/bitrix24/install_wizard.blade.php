<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configure Local App — Antigravity</title>
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
<body class="h-full font-sans antialiased overflow-x-hidden p-6 flex flex-col items-center justify-center">
    <div class="w-full max-w-lg">
        <div class="glass-panel p-8 rounded-2xl">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
                    Connect Workspace
                </h1>
                <p class="text-slate-400 text-xs mt-1">
                    Complete registration of <b>{{ $domain }}</b> inside your Antigravity Dashboard.
                </p>
            </div>

            <form action="{{ route('bitrix24.install.complete') }}" method="POST" class="space-y-4">
                @csrf
                <!-- Hidden OAuth context inputs -->
                <input type="hidden" name="domain" value="{{ $domain }}">
                <input type="hidden" name="member_id" value="{{ $member_id }}">
                <input type="hidden" name="access_token" value="{{ $access_token }}">
                <input type="hidden" name="refresh_token" value="{{ $refresh_token }}">
                <input type="hidden" name="expires_in" value="{{ $expires_in }}">

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Company Name</label>
                    <input type="text" name="name" required placeholder="Acme Corp" 
                        class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Local App Client ID (App ID)</label>
                    <input type="text" name="client_id" required placeholder="local.65c82ff..." 
                        class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">App Client Secret</label>
                    <input type="password" name="client_secret" required placeholder="••••••••••••••••" 
                        class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                </div>

                <button type="submit" 
                    class="w-full mt-2 bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-semibold rounded-xl py-3 text-sm transition-all shadow-lg shadow-indigo-500/10">
                    Complete & Initialize Setup
                </button>
            </form>
        </div>
    </div>
</body>
</html>
