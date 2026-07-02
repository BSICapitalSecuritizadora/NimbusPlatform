import re

with open('resources/views/livewire/proposals/continuation-form.blade.php', 'r') as f:
    content = f.read()

replacements = {
    r'\[background:linear-gradient\(180deg,_rgba\(255,255,255,0.55\),_transparent_180px\),radial-gradient\(1100px_420px_at_50%_-8%,_rgba\(0,32,91,0.10\),_transparent_72%\),var\(--bg\)]': 'bsi-investor-body',
    r'bg-green-50 text-green-800 border border-green-200': 'bg-emerald-50 text-emerald-800 border border-emerald-200',
    r'bg-red-50 text-red-800 border border-red-200': 'bg-rose-50 text-rose-800 border border-rose-200',
    r'relative overflow-hidden rounded-\[30px\] border border-\[var\(--border\)\] shadow-\[0_20px_45px_rgba\(0,32,91,0.08\)\] \[background:linear-gradient\(145deg,_color-mix\(in_oklab,_var\(--surface\)_95%,_white_5%\),_color-mix\(in_oklab,_var\(--surface\)_88%,_var\(--brand\)_12%\)\)\]': 'relative overflow-hidden bsi-investor-header-surface',
    r'bg-gradient-to-b from-\[var\(--gold\)\] to-\[var\(--brand\)\]': 'bg-gradient-to-b from-gold-500 to-brand-700',
    r'text-\[0.78rem\] font-bold tracking-\[0.12em\] uppercase text-\[var\(--gold\)\]': 'bsi-kicker',
    r'text-\[clamp\(2rem,3vw,2.8rem\)\] font-extrabold tracking-\[-0.03em\] text-\[var\(--brand\)\]': 'text-[clamp(2rem,3vw,2.8rem)] bsi-heading',
    r'text-\[var\(--muted\)\]': 'bsi-copy',
    r'text-\[0.76rem\] font-bold tracking-\[0.08em\] uppercase text-\[var\(--muted\)\]': 'text-xs font-bold tracking-widest uppercase text-brand-500',
    r'border-\[color-mix\(in_oklab,var\(--gold\)_30%,var\(--border\)_70%\)\] bg-\[color-mix\(in_oklab,var\(--gold\)_10%,var\(--surface\)_90%\)\] font-bold text-\[var\(--brand\)\]': 'border-gold-200 bg-gold-50 font-bold text-brand-800',
    r'bg-\[var\(--gold\)\] shadow-\[0_0_0_0.35rem_rgba\(212,175,55,0.18\)\]': 'bg-gold-500 shadow-[0_0_0_0.35rem_rgba(160,110,40,0.18)]',
    r'h-full p-\[1.15rem_1.2rem\] border border-\[var\(--border\)\] rounded-\[22px\] bg-\[color-mix\(in_oklab,var\(--surface\)_94%,var\(--brand\)_6%\)\]': 'h-full p-5 bsi-shell-card-soft',
    r'text-\[var\(--brand\)\] font-bold': 'text-brand-800 font-bold',
    r'rounded-\[30px\] border border-\[var\(--border\)\] shadow-\[0_20px_45px_rgba\(0,32,91,0.08\)\] bg-\[var\(--surface,#fff\)\]': 'bsi-investor-form-card',
    r'text-\[1.65rem\] font-extrabold tracking-\[-0.03em\] text-\[var\(--brand\)\]': 'text-2xl bsi-heading',
    r'p-\[1.2rem_1.25rem\] rounded-3xl border border-\[color-mix\(in_oklab,var\(--gold\)_18%,var\(--border\)_82%\)\] bg-gradient-to-br from-\[color-mix\(in_oklab,var\(--brand\)_8%,var\(--surface\)_92%\)\] to-\[color-mix\(in_oklab,var\(--gold\)_10%,var\(--surface\)_90%\)\]': 'p-5 rounded-3xl border border-gold-200 bg-gradient-to-br from-brand-50 to-gold-50',
    r'h4 text-\[var\(--brand\)\] font-extrabold tracking-\[-0.03em\]': 'text-xl bsi-heading',
    r'border-\[var\(--border\)\] opacity-100': 'border-zinc-200/80',
    r'overflow-hidden border border-\[var\(--border\)\] rounded-\[22px\] bg-\[color-mix\(in_oklab,var\(--surface\)_96%,var\(--brand\)_4%\)\]': 'overflow-hidden bsi-shell-card-soft',
    r'h5 text-\[var\(--brand\)\] font-extrabold tracking-\[-0.03em\]': 'text-lg bsi-heading',
    r'bg-\[color-mix\(in_oklab,var\(--brand\)_8%,var\(--surface\)_92%\)\] text-\[var\(--brand\)\] text-\[0.76rem\] font-bold tracking-\[0.08em\] uppercase': 'bg-brand-50 text-brand-700 text-xs font-bold tracking-wider uppercase',
    r'shadow-\[0_0_0_0.3rem_rgba\(212,175,55,0.15\)\]': 'shadow-[0_0_0_0.3rem_rgba(160,110,40,0.15)]',
    r'<flux:button type="button" variant="outline"': '<button type="button" class="bsi-action-secondary"',
    r'<flux:button\s*type="button"\s*variant="outline"\s*wire:click="addProject">Adicionar Empreendimento</flux:button>': '<button type="button" class="bsi-action-secondary py-2 px-4 text-sm" wire:click="addProject">Adicionar Empreendimento</button>',
    r'<flux:button\s*type="button"\s*variant="outline"\s*wire:click="addUnitType">Adicionar Tipo</flux:button>': '<button type="button" class="bsi-action-secondary py-2 px-4 text-sm" wire:click="addUnitType">Adicionar Tipo</button>',
    r'text-\[var\(--text\)\]': 'text-brand-900',
}

# Fix buttons correctly
content = content.replace('<flux:button type="button" variant="outline" wire:click="addProject">Adicionar Empreendimento</flux:button>', '<button type="button" class="bsi-action-secondary py-2 px-4 text-sm" wire:click="addProject">Adicionar Empreendimento</button>')
content = content.replace('<flux:button type="button" variant="outline" wire:click="addUnitType">Adicionar Tipo</flux:button>', '<button type="button" class="bsi-action-secondary py-2 px-4 text-sm" wire:click="addUnitType">Adicionar Tipo</button>')

# For the main submit button, replace the flux:button with bsi-action-primary
submit_button = '''<button
                                            type="submit"
                                            class="bsi-action-primary px-6"
                                            wire:loading.attr="disabled"
                                            wire:target="save,uploads"
                                        >
                                            <span wire:loading.remove wire:target="save">Salvar Empreendimento(s)</span>
                                            <span wire:loading wire:target="save">Salvando...</span>
                                        </button>'''

content = re.sub(r'<flux:button\s*type="submit"\s*variant="primary"\s*wire:loading\.attr="disabled"\s*wire:target="save,uploads"\s*>\s*<span wire:loading\.remove wire:target="save">Salvar Empreendimento\(s\)</span>\s*<span wire:loading wire:target="save">Salvando\.\.\.</span>\s*</flux:button>', submit_button, content)


for pattern, replacement in replacements.items():
    content = re.sub(pattern, replacement, content)

with open('resources/views/livewire/proposals/continuation-form.blade.php', 'w') as f:
    f.write(content)
