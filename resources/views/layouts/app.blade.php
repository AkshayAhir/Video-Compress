<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VideoShrink') }} - Local Video Compression</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="app-header">
        <div class="container header-container">
            <div class="logo-area">
                <div class="logo-icon">🎬</div>
                <div>
                    <h1 class="logo-title">VideoShrink</h1>
                    <p class="logo-subtitle">Local H.264 Video Compressor & Optimizer</p>
                </div>
            </div>
            <div class="header-badges">
                <span class="badge badge-cpu">⚡ CPU libx264</span>
                <span class="badge badge-local">🔒 100% Local & Offline</span>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            @yield('content')
        </div>
    </main>

    <footer class="app-footer">
        <div class="container footer-container">
            <p>VideoShrink &bull; Runs completely on your local computer &bull; Powered by Laravel, FFmpeg &amp; libx264</p>
        </div>
    </footer>

    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>

