<?php

$content = file_get_contents('resources/views/site/proposal/partials/summary.blade.php');
$content = str_replace('bg-[var(--gold)]', 'bg-gold-500', $content);
file_put_contents('resources/views/site/proposal/partials/summary.blade.php', $content);
echo 'Gold dots fixed!';
