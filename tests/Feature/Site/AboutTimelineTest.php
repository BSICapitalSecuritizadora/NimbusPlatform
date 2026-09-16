<?php

use Illuminate\Support\Str;

/**
 * A timeline "Nossa Trajetória" (/sobre) já foi ao ar com marcadores
 * invisíveis: usava `bg-brand`/`bg-gold`/`text-gold`, utilitários que não
 * existem no Bootstrap nem no CSS do site, então os pontos ficavam com fundo
 * transparente. A trilha também estourava o container (`h-100` sem `top`) e a
 * linha mobile ficava 5px fora do eixo dos pontos. Estes testes travam o
 * contrato corrigido: trilha única por breakpoint, quatro marcadores com
 * preenchimento vindo dos tokens institucionais e ritmo vertical uniforme.
 */
it('renders the about timeline as a single continuous track per breakpoint', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->assertViewIs('site.about')
        ->getContent();

    // Uma trilha desktop + uma trilha mobile; nunca um segmento por item.
    // Quatro itens, cada um com marcador desktop e marcador mobile.
    expect(substr_count($content, 'timeline-track-desktop"'))->toBe(1)
        ->and(substr_count($content, 'timeline-track-mobile"'))->toBe(1)
        ->and(substr_count($content, 'timeline-row"'))->toBe(4)
        ->and(substr_count($content, 'timeline-dot"'))->toBe(3)
        ->and(substr_count($content, 'timeline-dot-active"'))->toBe(1)
        ->and(substr_count($content, 'timeline-dot-sm"'))->toBe(3)
        ->and(substr_count($content, 'timeline-dot-sm-active"'))->toBe(1)
        ->and(substr_count($content, 'timeline-year-active"'))->toBe(1);
});

it('paints timeline markers and track from the institutional tokens', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    // Preenchimentos: inativos na cor da marca, ativo no dourado institucional.
    expect($content)->toContain('.timeline-dot, .timeline-dot-sm { background: var(--brand); }')
        ->and($content)->toContain('.timeline-dot-active, .timeline-dot-sm-active { background: var(--gold); }')
        ->and($content)->toContain('.timeline-year-active { color: var(--gold); }')
        // Trilha sólida e discreta, na mesma cor das bordas da interface.
        ->and($content)->toContain('.timeline-track {')
        ->and($content)->toContain('background: var(--border);')
        // Ritmo uniforme: todas as linhas com a mesma altura mínima.
        ->and($content)->toContain('min-height: 14rem;')
        ->and($content)->toContain('min-height: 14.5rem;')
        ->and($content)->toContain('min-height: 15.5rem;')
        ->and($content)->toContain('max-width: 359.98px')
        // A trilha começa no centro do primeiro marcador e termina no centro
        // do último (padding do container + metade da altura da linha).
        ->and($content)->toContain('top: 8.5rem;')
        ->and($content)->toContain('bottom: 8.5rem;')
        ->and($content)->toContain('top: 8.75rem;')
        ->and($content)->toContain('bottom: 8.75rem;')
        ->and($content)->toContain('top: 9.25rem;')
        ->and($content)->toContain('bottom: 9.25rem;');

    // Eixo horizontal: desktop centralizado, mobile sobre o centro dos pontos.
    expect($content)->toMatch('/\.timeline-track-desktop\s*\{[^}]*left:\s*50%[^}]*translateX\(-50%\)/s')
        ->and($content)->toMatch('/\.timeline-track-mobile\s*\{[^}]*left:\s*11px/s');
});

it('does not rely on undefined background utilities in the timeline', function () {
    $content = $this->get(route('site.about'))
        ->assertSuccessful()
        ->getContent();

    expect($content)->toContain('.hover-scale-timeline > .position-absolute {');

    $timeline = Str::between($content, '<!-- Timeline de Sucessos -->', '<!-- Missão, Visão, Valores -->');

    expect($timeline)->not->toBeEmpty()
        ->and($timeline)->not->toContain('bg-brand')
        ->and($timeline)->not->toContain('bg-gold')
        ->and($timeline)->not->toContain('text-gold')
        // O wrapper mobile é absoluto: `col-2` só adicionava gutters que
        // espremiam o ponto ativo de 28px para 24px de largura.
        ->and($timeline)->not->toContain('col-2 col-md-2 order-1');
});
