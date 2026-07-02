<?php

$content = file_get_contents('resources/views/livewire/proposals/continuation-form.blade.php');
$content = str_replace('bg-[var(--gold)]', 'bg-gold-500', $content);
$content = str_replace('text-[var(--brand)]', 'text-brand-800', $content);
file_put_contents('resources/views/livewire/proposals/continuation-form.blade.php', $content);
echo 'Form leftovers fixed!';
