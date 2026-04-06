<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$columns = DB::select('DESCRIBE proposal_projects');
foreach ($columns as $column) {
    echo $column->Field."\n";
}
