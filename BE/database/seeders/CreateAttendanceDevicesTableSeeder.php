<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Sổ đăng ký MÁY CHẤM CÔNG theo tenant (đa-tenant device registry).
 *
 * Mỗi công ty tự khai báo (các) thiết bị họ đang dùng: hãng + phương thức kết
 * nối + thông số (IP/serial/api_key) + 1 device_token riêng để bridge xác thực.
 * API nhận punch là CHUNG; thiết bị/phương thức khác nhau chỉ khác cách dữ liệu
 * đi tới API. Idempotent: chỉ tạo bảng nếu chưa có.
 */
class CreateAttendanceDevicesTableSeeder extends Seeder
{
    public function run(): void
    {
        if (Schema::hasTable('attendance_devices')) {
            $this->command?->info('attendance_devices đã tồn tại — bỏ qua.');

            return;
        }

        Schema::create('attendance_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('legal_entity_id')->nullable();
            $t->string('name');                          // vd "Máy cổng chính"
            $t->string('brand')->default('zkteco');      // zkteco|wiseeye|hikvision|suprema|other
            $t->string('protocol')->default('zk_pull');  // zk_pull|adms_push|cloud_api|file_import
            $t->string('device_token', 64)->unique();    // bridge auth
            $t->string('status')->default('ACTIVE');     // ACTIVE|INACTIVE
            $t->string('location')->nullable();
            $t->timestamp('last_seen_at')->nullable();   // lần cuối gửi punch
            $t->jsonb('meta')->nullable();               // ip, port, serial, api_key, column_mapping...
            $t->timestamps();

            $t->index('tenant_id');
            $t->index('device_token');
            $t->index('status');
        });

        $this->command?->info('Đã tạo bảng attendance_devices.');
    }
}
