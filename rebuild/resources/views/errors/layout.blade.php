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
        .actions { display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin-top:22px; }
        a { display:inline-block;padding:12px 16px;background:#609cff;color:#0c111a;font-weight:750;text-decoration:none;border-radius:10px; }
        a.secondary { background:#1a2638;color:#edf3ff;border:1px solid #37465e; }
    </style>
</head>
<body>
    <main>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions"><a href="{{ route('guest.orders.lookup') }}">Cek pesanan</a><a class="secondary" href="{{ route('catalog.index') }}">Kembali ke beranda</a></div>
    </main>
</body>
</html>
