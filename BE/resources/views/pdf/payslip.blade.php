<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} {{ $period->period_code }}</title>
    <style>
        @page { margin: 10mm 11mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #20242a; font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; }
        .brand-line { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        .brand-line td { height: 4px; padding: 0; }
        .blue { background: #1c55a5; } .orange { background: #f17a28; } .green { background: #52b34b; }
        .company { text-align: center; font-size: 10px; font-weight: 700; }
        h1 { margin: 4px 0 2px; color: #164b8c; text-align: center; font-size: 16px; }
        .period { margin-bottom: 7px; text-align: center; font-size: 9px; }
        .info, .lines, .totals { width: 100%; border-collapse: collapse; }
        .info { margin-bottom: 7px; }
        .info td { border: 1px solid #9da8b3; padding: 3px 5px; }
        .info .label { width: 18%; color: #475569; font-weight: 700; background: #f4f7fa; }
        .lines th, .lines td { border: 1px solid #8b98a5; padding: 2.4px 4px; }
        .lines th { color: #fff; text-align: center; background: #164b8c; font-size: 8px; }
        .lines .section td { color: #164b8c; font-weight: 700; background: #eaf1f8; }
        .lines .index { width: 5%; text-align: center; }
        .lines .quantity { width: 14%; text-align: right; }
        .lines .amount { width: 23%; text-align: right; font-weight: 600; }
        .totals { margin-top: 6px; }
        .totals td { border: 1px solid #8b98a5; padding: 4px 6px; }
        .totals .label { font-weight: 700; background: #f4f7fa; }
        .totals .value { width: 30%; text-align: right; font-weight: 700; }
        .totals .net-label, .totals .net-value { color: #fff; background: #164b8c; font-size: 10px; }
        .footer { margin-top: 7px; color: #52606d; text-align: center; font-size: 7.5px; font-style: italic; }
        .meta { margin-top: 3px; color: #7b8794; text-align: right; font-size: 6.5px; }
    </style>
</head>
<body>
<table class="brand-line"><tr><td class="blue"></td><td class="orange"></td><td class="green"></td></tr></table>
<div class="company">{{ $legal_entity?->name ?: config('app.name', 'HRM System') }}</div>
@if($legal_entity?->address)<div style="text-align:center;color:#52606d">{{ $legal_entity->address }}</div>@endif
<h1>{{ $title }}</h1>
<div class="period">
    Kỳ {{ $period->period_code }} ·
    {{ \Carbon\Carbon::parse($period->start_date)->format('d/m/Y') }} -
    {{ \Carbon\Carbon::parse($period->end_date)->format('d/m/Y') }}
</div>

<table class="info">
    <tr>
        <td class="label">Họ và tên</td><td>{{ $employee->full_name }}</td>
        <td class="label">Mã nhân viên</td><td>{{ $employee->employee_code }}</td>
    </tr>
    <tr>
        <td class="label">Chức vụ</td><td>{{ $employee->position?->position_name ?: '-' }}</td>
        <td class="label">Phòng ban</td><td>{{ $employee->department?->department_name ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Công chuẩn</td><td>{{ rtrim(rtrim(number_format((float) ($attendance->standard_days ?? 0), 2, ',', ''), '0'), ',') }}</td>
        <td class="label">Công thực tế</td><td>{{ rtrim(rtrim(number_format((float) ($attendance->actual_working_days ?? 0), 2, ',', ''), '0'), ',') }}</td>
    </tr>
</table>

<table class="lines">
    <thead><tr><th>STT</th><th>Nội dung</th><th>Số ngày/giờ</th><th>Thành tiền (VND)</th></tr></thead>
    <tbody>
        <tr class="section"><td colspan="4">A. THU NHẬP</td></tr>
        @foreach($earnings as $index => $row)
            <tr>
                <td class="index">{{ $index + 1 }}</td>
                <td>{{ $row['label'] }}</td>
                <td class="quantity">{{ $row['quantity_formatted'] }}</td>
                <td class="amount">{{ $row['amount_formatted'] }}</td>
            </tr>
        @endforeach
        <tr class="section"><td colspan="4">B. KHẤU TRỪ</td></tr>
        @foreach($deductions as $index => $row)
            <tr>
                <td class="index">{{ $index + 1 }}</td>
                <td>{{ $row['label'] }}</td>
                <td class="quantity">0</td>
                <td class="amount">{{ $row['amount_formatted'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="label">TỔNG THU NHẬP</td><td class="value">{{ $gross_formatted }}</td></tr>
    <tr><td class="label">TỔNG KHẤU TRỪ</td><td class="value">{{ $total_deductions_formatted }}</td></tr>
    <tr><td class="label net-label">THỰC LĨNH</td><td class="value net-value">{{ $net_formatted }}</td></tr>
</table>

<div class="footer">{{ $footer }}</div>
<div class="meta">Mẫu {{ $template_version }} · Phát hành {{ $generated_label }}</div>
</body>
</html>
