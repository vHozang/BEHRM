<?php

use App\Repositories\OrganizationChartRepository;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$repo = new OrganizationChartRepository;
$tree = $repo->getNestedTree();
echo json_encode($tree, JSON_PRETTY_PRINT);
