<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

interface Summary {
    total: number;
    awaiting_completion: number;
    in_review: number;
    awaiting_information: number;
    approved: number;
    rejected: number;
    completed: number;
    received_last_30_days: number;
    attention: number;
}

interface StatusSlice {
    status: string;
    label: string;
    color: string;
    count: number;
}

interface RecentProposal {
    id: number;
    company: string;
    status: string;
    status_label: string;
    status_color: string;
    created_at: string;
}

const props = defineProps<{
    summary: Summary;
    statusDistribution: StatusSlice[];
    recent: RecentProposal[];
}>();

const cards = computed(() => [
    { label: 'Total de Propostas', value: props.summary.total, hint: `${props.summary.received_last_30_days} nos últimos 30 dias` },
    { label: 'Aguardando Documentação', value: props.summary.awaiting_completion, hint: 'Complementação pendente' },
    { label: 'Em Análise Técnica', value: props.summary.in_review, hint: 'Em avaliação ativa' },
    { label: 'Aguardando Informações', value: props.summary.awaiting_information, hint: 'Pendente do cliente' },
    { label: 'Aprovadas', value: props.summary.approved, hint: 'Deferidas no fluxo' },
    { label: 'Requerem Atenção', value: props.summary.attention, hint: 'Acompanhamento imediato' },
]);

const maxStatus = computed(() =>
    Math.max(1, ...props.statusDistribution.map((slice) => slice.count)),
);

const colorHex: Record<string, string> = {
    primary: '#a06e28',
    info: '#091b23',
    warning: '#c08a3e',
    success: '#2f855a',
    danger: '#c53030',
    gray: '#718096',
};

function barColor(color: string): string {
    return colorHex[color] ?? colorHex.gray;
}

function formatDate(iso: string): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('pt-BR');
}
</script>

<template>
    <Head title="Painel Operacional de Propostas" />

    <main class="operacional">
        <header class="operacional__header">
            <p class="operacional__kicker">Operacional · POC Inertia + Vue</p>
            <h1 class="operacional__title">Painel de Propostas</h1>
            <p class="operacional__subtitle">
                Visão operacional read-only das propostas comerciais, respeitando o mesmo escopo de
                permissões do painel administrativo.
            </p>
        </header>

        <section class="cards" aria-label="Indicadores">
            <article v-for="card in cards" :key="card.label" class="card">
                <span class="card__label">{{ card.label }}</span>
                <span class="card__value">{{ card.value.toLocaleString('pt-BR') }}</span>
                <span class="card__hint">{{ card.hint }}</span>
            </article>
        </section>

        <section class="panel" aria-label="Distribuição por status">
            <h2 class="panel__title">Distribuição por status</h2>
            <ul class="dist">
                <li v-for="slice in statusDistribution" :key="slice.status" class="dist__row">
                    <span class="dist__label">{{ slice.label }}</span>
                    <span class="dist__track">
                        <span
                            class="dist__bar"
                            :style="{ width: `${(slice.count / maxStatus) * 100}%`, background: barColor(slice.color) }"
                        ></span>
                    </span>
                    <span class="dist__count">{{ slice.count }}</span>
                </li>
            </ul>
        </section>

        <section class="panel" aria-label="Propostas recentes">
            <h2 class="panel__title">Propostas recentes</h2>
            <table class="recent">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>Status</th>
                        <th>Recebida em</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="proposal in recent" :key="proposal.id">
                        <td>{{ proposal.company }}</td>
                        <td>
                            <span class="tag" :style="{ color: barColor(proposal.status_color) }">
                                {{ proposal.status_label }}
                            </span>
                        </td>
                        <td>{{ formatDate(proposal.created_at) }}</td>
                    </tr>
                    <tr v-if="recent.length === 0">
                        <td colspan="3" class="recent__empty">Nenhuma proposta registrada.</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </main>
</template>

<style scoped>
.operacional {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2.5rem 1.5rem 4rem;
    font-family: 'Inter', system-ui, sans-serif;
    color: #091b23;
}

.operacional__kicker {
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.72rem;
    font-weight: 600;
    color: #a06e28;
    margin: 0 0 0.4rem;
}

.operacional__title {
    font-size: 1.9rem;
    font-weight: 700;
    margin: 0 0 0.5rem;
}

.operacional__subtitle {
    margin: 0;
    color: #4a5568;
    max-width: 640px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
    margin: 2rem 0;
}

.card {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 1.1rem 1.25rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(9, 27, 35, 0.05);
}

.card__label {
    font-size: 0.8rem;
    color: #718096;
}

.card__value {
    font-size: 1.7rem;
    font-weight: 700;
}

.card__hint {
    font-size: 0.74rem;
    color: #a0aec0;
}

.panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.panel__title {
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0 0 1.1rem;
}

.dist {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}

.dist__row {
    display: grid;
    grid-template-columns: 180px 1fr 48px;
    align-items: center;
    gap: 0.75rem;
}

.dist__label {
    font-size: 0.85rem;
    color: #4a5568;
}

.dist__track {
    height: 10px;
    background: #edf2f7;
    border-radius: 999px;
    overflow: hidden;
}

.dist__bar {
    display: block;
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}

.dist__count {
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

.recent {
    width: 100%;
    border-collapse: collapse;
}

.recent th,
.recent td {
    text-align: left;
    padding: 0.65rem 0.5rem;
    border-bottom: 1px solid #edf2f7;
    font-size: 0.88rem;
}

.recent th {
    color: #718096;
    font-weight: 600;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.tag {
    font-weight: 600;
    font-size: 0.82rem;
}

.recent__empty {
    text-align: center;
    color: #a0aec0;
    padding: 1.5rem;
}
</style>
