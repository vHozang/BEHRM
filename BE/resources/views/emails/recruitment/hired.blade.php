<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thư mời nhận việc</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
    <tr><td align="center" style="padding:30px 15px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.08)">
            @include('emails.recruitment.partials.brand-header', [
                'title' => 'Chúc mừng bạn đã trúng tuyển',
                'subtitle' => 'Vị trí '.$position_name,
                'banner_color' => '#166534',
                'subtitle_color' => '#dcfce7',
            ])
            <tr><td style="padding:35px 40px">
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">Xin chào <strong>{{ $candidate_name }}</strong>,</p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Cảm ơn bạn đã dành thời gian tham gia quy trình tuyển dụng. Chúng tôi vui mừng thông báo bạn đã được lựa chọn
                    cho vị trí <strong>{{ $position_name }}</strong> tại <strong>{{ $company_name }}</strong>.
                </p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:25px 0;background:#f0fdf4;border-left:4px solid #166534;border-radius:4px">
                    <tr><td style="padding:20px 22px">
                        <h2 style="margin:0 0 15px;color:#166534;font-size:18px">Thông tin nhận việc</h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr><td style="width:155px;padding:5px 0;color:#555"><strong>Vị trí:</strong></td><td style="padding:5px 0">{{ $position_name }}</td></tr>
                            <tr><td style="padding:5px 0;color:#555"><strong>Ngày bắt đầu:</strong></td><td style="padding:5px 0">{{ $start_date }}</td></tr>
                            <tr><td style="padding:5px 0;color:#555"><strong>Thời gian có mặt:</strong></td><td style="padding:5px 0">{{ $arrival_time }}</td></tr>
                            @if($work_location)<tr><td style="padding:5px 0;color:#555"><strong>Địa điểm:</strong></td><td style="padding:5px 0">{{ $work_location }}</td></tr>@endif
                        </table>
                    </td></tr>
                </table>
                @if($offer_note)<div style="margin:0 0 20px;padding:16px 18px;background:#f8fafc;border-radius:4px;line-height:1.7">{{ $offer_note }}</div>@endif
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Bạn vui lòng trả lời email này để xác nhận nhận việc. Bộ phận Tuyển dụng sẽ tiếp tục hướng dẫn hồ sơ và giấy tờ cần chuẩn bị.
                </p>
                <p style="margin:28px 0 0;font-size:16px;line-height:1.7">
                    Trân trọng,<br><strong>{{ $recruiter_name }}</strong><br>{{ $recruiter_title }} - {{ $company_name }}
                </p>
            </td></tr>
            <tr><td style="padding:24px 40px;background:#f8f9fa;border-top:1px solid #e6e9ec;font-size:13px;line-height:1.7;color:#777">
                <strong>{{ $company_name }}</strong><br>
                @if($company_address){{ $company_address }}<br>@endif
                Email: <a href="mailto:{{ $recruitment_email }}" style="color:#166534">{{ $recruitment_email }}</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
