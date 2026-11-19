<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('code') — {{ config('app.name', 'LFAMILIA STORE') }}</title>
    <style>
        @font-face { font-family:'Bricolage Grotesque';src:url('{{ asset('fonts/bricolage-grotesque.woff2') }}') format('woff2');font-weight:200 800;font-style:normal;font-display:swap; }
        @font-face { font-family:'Plus Jakarta Sans';src:url('{{ asset('fonts/plus-jakarta-sans.woff2') }}') format('woff2');font-weight:200 800;font-style:normal;font-display:swap; }
        * { margin:0;padding:0;box-sizing:border-box; }
        body { min-height:100vh;font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:#fff;color:#1F2A44;display:flex;flex-direction:column;position:relative;overflow-x:hidden; }
        .dot { position:absolute;border-radius:50%;opacity:.5; }
        .d1 { width:14px;height:14px;background:#F6C445;top:120px;left:6%; }
        .d2 { width:10px;height:10px;background:#EE4B4B;top:200px;right:8%; }
        .d3 { width:8px;height:8px;background:#4A90D9;top:430px;left:10%; }
        .d4 { width:12px;height:12px;background:#F6C445;top:520px;right:5%; }
        header { display:flex;align-items:center;gap:10px;padding:20px 28px;position:relative;z-index:2; }
        .logo { width:38px;height:38px;border-radius:12px;background:#EE4B4B;display:grid;place-items:center;color:#fff;font-family:'Bricolage Grotesque','Plus Jakarta Sans',system-ui,sans-serif;font-weight:800;font-size:20px;box-shadow:0 4px 0 #C93333; }
        .brand { font-family:'Bricolage Grotesque','Plus Jakarta Sans',system-ui,sans-serif;font-weight:800;font-size:19px;letter-spacing:.02em; }
        .brand span { color:#EE4B4B; }
        .pill { margin-left:auto;background:#1F2A44;color:#F6C445;font-size:12px;font-weight:700;padding:7px 13px;border-radius:999px;letter-spacing:.06em; }
        main { flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;padding:6px 22px 10px;position:relative;z-index:2; }
        .robot { width:min(480px,88vw);height:auto; }
        .badge { display:inline-flex;align-items:center;gap:8px;background:#fff;border:2px solid #1F2A44;border-radius:999px;padding:7px 15px;font-weight:700;font-size:13px;box-shadow:3px 3px 0 #1F2A44;margin-top:4px; }
        .badge i { width:9px;height:9px;border-radius:50%;background:#EE4B4B;display:inline-block; }
        h1 { font-family:'Bricolage Grotesque','Plus Jakarta Sans',system-ui,sans-serif;font-weight:800;font-size:clamp(34px,6vw,52px);line-height:1.06;letter-spacing:-.01em;margin:18px 0 12px;max-width:16ch; }
        h1 .red { color:#EE4B4B; }
        p.lead { font-size:16px;line-height:1.65;color:#4A5878;max-width:52ch; }
        .meta { display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin:20px 0 24px; }
        .chip { background:#fff;border:2px solid #F3E3CF;border-radius:14px;padding:9px 14px;font-size:13.5px;font-weight:600;color:#4A5878; }
        .chip b { color:#1F2A44; }
        footer { text-align:center;padding:18px 16px 20px;font-size:13px;color:#93A0B8;position:relative;z-index:2; }
    </style>
</head>
<body>
    <span class="dot d1"></span><span class="dot d2"></span><span class="dot d3"></span><span class="dot d4"></span>
    <header>
        <div class="logo">L</div>
        <div class="brand">LFAMILIA<span>.</span></div>
        <div class="pill">MAINTENANCE</div>
    </header>
    <main>
        <img class="robot" src="{{ asset('images/maintenance-robot.png') }}" alt="Robot LFAMILIA lagi benerin server" width="640" height="540">
        <span class="badge"><i></i>Server lagi diservis</span>
        <h1>Sabar ya, lagi <span class="red">dibenerin</span> bentar lagi beres!</h1>
        <p class="lead">@yield('message')</p>
        <div class="meta">
            <span class="chip">🛡️ Pesananmu <b>aman tersimpan</b></span>
        </div>
        {{-- Tanpa tombol navigasi (keputusan pemilik 19 Nov 2026): selama
             maintenance seluruh toko menjawab 503, jadi tombol ke lacak
             pesanan atau beranda hanya memutar pengunjung kembali ke
             halaman ini. --}}
    </main>
    <footer>© 2026 LFAMILIA STORE — Lagi dibenerin, bukan lagi kabur. 😄</footer>
</body>
</html>
