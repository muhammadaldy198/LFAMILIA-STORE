<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#e8ebee;font-family:Arial,Helvetica,sans-serif;color:#333333">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#e8ebee">
<tr><td align="center" style="padding:18px 10px 30px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="640" style="width:100%;max-width:640px;border-collapse:separate;background:#ffffff;border:1px solid #d9dee3;border-radius:8px">
<tr><td style="padding:28px 30px 22px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td align="left"><img src="{{ $logoUrl }}" alt="{{ $store }}" width="168" height="31" style="display:block;width:168px;max-width:100%;height:auto;border:0"></td></tr>
<tr><td style="padding-top:18px;font-size:20px;line-height:1.4;color:#111111;font-weight:bold">{{ $name !== '' ? 'Hai '.$name.',' : 'Hai,' }}</td></tr>
<tr><td style="padding-top:12px;font-size:14px;line-height:1.6;color:#333333">{{ $intro }}</td></tr>
<tr><td style="padding-top:13px;font-size:14px;line-height:1.7;color:#333333">{{ $metaDateLabel }}: {{ $metaDate }}</td></tr>
<tr><td style="font-size:14px;line-height:1.7;color:#333333">No. Transaksi: <strong>{{ $reference }}</strong></td></tr>
<tr><td style="padding:16px 0">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#f4f4f4;border-radius:6px">
<tr><td align="center" style="padding:16px 10px 4px;font-size:13px;color:#666666">{{ $highlightLabel }}</td></tr>
<tr><td align="center" style="padding:0 10px 16px;font-size:20px;font-weight:bold;color:#111111;overflow-wrap:anywhere">{{ $highlightValue }}</td></tr>
</table>
</td></tr>
<tr><td style="padding:16px 18px;border:1px solid #e2e2e2;border-radius:6px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td colspan="2" style="padding-bottom:10px;font-size:15px;color:#111111;font-weight:bold">{{ $summaryTitle }}</td></tr>
@foreach ($summary as $row)
<tr>
<td valign="top" style="padding:4px 12px 4px 0;font-size:14px;line-height:1.45;color:#333333{{ !empty($row['strong']) ? ';font-weight:bold' : '' }}">{{ $row['label'] }}</td>
<td valign="top" align="right" style="padding:4px 0;font-size:14px;line-height:1.45;text-align:right;overflow-wrap:anywhere;color:#333333{{ !empty($row['strong']) ? ';font-weight:bold' : '' }}">{{ $row['value'] }}</td>
</tr>
@endforeach
@foreach ($extra as $row)
<tr>
<td valign="top" style="padding:5px 12px 5px 0;font-size:14px;line-height:1.5;color:#333333">{{ $row['label'] }}</td>
<td valign="top" align="right" style="padding:5px 0;font-size:14px;line-height:1.5;text-align:right;overflow-wrap:anywhere;color:#333333">{{ $row['value'] }}</td>
</tr>
@endforeach
</table>
</td></tr>
<tr><td style="padding-top:16px">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
<td align="center" bgcolor="#2f7de1" style="border-radius:6px;padding:13px 10px"><a href="{{ $ctaUrl }}" style="display:block;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none">{{ $ctaLabel }}</a></td>
</tr></table>
</td></tr>
<tr><td style="padding-top:15px;font-size:14px;line-height:1.7;color:#333333">{{ $after }}</td></tr>
<tr><td style="padding-top:13px;font-size:14px;line-height:1.7;color:#333333">Untuk pertanyaan, silakan hubungi <a href="{{ $supportLink }}" style="color:#2f7de1;text-decoration:underline">Customer Service kami</a>.</td></tr>
<tr><td style="padding-top:10px;font-size:14px;line-height:1.6;color:#333333">Terima kasih atas perhatian dan kepercayaan kamu di {{ $store }}.</td></tr>
<tr><td style="padding-top:20px;font-size:12px;line-height:1.7;color:#888888">
Ini adalah email otomatis, mohon untuk tidak membalas email ini.
@if ($support !== '')
Untuk menghubungi kami, silakan hubungi <a href="mailto:{{ $support }}" style="color:#2f7de1">{{ $support }}</a>@if ($hours !== '') ({{ $hours }})@endif.
@elseif ($hours !== '')
Jam layanan: {{ $hours }}.
@endif
<br>LFAMILIA STORE tidak pernah meminta password atau kode OTP kamu.<br>
&copy; {{ $year }} {{ $store }}
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</body></html>
