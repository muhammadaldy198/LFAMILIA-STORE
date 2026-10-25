<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('code') — {{ config('app.name', 'LFAMILIA STORE') }}</title>
    <style>
        :root { color-scheme: dark; }
        body { margin:0;min-height:100vh;display:grid;place-items:center;background:#0c111a;color:#edf3ff;font-family:system-ui,sans-serif;padding:20px;box-sizing:border-box; }
        main { width:min(100%,480px);text-align:center;background:#111a2a;border:1px solid #25344a;border-radius:20px;padding:36px 24px;box-sizing:border-box; }
        .code { font-size:72px;font-weight:850;color:#609cff;margin:0;letter-spacing:-.06em; }
        h1 { font-size:25px;margin:12px 0; } p { line-height:1.7;color:#aabbd3; }
        a { display:inline-block;margin-top:20px;padding:12px 20px;background:#609cff;color:#0c111a;font-weight:750;text-decoration:none;border-radius:10px; }
    </style>
</head>
<body>
    <main>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="/">Kembali ke beranda</a>
    </main>
</body>
</html>
