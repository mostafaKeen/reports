<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitrix24 Tenant Console — Antigravity</title>
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
        .glow-hover:hover {
            box-shadow: 0 0 25px rgba(99, 102, 241, 0.2);
            border-color: rgba(99, 102, 241, 0.4);
        }
    </style>
</head>
<body class="h-full font-sans antialiased overflow-x-hidden">
    <!-- Decorative background elements -->
    <div class="absolute top-0 left-1/4 w-96 h-96 bg-indigo-600/10 rounded-full blur-3xl -z-10"></div>
    <div class="absolute bottom-10 right-10 w-96 h-96 bg-purple-600/10 rounded-full blur-3xl -z-10"></div>

    <div class="max-w-7xl mx-auto px-6 py-12">
        <!-- Header -->
        <header class="flex justify-between items-center border-b border-slate-800 pb-8 mb-12">
            <div>
                <h1 class="text-4xl font-bold tracking-tight bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
                    Bitrix24 multi-company
                </h1>
                <p class="text-slate-400 mt-2 text-sm md:text-base">
                    Admin console to connect and register independent client workspaces with namespaced Redis caching.
                </p>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-xs bg-slate-900 border border-slate-800 text-indigo-400 px-3 py-1.5 rounded-full font-semibold">
                    v1.0.0 Stable
                </span>
                <form action="{{ route('logout') }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="text-xs hover:text-rose-400 text-slate-400 font-semibold bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full transition-all">
                        Logout
                    </button>
                </form>
            </div>
        </header>

        @if(session('success'))
            <div class="mb-8 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm">
                🎉 {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-8 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm">
                ⚠️ {{ session('error') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Panel: Create Company -->
            <div class="lg:col-span-1">
                <div class="glass-panel p-8 rounded-2xl sticky top-8">
                    <h2 class="text-xl font-semibold mb-6 flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        Register New Company
                    </h2>
                    
                    <form action="{{ route('companies.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Company Name</label>
                            <input type="text" name="name" required placeholder="e.g. Acme Corp" 
                                class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Bitrix24 Portal Domain</label>
                            <input type="text" name="domain" required placeholder="acme.bitrix24.com" 
                                class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Local App ID (Client ID)</label>
                            <input type="text" name="client_id" required placeholder="local.65c82ff..." 
                                class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">App Client Secret</label>
                            <input type="password" name="client_secret" required placeholder="••••••••••••••••" 
                                class="w-full bg-slate-900/60 border border-slate-800 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-500 transition-all text-slate-100 placeholder-slate-600">
                        </div>

                        <button type="submit" 
                            class="w-full bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-semibold rounded-xl py-3 text-sm transition-all shadow-lg shadow-indigo-500/10">
                            Register Company
                        </button>
                    </form>

                    <div class="mt-8 pt-6 border-t border-slate-800/80">
                        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Setup Hint</h3>
                        <p class="text-xs text-slate-500 leading-relaxed space-y-3">
                            <span>When registering your Local App inside the Bitrix24 Developer Portal:</span>
                            <span class="block mt-2 font-semibold">Path for initial installation (Install URL):</span>
                            <code class="block p-2 bg-slate-950 border border-slate-800 rounded text-indigo-400 select-all overflow-x-auto">
                                {{ url('/bitrix24/install') }}
                            </code>
                            <span class="block mt-2 font-semibold">Redirect URI (Callback URL):</span>
                            <code class="block p-2 bg-slate-950 border border-slate-800 rounded text-indigo-400 select-all overflow-x-auto">
                                {{ url('/bitrix24/callback') }}
                            </code>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Companies List -->
            <div class="lg:col-span-2 space-y-6">
                <h2 class="text-2xl font-bold tracking-tight text-slate-200">Registered Companies</h2>

                @if($companies->isEmpty())
                    <div class="glass-panel p-12 rounded-2xl text-center border-dashed">
                        <p class="text-slate-500 text-sm">No companies registered yet. Create your first tenant workspace using the form.</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach($companies as $company)
                            <div class="glass-panel p-6 rounded-2xl transition-all duration-300 glow-hover relative flex flex-col justify-between h-[21rem]">
                                <div>
                                    <div class="flex justify-between items-start gap-4">
                                        <div>
                                            <h3 class="text-lg font-bold text-slate-200">{{ $company->name }}</h3>
                                            <span class="text-xs text-slate-400 select-all font-mono">{{ $company->domain }}</span>
                                        </div>
                                        <div class="flex flex-col gap-1.5 items-end">
                                            @if($company->is_active)
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-semibold bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                                                    <span class="w-1 h-1 rounded-full bg-emerald-400"></span> Active
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-semibold bg-rose-500/10 border border-rose-500/20 text-rose-400">
                                                    <span class="w-1 h-1 rounded-full bg-rose-400"></span> Inactive
                                                </span>
                                            @endif
                                            
                                            @if($company->isConnected())
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 border border-indigo-500/20 text-indigo-400">
                                                    Connected
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-slate-800 border border-slate-700 text-slate-400">
                                                    Unconnected
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-4 space-y-1.5 text-xs text-slate-400 border-t border-slate-900 pt-4">
                                        <div class="flex justify-between">
                                            <span>Member ID:</span>
                                            <span class="font-mono text-slate-300">{{ $company->member_id ?? 'N/A' }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Client ID:</span>
                                            <span class="font-mono text-slate-300 truncate max-w-[150px]">{{ $company->client_id }}</span>
                                        </div>
                                        @if($company->expires_at)
                                            <div class="flex justify-between">
                                                <span>Tokens Expire:</span>
                                                <span class="text-slate-300">{{ $company->expires_at->diffForHumans() }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="space-y-3 mt-6 pt-4 border-t border-slate-900">
                                    <div class="flex gap-2">
                                        <form action="{{ route('companies.toggle-active', $company) }}" method="POST" class="flex-1">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" 
                                                class="w-full text-center bg-slate-900 border border-slate-850 hover:border-slate-700 text-slate-300 hover:text-slate-100 font-semibold rounded-xl py-2 text-xs transition-all">
                                                {{ $company->is_active ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>

                                        <form action="{{ route('companies.destroy', $company) }}" method="POST" onsubmit="return confirm('Are you sure you want to remove this company?');" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" 
                                                class="px-4 bg-slate-900 hover:bg-rose-500/10 border border-slate-850 hover:border-rose-500/20 text-slate-500 hover:text-rose-400 font-semibold rounded-xl py-2 text-xs transition-all">
                                                Delete
                                            </button>
                                        </form>
                                    </div>

                                    @if($company->is_active)
                                        @if($company->isConnected())
                                            <a href="{{ route('bitrix24.connect', $company) }}" 
                                                class="block w-full text-center bg-slate-900 border border-slate-800 hover:border-indigo-500 text-indigo-400 hover:text-indigo-300 font-semibold rounded-xl py-2 text-xs transition-all">
                                                Reconnect OAuth
                                            </a>
                                            <a href="{{ route('report.show', $company) }}" 
                                                class="block w-full text-center bg-gradient-to-r from-purple-600/20 to-pink-600/20 border border-purple-500/20 hover:border-purple-500/40 text-purple-400 hover:text-purple-300 font-semibold rounded-xl py-2 text-xs transition-all">
                                                📊 View Reports
                                            </a>
                                        @else
                                            <a href="{{ route('bitrix24.connect', $company) }}" 
                                                class="block w-full text-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl py-2 text-xs transition-all shadow-md shadow-indigo-600/10">
                                                Connect OAuth
                                            </a>
                                        @endif
                                    @else
                                        <button disabled 
                                            class="block w-full text-center bg-slate-900/40 border border-slate-900 text-slate-600 font-semibold rounded-xl py-2 text-xs cursor-not-allowed">
                                            Activate to Connect
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
