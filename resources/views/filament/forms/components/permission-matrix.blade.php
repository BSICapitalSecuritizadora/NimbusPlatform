@php
    use App\Enums\AccessPermission;
    use Spatie\Permission\Models\Permission;

    $allPermissions = Permission::query()
        ->whereIn('name', AccessPermission::values())
        ->orderBy('name')
        ->get();

    $modules = [];
    foreach ($allPermissions as $perm) {
        $enum = AccessPermission::tryFrom($perm->name);
        $moduleName = $enum?->module() ?? 'Outros';
        $modules[$moduleName][] = [
            'id' => (int) $perm->id,
            'code' => $perm->name,
            'label' => $enum?->label() ?? $perm->name,
            'is_critical' => $enum?->isCritical() ?? false,
        ];
    }

    $totalPermissionsCount = $allPermissions->count();
    $statePath = $getStatePath();
@endphp

<div
    x-data="{
        search: '',
        openModules: {},
        allExpanded: true,
        selectedIds: @entangle($statePath).live,
        initialSelectedIds: [],

        init() {
            this.initialSelectedIds = Array.isArray(this.selectedIds) ? [...this.selectedIds].map(String) : [];
            @foreach(array_keys($modules) as $modKey)
                this.openModules['{{ addslashes($modKey) }}'] = true;
            @endforeach
        },

        get addedCount() {
            if (!Array.isArray(this.selectedIds)) return 0;
            const currentStr = this.selectedIds.map(String);
            return currentStr.filter(id => !this.initialSelectedIds.includes(id)).length;
        },

        get removedCount() {
            if (!Array.isArray(this.selectedIds)) return 0;
            const currentStr = this.selectedIds.map(String);
            return this.initialSelectedIds.filter(id => !currentStr.includes(id)).length;
        },

        isModuleOpen(module) {
            if (this.search && this.search.trim() !== '') {
                return true;
            }
            return !!this.openModules[module];
        },

        toggleModule(module) {
            this.openModules[module] = !this.openModules[module];
        },

        expandAll() {
            this.allExpanded = true;
            @foreach(array_keys($modules) as $modKey)
                this.openModules['{{ addslashes($modKey) }}'] = true;
            @endforeach
        },

        collapseAll() {
            this.allExpanded = false;
            @foreach(array_keys($modules) as $modKey)
                this.openModules['{{ addslashes($modKey) }}'] = false;
            @endforeach
        },

        selectAllModule(ids) {
            let current = Array.isArray(this.selectedIds) ? [...this.selectedIds] : [];
            ids.forEach(id => {
                const strId = String(id);
                const numId = Number(id);
                const exists = current.some(item => String(item) === strId || Number(item) === numId);
                if (!exists) {
                    current.push(id);
                }
            });
            this.selectedIds = current;
        },

        clearModule(ids) {
            if (!Array.isArray(this.selectedIds)) return;
            const strIds = ids.map(id => String(id));
            const numIds = ids.map(id => Number(id));
            this.selectedIds = this.selectedIds.filter(item => !strIds.includes(String(item)) && !numIds.includes(Number(item)));
        },

        getSelectedCount(ids) {
            if (!Array.isArray(this.selectedIds)) return 0;
            const strIds = ids.map(id => String(id));
            const numIds = ids.map(id => Number(id));
            return this.selectedIds.filter(item => strIds.includes(String(item)) || numIds.includes(Number(item))).length;
        },

        matchesSearch(label, code) {
            if (!this.search || this.search.trim() === '') return true;
            const q = this.search.toLowerCase().trim();
            return label.toLowerCase().includes(q) || code.toLowerCase().includes(q);
        },

        moduleHasMatches(perms) {
            if (!this.search || this.search.trim() === '') return true;
            return perms.some(p => this.matchesSearch(p.label, p.code));
        }
    }"
    class="bsi-permission-matrix w-full space-y-4"
