<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;

class CaseStudyController extends Controller
{
    private $cases = [
        'estruturacao-cri' => [
            'title' => 'Estruturação de CRI',
            'kicker' => 'Real Estate',
            'image' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=1200&auto=format&fit=crop',
            'description' => 'Estruturação e gestão completa de Certificado de Recebíveis Imobiliários para incorporadora de médio porte, com foco em antecipação de recebíveis e governança ativa.',
            'highlights' => [
                ['label' => 'Volume Total', 'value' => 'R$ 42 Mi', 'icon' => 'currency-dollar'],
                ['label' => 'Prazo Médio', 'value' => '60 Meses', 'icon' => 'calendar'],
                ['label' => 'Rating Inicial', 'value' => 'A (bra)', 'icon' => 'shield-check'],
                ['label' => 'Liquidação', 'value' => '45 Dias', 'icon' => 'lightning-bolt'],
            ],
            'content' => '
                <p class="lead">A BSI Capital atuou como securitizadora na estruturação de um Certificado de Recebíveis Imobiliários (CRI) para uma incorporadora com forte atuação no interior de São Paulo, visando a expansão de novos empreendimentos residenciais.</p>
                <h3>O Desafio</h3>
                <p>O cliente possuía uma carteira de recebíveis pulverizada e precisava de liquidez imediata para acelerar o ciclo de construção de novas torres. O desafio era estruturar uma operação que oferecesse segurança aos investidores institucionais, mesmo com um fluxo de caixa futuro sujeito a variações de inadimplência do setor imobiliário.</p>
                <h3>A Solução</h3>
                <p>Nossa equipe implementou uma estrutura de <strong>Regime Fiduciário</strong>, isolando o patrimônio da operação e garantindo a segregação de riscos. Além disso:</p>
                <ul>
                    <li>Criamos um mecanismo de <strong>Fundo de Reserva</strong> dinâmico para mitigar riscos de inadimplência pontual.</li>
                    <li>Desenvolvemos um dashboard de acompanhamento de lastro em tempo real para o Agente Fiduciário.</li>
                    <li>Realizamos a coordenação completa entre custodiante, auditoria e investidores.</li>
                </ul>
                <h3>O Resultado</h3>
                <p>A operação obteve demanda superior à oferta (oversubscription), permitindo uma redução na taxa final do cupom para o emissor. A governança implementada pela BSI Capital garantiu que todos os relatórios mensais fossem entregues com precisão de D+5, consolidando a confiança dos investidores no ativo.</p>
            ',
            'badges' => ['CRI', 'Real Estate', 'Governança'],
        ],
        'gestao-de-documentos' => [
            'title' => 'Gestão de Documentos',
            'kicker' => 'Tecnologia & Controle',
            'image' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=1200&auto=format&fit=crop',
            'description' => 'Implementação de ecossistema digital para custódia e auditoria de documentos em operações complexas de securitização.',
            'highlights' => [
                ['label' => 'Arquivos Geridos', 'value' => '150k+', 'icon' => 'document-duplicate'],
                ['label' => 'Tempo Auditoria', 'value' => '-70%', 'icon' => 'clock'],
                ['label' => 'Acesso Seguro', 'value' => '24/7', 'icon' => 'lock-closed'],
                ['label' => 'Disponibilidade', 'value' => '99.9%', 'icon' => 'cloud-upload'],
            ],
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
