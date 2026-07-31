<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết quả tuyển dụng</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f6f8">
    <tr><td align="center" style="padding:30px 15px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:640px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.08)">
            <tr><td align="center" style="background:#475569;padding:30px 35px">
                <h1 style="margin:0;color:#fff;font-size:24px;line-height:1.4">Thông báo kết quả tuyển dụng</h1>
                <p style="margin:8px 0 0;color:#e2e8f0;font-size:15px">Vị trí {{ $position_name }}</p>
            </td></tr>
            <tr><td style="padding:35px 40px">
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">Xin chào <strong>{{ $candidate_name }}</strong>,</p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Cảm ơn bạn đã quan tâm và dành thời gian tham gia quy trình tuyển dụng vị trí
                    <strong>{{ $position_name }}</strong> tại <strong>{{ $company_name }}</strong>.
                </p>
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Sau khi cân nhắc kỹ, chúng tôi rất tiếc chưa thể tiếp tục đồng hành cùng bạn ở vị trí này trong đợt tuyển dụng hiện tại.
                </p>
                @if($rejection_reason)
                    <div style="margin:25px 0;padding:18px 20px;background:#f8fafc;border-left:4px solid #475569;border-radius:4px">
                        <p style="margin:0;font-size:15px;line-height:1.7;color:#475569"><strong>Phản hồi từ bộ phận Tuyển dụng:</strong><br>{{ $rejection_reason }}</p>
                    </div>
                @endif
                <p style="margin:0 0 20px;font-size:16px;line-height:1.7">
                    Quyết định này chỉ phản ánh mức độ phù hợp với nhu cầu của vị trí ở thời điểm hiện tại. Chúng tôi trân trọng hồ sơ của bạn
                    và hy vọng có cơ hội kết nối khi xuất hiện vị trí phù hợp hơn trong tương lai.
                </p>
                <p style="margin:28px 0 0;font-size:16px;line-height:1.7">
                    Trân trọng,<br><strong>{{ $recruiter_name }}</strong><br>{{ $recruiter_title }} - {{ $company_name }}
                </p>
            </td></tr>
            <tr><td style="padding:24px 40px;background:#f8f9fa;border-top:1px solid #e6e9ec;font-size:13px;line-height:1.7;color:#777">
                <strong>{{ $company_name }}</strong><br>
                @if($company_address){{ $company_address }}<br>@endif
                Email: <a href="mailto:{{ $recruitment_email }}" style="color:#475569">{{ $recruitment_email }}</a>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
