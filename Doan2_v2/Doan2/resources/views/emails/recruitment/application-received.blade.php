<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cảm ơn bạn đã ứng tuyển</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
    <tr><td align="center" style="padding:30px 15px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.08)">
            <tr><td align="center" style="background:#0f4c81;padding:28px 30px">
                <h1 style="margin:0;color:#fff;font-size:24px;line-height:1.4">Cảm ơn bạn đã ứng tuyển</h1>
                <p style="margin:8px 0 0;color:#dbeaf7;font-size:15px">{{ $company_name }}</p>
            </td></tr>
            <tr><td style="padding:35px 40px">
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">Xin chào <strong>{{ $candidate_name }}</strong>,</p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Cảm ơn bạn đã quan tâm và gửi hồ sơ ứng tuyển vị trí <strong>{{ $position_name }}</strong>
                    tại <strong>{{ $company_name }}</strong>.
                </p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Chúng tôi xác nhận đã nhận được CV của bạn. Bộ phận Tuyển dụng sẽ xem xét kinh nghiệm,
                    kỹ năng và mức độ phù hợp với yêu cầu của vị trí.
                </p>
                <div style="margin:25px 0;padding:18px 20px;background:#f2f7fb;border-left:4px solid #0f4c81;border-radius:4px">
                    <p style="margin:0;font-size:15px;line-height:1.6;color:#445566">
                        Thời gian phản hồi dự kiến: <strong>{{ $response_days }} ngày làm việc</strong> kể từ ngày nhận hồ sơ.
                    </p>
                </div>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Nếu hồ sơ phù hợp, chúng tôi sẽ liên hệ qua email hoặc số điện thoại bạn đã cung cấp để trao đổi bước tiếp theo.
                </p>
                <p style="margin:30px 0 0;font-size:16px;line-height:1.7">
                    Trân trọng,<br><strong>{{ $recruiter_name }}</strong><br>{{ $recruiter_title }} - {{ $company_name }}
                </p>
            </td></tr>
            <tr><td style="padding:24px 40px;background:#f8f9fa;border-top:1px solid #e6e9ec">
                <p style="margin:0 0 8px;font-size:14px;color:#555"><strong>{{ $company_name }}</strong></p>
                <p style="margin:0;font-size:13px;line-height:1.7;color:#777">
                    @if($company_address)Địa chỉ: {{ $company_address }}<br>@endif
                    @if($company_phone)Điện thoại: {{ $company_phone }}<br>@endif
                    Email: <a href="mailto:{{ $recruitment_email }}" style="color:#0f4c81;text-decoration:none">{{ $recruitment_email }}</a><br>
                    Website: <a href="{{ $company_website }}" style="color:#0f4c81;text-decoration:none">{{ $company_website }}</a>
                </p>
                <p style="margin:18px 0 0;font-size:12px;line-height:1.6;color:#999">Đây là email xác nhận tự động từ hệ thống tuyển dụng.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
