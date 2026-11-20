{{--
    Booking confirmation e-mail (Part B).

    Inline styles only — Gmail and most desktop clients strip <style>
    blocks. Colours follow the application's sky-600 / slate palette so
    the message reads as the same product as the dashboard. Rendered by
    App\Mail\BookingConfirmationMail; every string arrives already
    translated for lang/en or lang/ar.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $strings['subject'] }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:#0284c7;padding:24px 32px;">
                            <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;">{{ $clinicName }}</p>
                            <p style="margin:6px 0 0;font-size:14px;color:#e0f2fe;">{{ $strings['subject'] }}</p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 12px;font-size:15px;font-weight:600;color:#0f172a;">{{ $greeting }}</p>

                            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#475569;">{{ $strings['intro'] }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e2e8f0;border-radius:12px;border-collapse:collapse;">
                                @foreach ($rows as $label => $value)
                                    <tr>
                                        <td style="padding:12px 16px;font-size:14px;color:#64748b;width:38%;border-bottom:{{ $loop->last ? '0' : '1' }}px solid #e2e8f0;">{{ $label }}</td>
                                        <td style="padding:12px 16px;font-size:14px;font-weight:600;color:#0f172a;border-bottom:{{ $loop->last ? '0' : '1' }}px solid #e2e8f0;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            @if (!empty($strings['closing']))
                                <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#475569;">{{ $strings['closing'] }}</p>
                            @endif
                        </td>
                    </tr>

                    {{-- Footer --}}
                    @if (!empty($strings['footer']))
                        <tr>
                            <td style="padding:16px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                                <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">{{ $strings['footer'] }}</p>
                            </td>
                        </tr>
                    @endif

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
