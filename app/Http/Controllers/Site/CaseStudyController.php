<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;

class CaseStudyController extends Controller
{
    private $cases = [
        'estruturacao-cri' => [
            'title' => 'Estruturação de CRI',
            'kicker' => 'Real Estate',
            'image' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1200&auto=format&fit=crop',
            'description' => 'Operação completa com governança e relatórios recorrentes, utilizando nossa infraestrutura para garantir o lastro perfeitamente auditável.',
            'content' => '
                <p class="lead">A BSI Capital atuou na estruturação de um Certificado de Recebíveis Imobiliários (CRI) de alta complexidade para um player relevante do setor de desenvolvimento urbano.</p>
                <h3>O Desafio</h3>
                <p>O cliente necessitava de uma estrutura que permitisse a antecipação de recebíveis de longo prazo com uma taxa competitiva, mantendo um rigoroso controle de garantias e fluxos financeiros para os investidores.</p>
                <h3>A Solução</h3>
                <p>Nossa equipe estruturou a operação em regime fiduciário, implementando um portal de transparência dedicado onde o agente fiduciário e os investidores podem acompanhar em tempo real a evolução do lastro.</p>
                <ul>
                    <li>Estruturação jurídica e financeira completa.</li>
                    <li>Gestão automatizada de recebíveis.</li>
                    <li>Relatórios de governança mensais detalhados.</li>
                </ul>
                <h3>O Resultado</h3>
                <p>A operação foi liquidada em tempo recorde, com 100% de captação e manutenção de um rating sólido ao longo de todo o período, consolidando a confiança do mercado na governança da BSI Capital.</p>
            ',
            'badges' => ['CRI', 'Real Estate', 'Governança'],
        ],
        'gestao-de-documentos' => [
            'title' => 'Gestão de Documentos',
            'kicker' => 'Tecnologia & Controle',
            'image' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=1200&auto=format&fit=crop',
            'description' => 'Desenvolvimento de um portal customizado com controle de acesso granular e auditoria completa para todos os investidores da operação.',
            'content' => '
                <p class="lead">Implementamos um ecossistema digital robusto para centralizar a custódia e auditoria de documentos sensíveis em operações de securitização.</p>
                <h3>O Desafio</h3>
                <p>A dispersão de documentos físicos e digitais em diferentes plataformas gerava riscos de conformidade e atrasos em auditorias anuais de operações estruturadas.</p>
                <h3>A Solução</h3>
                <p>Criamos o portal "BSI Investor Connect", uma camada tecnológica que se integra ao nosso core e fornece um repositório imutável de documentos com trilhas de auditoria via logs de sistema.</p>
                <ul>
                    <li>Criptografia de ponta a ponta.</li>
                    <li>Controle de acesso por papel (Perfil Investidor vs. Perfil Auditor).</li>
                    <li>Notificações automáticas de novos documentos.</li>
                </ul>
                <h3>O Resultado</h3>
                <p>Redução de 70% no tempo gasto em processos de auditoria e aumento significativo na satisfação dos investidores institucionais, que agora contam com uma fonte única e segura de verdade para seus ativos.</p>
            ',
            'badges' => ['Tecnologia', 'Compliance', 'Auditoria'],
        ],
    ];

    public function show($slug)
    {
        if (! isset($this->cases[$slug])) {
            abort(404);
        }

        $case = (object) $this->cases[$slug];

        return view('site.cases.show', compact('case'));
    }
}
