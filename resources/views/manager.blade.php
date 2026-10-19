<!DOCTYPE html>
<html lang="en" @class(['dark' => ($appearance ?? 'system') === 'dark', 'embedded' => $boot['embed']])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <script>
        // The theme Vito shows. Inside Vito's page: whatever its <html> has, live. On its own: Vito's choice, which
        // Vito keeps in localStorage (the cookie only mirrors it and can be missing or stale).
        (function () {
            var root = document.documentElement;
            var system = window.matchMedia('(prefers-color-scheme: dark)');
            var host = null;
            try {
                if (window.parent !== window && window.parent.document.documentElement) host = window.parent.document.documentElement;
            } catch (e) {}
            function apply() {
                var dark;
                if (host) {
                    dark = host.classList.contains('dark');
                } else {
                    var saved = null;
                    try { saved = localStorage.getItem('appearance'); } catch (e) {}
                    var appearance = saved || @json($appearance ?? 'system');
                    dark = appearance === 'dark' || (appearance === 'system' && system.matches);
                }
                root.classList.toggle('dark', dark);
            }
            apply();
            if (host) {
                new MutationObserver(apply).observe(host, { attributes: true, attributeFilter: ['class'] });
            } else {
                system.addEventListener('change', apply);
                window.addEventListener('storage', function (e) { if (e.key === 'appearance') apply(); });
            }
        })();
    </script>
    <style>
        html { background-color: oklch(1 0 0); }
        html.dark { background-color: oklch(0.145 0 0); }
    </style>
    <link rel="icon" href="{{ asset('favicon/favicon-96x96.png') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <link rel="stylesheet" href="{{ $css }}">
</head>
<body>
    <div id="vdbm"></div>
    <script type="application/json" id="vdbm-boot">{!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
    <script src="{{ $js }}" defer></script>
</body>
</html>
