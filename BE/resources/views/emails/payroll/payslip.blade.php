<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu lương {{ $period_code }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
    <tr><td align="center" style="padding:30px 15px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.08)">
            @include('emails.recruitment.partials.brand-header', [
                'title' => 'Phiếu lương của bạn',
                'subtitle' => 'Kỳ ' . $period_code,
                'banner_color' => '#0f4c81',
                'subtitle_color' => '#dbeaf7',
            ])
            <tr><td style="padding:35px 40px">
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">Xin chào <strong>{{ $employee_name }}</strong>,</p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Phiếu lương kỳ <strong>{{ $period_code }}</strong> đã được phát hành. File PDF chính thức được đính kèm trong email này.
                </p>
                <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#56606b">
                    Vì đây là thông tin cá nhân, vui lòng không chuyển tiếp file cho người không liên quan.
                </p>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                    <tr><td bgcolor="#0f4c81" style="border-radius:6px">
                        <a href="{{ $portal_url }}" style="display:inline-block;padding:13px 24px;color:#fff;text-decoration:none;font-size:15px;font-weight:700">Xem trong tài khoản HRM</a>
                    </td></tr>
                </table>
                <p style="margin:30px 0 0;font-size:16px;line-height:1.7">Trân trọng,<br><strong>Bộ phận Nhân sự</strong><br>{{ $company_name }}</p>
            </td></tr>
            <tr><td style="padding:22px 40px;background:#f8f9fa;border-top:1px solid #e6e9ec">
                <p style="margin:0;font-size:12px;line-height:1.6;color:#888">Đây là email tự động về phiếu lương. Nội dung email không hiển thị số tiền; vui lòng xem file PDF hoặc đăng nhập hệ thống.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
