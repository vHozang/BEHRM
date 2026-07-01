<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request: Validate dữ liệu check-in trước khi vào Controller.
 * Tách validation ra khỏi Controller để tuân thủ Single Responsibility Principle.
 */
class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ở đây có thể thêm logic phân quyền (kiểm tra nhân viên chỉ check-in cho chính mình)
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'source' => ['nullable', 'string', 'in:API,FACE_ID,QR_CODE,MANUAL'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'location_code' => ['nullable', 'string', 'max:50'],
            // metadata là JSONB, validate là array hợp lệ
            'metadata' => ['nullable', 'array'],
            'metadata.gps' => ['nullable', 'array'],
            'metadata.gps.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'metadata.gps.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'Mã nhân viên là bắt buộc.',
            'employee_id.exists' => 'Nhân viên không tồn tại trong hệ thống.',
            'source.in' => 'Nguồn chấm công không hợp lệ.',
        ];
    }
}
