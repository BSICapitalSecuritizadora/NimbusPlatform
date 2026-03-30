<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('nimbus_documents');
Schema::dropIfExists('nimbus_announcements');
Schema::dropIfExists('nimbus_submission_tags');
Schema::dropIfExists('nimbus_tags');
Schema::dropIfExists('nimbus_submission_notes');
Schema::dropIfExists('nimbus_submission_file_versions');
Schema::dropIfExists('nimbus_submission_files');
Schema::dropIfExists('nimbus_submission_shareholders');
Schema::dropIfExists('nimbus_submissions');
echo "Tables dropped\n";
