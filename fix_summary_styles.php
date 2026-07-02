<?php

$content = file_get_contents('resources/views/site/proposal/partials/summary.blade.php');

$replacements = [
    '[background:linear-gradient(180deg,_rgba(255,255,255,0.55),_transparent_180px),radial-gradient(1100px_420px_at_50%_-8%,_rgba(0,32,91,0.10),_transparent_72%),var(--bg)]' => 'bsi-investor-body',
    'bg-green-50 text-green-800 border border-green-200' => 'bg-emerald-50 text-emerald-800 border border-emerald-200',
    'bg-red-50 text-red-800 border border-red-200' => 'bg-rose-50 text-rose-800 border border-rose-200',
    'relative overflow-hidden rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] [background:linear-gradient(145deg,_color-mix(in_oklab,_var(--surface)_95%,_white_5%),_color-mix(in_oklab,_var(--surface)_88%,_var(--brand)_12%))]' => 'relative overflow-hidden bsi-investor-header-surface',
    'bg-gradient-to-b from-[var(--gold)] to-[var(--brand)]' => 'bg-gradient-to-b from-gold-500 to-brand-700',
    'bg-gradient-to-r from-transparent via-[color-mix(in_oklab,var(--gold)_55%,var(--brand)_45%)] to-transparent' => 'bg-gradient-to-r from-transparent via-gold-500 to-transparent',
    'text-[0.78rem] font-bold tracking-[0.12em] uppercase text-[var(--gold)]' => 'bsi-kicker',
    'text-[clamp(2rem,3vw,2.8rem)] font-extrabold tracking-[-0.03em] text-[var(--brand)]' => 'text-[clamp(2rem,3vw,2.8rem)] bsi-heading',
    'text-[var(--muted)]' => 'bsi-copy',
    'text-[0.76rem] font-bold tracking-[0.08em] uppercase text-[var(--muted)]' => 'text-xs font-bold tracking-widest uppercase text-brand-500',
    'border-[color-mix(in_oklab,var(--gold)_30%,var(--border)_70%)] bg-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)] text-[var(--brand)] font-bold' => 'border-gold-200 bg-gold-50 text-brand-800 font-bold',
    'bg-[var(--gold)] shadow-[0_0_0_0.35rem_rgba(212,175,55,0.18)]' => 'bg-gold-500 shadow-[0_0_0_0.35rem_rgba(160,110,40,0.18)]',
    'h-full p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_94%,var(--brand)_6%)]' => 'h-full p-5 bsi-shell-card-soft',
    'text-[var(--brand)] font-bold' => 'text-brand-800 font-bold',
    'rounded-[30px] border border-[var(--border)] shadow-[0_20px_45px_rgba(0,32,91,0.08)] bg-[var(--surface,#fff)]' => 'bsi-investor-form-card mb-4',
    'text-[1.65rem] font-extrabold tracking-[-0.03em] text-[var(--brand)]' => 'text-2xl bsi-heading',
    'text-[1.7rem] font-extrabold tracking-[-0.03em] text-[var(--brand)]' => 'text-2xl bsi-heading',
    'p-[1.2rem_1.25rem] rounded-3xl border border-[color-mix(in_oklab,var(--gold)_18%,var(--border)_82%)] bg-gradient-to-br from-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] to-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)]' => 'p-5 rounded-3xl border border-gold-200 bg-gradient-to-br from-brand-50 to-gold-50',
    'h4 text-[var(--brand)] font-extrabold tracking-[-0.03em]' => 'text-xl bsi-heading',
    'border-[var(--border)] opacity-100' => 'border-zinc-200/80',
    'overflow-hidden border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_96%,var(--brand)_4%)]' => 'overflow-hidden bsi-shell-card-soft',
    'h5 text-[var(--brand)] font-extrabold tracking-[-0.03em]' => 'text-lg bsi-heading',
    'bg-[color-mix(in_oklab,var(--brand)_8%,var(--surface)_92%)] text-[var(--brand)] text-[0.76rem] font-bold tracking-[0.08em] uppercase' => 'bg-brand-50 text-brand-700 text-xs font-bold tracking-wider uppercase',
    'shadow-[0_0_0_0.3rem_rgba(212,175,55,0.15)]' => 'shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]',
    'text-[var(--text)]' => 'text-brand-900',
    'p-[1.15rem_1.2rem] border border-[var(--border)] rounded-[22px] bg-[color-mix(in_oklab,var(--surface)_95%,var(--brand)_5%)]' => 'p-5 border border-zinc-200/80 rounded-[22px] bg-brand-50/50',
    'hover:border-[color-mix(in_oklab,var(--gold)_35%,var(--border)_65%)] hover:shadow-[0_18px_34px_rgba(0,32,91,0.08)]' => 'hover:border-gold-500/50 hover:bg-gold-400/10 hover:shadow-sm',
    '<strong class="text-[var(--brand)]">' => '<strong class="text-brand-800">',
    'border-[var(--border)] p-[1rem_1.1rem]' => 'border-zinc-200/80 p-4',
    'text-[1.45rem] font-extrabold tracking-[-0.03em] text-[var(--brand)]' => 'text-[1.45rem] font-extrabold tracking-[-0.03em] text-brand-800',
    'border-[color-mix(in_oklab,var(--gold)_30%,var(--border)_70%)] bg-[color-mix(in_oklab,var(--gold)_10%,var(--surface)_90%)] text-[var(--brand)] font-bold' => 'border-gold-200 bg-gold-50 font-bold text-brand-800',
    'text-[var(--brand)]' => 'text-brand-800',
];

foreach ($replacements as $search => $replace) {
    $content = str_replace($search, $replace, $content);
}

file_put_contents('resources/views/site/proposal/partials/summary.blade.php', $content);
echo 'Summary styles updated successfully!';
