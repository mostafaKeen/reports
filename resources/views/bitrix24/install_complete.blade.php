<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete</title>
    <!-- Outfit Google Font -->
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
    <!-- Bitrix24 JS SDK -->
    <script src="//api.bitrix24.com/api/v1/"></script>
</head>
<body class="h-full font-sans antialiased flex flex-col items-center justify-center p-6 bg-slate-950">
    <div class="text-center max-w-sm">
        <div class="w-16 h-16 bg-emerald-500/10 border border-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-6 text-emerald-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-8 h-8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold bg-gradient-to-r from-emerald-400 to-indigo-400 bg-clip-text text-transparent">
            Installation Complete!
        </h1>
        <p class="text-slate-400 text-sm mt-2 mb-6">
            The workspace was registered and authenticated. Redirecting to your dashboard...
        </p>
        
        <!-- Fallback link in case redirect doesn't work -->
        <a href="{{ route('report.show', ['company' => $company->id]) }}" 
            class="inline-block mt-4 px-4 py-2 bg-indigo-500 hover:bg-indigo-600 text-white text-sm rounded-lg transition-colors">
            Go to Dashboard
        </a>
    </div>

    <script>
        // Initialize the JS SDK and finish the installation inside Bitrix24
        BX24.init(function() {
            console.log("Bitrix24 SDK Initialized. Finishing Installation...");
            BX24.installFinish();
        });

        // Redirect to the reports dashboard after 2 seconds
        setTimeout(function() {
            window.location.href = "{{ route('report.show', ['company' => $company->id]) }}";
        }, 2000);
    </script>
</body>
</html>