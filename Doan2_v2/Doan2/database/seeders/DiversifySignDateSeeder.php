<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Đa dạng hoá ngày ký HĐ cho sát thực tế: phần lớn ký = ngày bắt đầu, một số
 * công ty ký TRƯỚC vài ngày rồi mới vào làm (vd ký 14 → vào làm 17).
 * sign_date = start_date - offset (theo id). Không bao giờ ký SAU ngày bắt đầu.
 */
class DiversifySignDateSeeder extends Seeder
{
    public function run(): void
    {
        // offset (số ngày ký trước ngày bắt đầu) theo id % 10 — mix 0..5
        $offsets = [0, 0, 3, 0, 2, 0, 5, 0, 1, 0];

        foreach (DB::table('contracts')->get() as $c) {
            if (! $c->start_date) {
                continue;
            }
            $offset = $offsets[$c->id % 10];
            $sign = Carbon::parse($c->start_date)->subDays($offset)->toDateString();

            $meta = $this->decode($c->meta);
            $meta['sign_date'] = $sign;
            DB::table('contracts')->where('id', $c->id)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    private function decode($p): array
    {
        if (! $p) {
            return [];
        }
        $d = is_string($p) ? json_decode($p, true) : (array) $p;

        return is_array($d) ? $d : [];
    }
}