>
    {{-- Toolbar: Busca rápida + Resumo de contagem + Ações de Expansão --}}
    <div class="bsi-perm-toolbar flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 p-3.5 rounded-xl border border-slate-700/60 bg-slate-900/60">
        {{-- Campo de Busca --}}
        <div class="relative flex-1 min-w-[240px]">
            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
            </div>
            <input
                type="search"
                x-model.debounce.150ms="search"
                placeholder="Buscar permissão por nome ou código técnico..."
                class="w-full pl-9 pr-8 py-2 text-xs rounded-lg border border-slate-700 bg-slate-950/70 text-slate-100 placeholder-slate-400 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 transition-colors"
            />
            <button
                type="button"
                x-show="search"
                x-on:click="search = ''"
                class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-200"
                style="display: none;"
            >
                <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Resumo e Ações Globais --}}
        <div class="flex flex-wrap items-center justify-between md:justify-end gap-2.5 shrink-0">
            <template x-if="addedCount > 0 || removedCount > 0">
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-slate-700/80 bg-slate-950/80 text-xs">
                    <span class="text-slate-400 font-medium">Alterações:</span>
                    <span x-show="addedCount > 0" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 tabular-nums" x-text="'+' + addedCount + ' adicionada' + (addedCount > 1 ? 's' : '')"></span>
                    <span x-show="removedCount > 0" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-500/15 text-rose-300 border border-rose-500/30 tabular-nums" x-text="'-' + removedCount + ' removida' + (removedCount > 1 ? 's' : '')"></span>
                </div>
            </template>

            <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-amber-500/20 bg-amber-500/10 text-amber-300 text-xs font-medium">
                <span>Selecionadas:</span>
                <span class="font-bold tabular-nums" x-text="(Array.isArray(selectedIds) ? selectedIds.length : 0) + ' de {{ $totalPermissionsCount }}'"></span>
            </div>

            <div class="flex items-center gap-1.5 border-l border-slate-700/60 pl-3">
                <button
                    type="button"
                    x-on:click="expandAll()"
                    class="px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-md transition-colors"
                >
                    Expandir todos
                </button>
                <span class="text-slate-600">·</span>
                <button
                    type="button"
                    x-on:click="collapseAll()"
                    class="px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:text-white hover:bg-slate-800/60 rounded-md transition-colors"
                >
                    Recolher todos
                </button>
            </div>
        </div>
    </div>

    {{-- Lista de Accordions por Módulo --}}
    <div class="bsi-perm-modules space-y-3">
        @foreach($modules as $moduleName => $perms)
            @php
                $permIds = array_column($perms, 'id');
            @endphp
            <div
                x-show="moduleHasMatches(@js($perms))"
                class="bsi-perm-module-card border border-slate-800/80 bg-slate-900/40 rounded-xl overflow-hidden shadow-sm transition-all"
            >
                {{-- Cabeçalho do Accordion --}}
                <div
                    x-on:click="toggleModule('{{ addslashes($moduleName) }}')"
                    class="bsi-perm-module-header flex items-center justify-between p-3.5 bg-slate-800/50 hover:bg-slate-800/80 cursor-pointer select-none transition-colors border-b border-slate-800/60"
                >
                    <div class="flex items-center gap-2.5">
                        <svg
                            class="w-4 h-4 text-slate-400 transition-transform duration-200"
                            :class="{ 'rotate-90 text-amber-400': isModuleOpen('{{ addslashes($moduleName) }}') }"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>

                        <span class="text-sm font-semibold text-slate-100">
                            {{ $moduleName }}
                        </span>

                        <span
                            class="px-2 py-0.5 text-[11px] font-medium rounded-full tabular-nums border"
                            :class="getSelectedCount(@js($permIds)) > 0
                                ? 'bg-amber-500/15 border-amber-500/30 text-amber-300'
                                : 'bg-slate-800 border-slate-700 text-slate-400'"
                            x-text="getSelectedCount(@js($permIds)) + ' de {{ count($perms) }} selecionadas'"
                        ></span>
                    </div>

                    <div class="flex items-center gap-2" x-on:click.stop>
                        <button
                            type="button"
                            x-on:click.stop="selectAllModule(@js($permIds))"
                            class="text-xs text-amber-400 hover:text-amber-300 font-medium px-2 py-1 rounded hover:bg-amber-500/10 transition-colors"
                        >
                            Selecionar todas
                        </button>
                        <span class="text-slate-600 text-xs">·</span>
                        <button
                            type="button"
                            x-on:click.stop="clearModule(@js($permIds))"
                            class="text-xs text-slate-400 hover:text-slate-200 font-medium px-2 py-1 rounded hover:bg-slate-700/40 transition-colors"
                        >
                            Limpar
                        </button>
                    </div>
                </div>

                {{-- Corpo do Accordion com Grid de Permissões --}}
                <div
                    x-show="isModuleOpen('{{ addslashes($moduleName) }}')"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="p-3.5 bg-slate-950/40"
                >
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5">
                        @foreach($perms as $perm)
                            <label
                                x-show="matchesSearch('{{ addslashes($perm['label']) }}', '{{ addslashes($perm['code']) }}')"
                                class="bsi-perm-item group flex items-start gap-2.5 p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50 hover:bg-slate-800/70 hover:border-slate-700 cursor-pointer transition-all"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $perm['id'] }}"
                                    x-model="selectedIds"
                                    class="mt-0.5 rounded border-slate-700 bg-slate-950 text-amber-500 focus:ring-amber-500 focus:ring-offset-slate-900"
                                />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-medium text-slate-200 group-hover:text-white leading-tight truncate">
                                            {{ $perm['label'] }}
                                        </span>
                                        @if($perm['is_critical'])
                                            <span
                                                class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-red-500/15 text-red-400 border border-red-500/30 shrink-0"
                                                title="Permissão sensível / destrutiva"
                                            >
                                                crítica
                                            </span>
                                        @endif
                                    </div>
                                    <span class="text-[10px] font-mono text-slate-500 group-hover:text-slate-400 block truncate mt-0.5" title="{{ $perm['code'] }}">
                                        {{ $perm['code'] }}
                                    </span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
