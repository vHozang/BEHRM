<?php

use App\Models\Employee;

$admin = Employee::where('employee_code', 'AD0001')->first();
$managers = Employee::where('id', '!=', $admin->id)->take(4)->get();

foreach ($managers as $mgr) {
    $mgr->manager_id = $admin->id;
    $mgr->save();
}

$employees = Employee::where('id', '!=', $admin->id)->whereNotIn('id', $managers->pluck('id'))->get();
foreach ($employees as $idx => $emp) {
    $mgrIndex = $idx % 4;
    $emp->manager_id = $managers[$mgrIndex]->id;
    $emp->save();
}

echo "Manager IDs assigned successfully.\n";
