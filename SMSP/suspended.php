<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Suspended - Access Restricted</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #311042 100%);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Glowing background orbs -->
    <div class="absolute w-[300px] h-[300px] bg-purple-600/10 rounded-full blur-[100px] top-1/4 left-1/4 -translate-y-1/2 -translate-x-1/2 animate-pulse"></div>
    <div class="absolute w-[350px] h-[350px] bg-pink-600/10 rounded-full blur-[120px] bottom-1/4 right-1/4 translate-y-1/2 translate-x-1/2 animate-pulse"></div>

    <div class="w-full max-w-md bg-white/5 border border-white/10 rounded-3xl p-8 md:p-10 shadow-2xl backdrop-blur-xl text-center space-y-6 relative z-10 hover:border-white/20 transition-all duration-300">
        <div class="inline-flex items-center justify-center w-20 h-20 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl text-4xl shadow-lg animate-bounce">
            🔒
        </div>
        
        <div class="space-y-2">
            <h1 class="text-2xl font-extrabold text-white">Portal Suspended</h1>
            <p class="text-red-400 font-semibold text-sm">Access Temporarily Restricted</p>
        </div>

        <p class="text-xs text-gray-300 leading-relaxed">
            The license subscription for this portal has expired. General operations have been suspended temporarily. 
        </p>

        <div class="p-4 bg-white/5 border border-white/5 rounded-xl text-left text-xs space-y-2 text-gray-405">
            <p class="text-gray-400"><strong>What should I do?</strong></p>
            <p>• If you are a student, teacher, or parent, please contact the **School Administration** or principal's desk directly.</p>
            <p>• School Administrators can log in to renew the subscription online to restore portal access instantly.</p>
        </div>

        <div class="pt-4 flex flex-col gap-2.5">
            <a href="login.php" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all text-center">
                🔑 Administrator Login
            </a>
            <a href="/" class="w-full py-3 bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 rounded-xl font-semibold text-sm transition-all text-center">
                🏠 Go back to Homepage
            </a>
        </div>
    </div>
</body>
</html>
