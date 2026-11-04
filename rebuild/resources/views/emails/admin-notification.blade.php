<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#e8ebee;font-family:Arial,Helvetica,sans-serif;color:#111">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#e8ebee;width:100%">
<tr><td align="center" style="padding:18px 10px 34px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="640" style="background:#fff;max-width:640px;width:100%;border:1px solid #d9dee3;border-radius:8px;border-collapse:separate">
<tr><td height="6" bgcolor="{{ $color }}" style="height:6px;font-size:1px;line-height:6px;background:{{ $color }}">&nbsp;</td></tr>
<tr><td style="padding:18px 22px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td><span style="display:inline-block;padding:4px 9px;border-radius:20px;background:{{ $color }};color:#fff;font-size:11px;font-weight:bold;letter-spacing:.06em">{{ $level }}</span></td></tr>
<tr><td style="padding:8px 0 2px;font-size:17px;font-weight:bold;color:#111;line-height:1.45">{{ $title }}</td></tr>
<tr><td style="font-size:12px;color:#888">{{ $wib }}</td></tr>
<tr><td style="padding:12px 0 4px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse">
@foreach ($fields as $fact)
<tr>
<td valign="top" width="38%" style="border-top:1px solid #eee;padding:7px 8px 7px 4px;font-size:13px;line-height:1.5;color:#777">{{ $fact['name'] }}</td>
<td valign="top" style="border-top:1px solid #eee;padding:7px 4px;font-size:13px;line-height:1.5;font-weight:bold;overflow-wrap:anywhere;color:#111">{!! nl2br(e($fact['value']), false) !!}</td>
</tr>
@endforeach
</table>
</td></tr>
@if ($action !== null && in_array($level, ['KRITIS', 'PERINGATAN'], true))
<tr><td style="padding:12px 0">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px">
<tr><td style="padding:10px 12px;font-size:13px;line-height:1.6;color:#7f1d1d"><strong>Yang harus dilakukan:</strong> {{ $action }}</td></tr>
</table>
</td></tr>
@endif
<tr><td style="padding-top:12px">
<a href="{{ $url }}" style="display:inline-block;padding:10px 16px;border-radius:6px;background:#111827;color:#ffffff;text-decoration:none;font-size:13px;font-weight:bold">Buka di Panel Admin</a>
</td></tr>
<tr><td style="padding-top:14px;font-size:11px;line-height:1.5;color:#999">Notifikasi otomatis LFAMILIA STORE — pesan yang sama dikirim ke Telegram, Discord, dan panel admin.</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
