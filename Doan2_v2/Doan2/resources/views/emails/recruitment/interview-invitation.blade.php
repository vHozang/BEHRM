<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thư mời phỏng vấn</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
    <tr><td align="center" style="padding:30px 15px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.08)">
            <tr><td align="center" style="background:#0f4c81;padding:30px 35px">
                <h1 style="margin:0;color:#fff;font-size:24px;line-height:1.4">Thư mời phỏng vấn</h1>
                <p style="margin:8px 0 0;color:#dbeaf7;font-size:15px">Vị trí {{ $position_name }} tại {{ $company_name }}</p>
            </td></tr>
            <tr><td style="padding:35px 40px">
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">Xin chào <strong>{{ $candidate_name }}</strong>,</p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Sau khi xem xét hồ sơ, chúng tôi nhận thấy kinh nghiệm và kỹ năng của bạn phù hợp với yêu cầu ban đầu.
                    {{ $company_name }} trân trọng mời bạn tham gia buổi phỏng vấn cho vị trí <strong>{{ $position_name }}</strong>.
                </p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:25px 0;background:#f2f7fb;border-left:4px solid #0f4c81;border-radius:4px">
                    <tr><td style="padding:20px 22px">
                        <h2 style="margin:0 0 15px;color:#0f4c81;font-size:18px">Thông tin buổi phỏng vấn</h2>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr><td style="width:145px;padding:5px 0;color:#555"><strong>Vị trí:</strong></td><td style="padding:5px 0">{{ $position_name }}</td></tr>
                            <tr><td style="padding:5px 0;color:#555"><strong>Thời gian:</strong></td><td style="padding:5px 0">{{ $interview_time }}, ngày {{ $interview_date }}</td></tr>
                            <tr><td style="padding:5px 0;color:#555"><strong>Hình thức:</strong></td><td style="padding:5px 0">{{ $interview_mode }}</td></tr>
                            @if($interview_location)<tr><td style="padding:5px 0;color:#555"><strong>Địa điểm:</strong></td><td style="padding:5px 0">{{ $interview_location }}</td></tr>@endif
                            @if($meeting_link)<tr><td style="padding:5px 0;color:#555"><strong>Link tham dự:</strong></td><td style="padding:5px 0"><a href="{{ $meeting_link }}" style="color:#0f4c81">{{ $meeting_link }}</a></td></tr>@endif
                            <tr><td style="padding:5px 0;color:#555"><strong>Người phỏng vấn:</strong></td><td style="padding:5px 0">{{ $interviewer_name }}</td></tr>
                            <tr><td style="padding:5px 0;color:#555"><strong>Thời lượng:</strong></td><td style="padding:5px 0">Khoảng {{ $duration_minutes }} phút</td></tr>
                        </table>
                    </td></tr>
                </table>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Bạn vui lòng phản hồi trước <strong>{{ $confirmation_deadline }}</strong> để xác nhận tham dự.
                    Nếu thời gian chưa phù hợp, hãy trả lời email này để đề xuất khung giờ khác.
                </p>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:28px auto">
                    <tr><td align="center" bgcolor="#0f4c81" style="border-radius:5px">
                        <a href="mailto:{{ $recruitment_email }}?subject=Xác nhận tham dự phỏng vấn vị trí {{ rawurlencode($position_name) }}" style="display:inline-block;padding:13px 24px;color:#fff;font-size:15px;font-weight:bold;text-decoration:none">Xác nhận tham dự</a>
                    </td></tr>
                </table>
                <p style="margin:24px 0 0;font-size:16px;line-height:1.7">
                    Trân trọng,<br><strong>{{ $recruiter_name }}</strong><br>{{ $recruiter_title }} - {{ $company_name }}
                </p>
            </td></tr>
            <tr><td style="padding:24px 40px;background:#f8f9fa;border-top:1px solid #e6e9ec;font-size:13px;line-height:1.7;color:#777">
                <strong>{{ $company_name }}</strong><br>
                @if($company_address){{ $company_address }}<br>@endif
                Email: <a href="mailto:{{ $recruitment_email }}" style="color:#0f4c81">{{ $recruitment_email }}</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
