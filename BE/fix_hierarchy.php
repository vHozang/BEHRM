<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Reset all to null first
DB::table('employees')->update(['manager_id' => null]);

$employees = DB::table('employees')->get()->keyBy('id');

$giamDoc = $employees->where('position_id', 1)->first(); // ID 9
$phoGiamDoc = $employees->where('position_id', 2)->first(); // ID 5

if ($phoGiamDoc && $giamDoc) {
    DB::table('employees')->where('id', $phoGiamDoc->id)->update(['manager_id' => $giamDoc->id]);
}

// System Admin reports to Giám đốc
DB::table('employees')->where('id', 21)->update(['manager_id' => $giamDoc ? $giamDoc->id : null]);

// Trưởng phòng reports to Phó Giám đốc or Giám đốc
$truongPhongs = $employees->where('position_id', 3);
foreach ($truongPhongs as $tp) {
    $manager = $phoGiamDoc ? $phoGiamDoc->id : ($giamDoc ? $giamDoc->id : null);
    DB::table('employees')->where('id', $tp->id)->update(['manager_id' => $manager]);
}

// Phó phòng reports to Trưởng phòng of the same department
$phoPhongs = $employees->where('position_id', 4);
foreach ($phoPhongs as $pp) {
    $tp = $truongPhongs->where('department_id', $pp->department_id)->first();
    $manager = $tp ? $tp->id : ($phoGiamDoc ? $phoGiamDoc->id : null);
    DB::table('employees')->where('id', $pp->id)->update(['manager_id' => $manager]);
}

// Staff (position > 4 or null) reports to Phó phòng or Trưởng phòng of the same department
$staffs = $employees->filter(function ($e) {
    return $e->position_id > 4 || is_null($e->position_id);
})->reject(function ($e) {
    return $e->id == 21 || $e->position_id == 1 || $e->position_id == 2;
});

foreach ($staffs as $staff) {
    // try to find manager in the same department
    $managerId = null;
    if ($staff->department_id) {
        $pp = $phoPhongs->where('department_id', $staff->department_id)->first();
        if ($pp) {
            $managerId = $pp->id;
        } else {
            $tp = $truongPhongs->where('department_id', $staff->department_id)->first();
            if ($tp) {
                $managerId = $tp->id;
            }
        }
    }

    // If no manager found in dept, report to phó giám đốc
    if (! $managerId) {
        $managerId = $phoGiamDoc ? $phoGiamDoc->id : ($giamDoc ? $giamDoc->id : null);
    }

    DB::table('employees')->where('id', $staff->id)->update(['manager_id' => $managerId]);
}

echo "Hierarchy fixed!\n";
