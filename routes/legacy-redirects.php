<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Redirecionamentos do site WordPress anterior
|--------------------------------------------------------------------------
|
| O domínio bsicapital.com.br serviu um WordPress por anos e tem URLs
| indexadas. Sem estes 301 elas viram 404 na migração, e o histórico de busca
| construído nesse período se perde junto.
|
| Só entram aqui os caminhos que MUDARAM. `/servicos/`, `/governanca/` e
| `/emissoes/` existem com o mesmo endereço no site novo, e o Laravel já
| normaliza a barra final — redirecioná-los seria um salto desnecessário.
|
| O que é lixo do WordPress (páginas de teste, `/remover/`, `/temporario-2/`,
| plugins) fica de fora de propósito: redirecionar página morta para a home é
| soft 404, que os buscadores penalizam. 404 é a resposta correta e honesta.
|
*/

/**
 * Páginas institucionais cujo endereço mudou.
 *
 * @var array<string, string>
 */
$institutionalRedirects = [
    'r-i' => '/ri',
    'real-estate' => '/imobiliario/cri-real-estate',
    'somos-a-bsi-capital-securitizadora' => '/sobre',
    'fale-com-a-bsi' => '/contato',
    'securitizacao' => '/servicos/estruturacao-de-operacoes',
    'societarios' => '/ri?category=societarios',
    'faq' => '/contato',
    'case/securitizacao' => '/servicos',
];

foreach ($institutionalRedirects as $legacyPath => $destination) {
    Route::permanentRedirect($legacyPath, $destination);
}

/**
 * Cada série/emissão tinha uma página própria no WordPress. No site novo elas
 * viraram registros de `emissions`, acessíveis por `/emissoes/{if_code}` — e o
 * `if_code` não é derivável do slug antigo ("15a-serie" não diz qual código é).
 * Mandar para a listagem, que tem busca e filtros, é o destino correto: o
 * visitante encontra a operação em um clique, sem risco de cair na errada.
 *
 * @var array<int, string>
 */
$legacyEmissionPages = [
    '1a-e-2a-serie',
    '3a-serie',
    '4a-serie',
    '5a-serie',
    '6a-serie',
    '7a-serie',
    '9a-serie',
    '11a-12a-e-13a-serie',
    '14a-serie',
    '15a-serie',
    '16a-17a-e-18a-serie',
    '19a-serie',
    '19a-serie-2',
    '20a-serie',
    '21a-serie',
    '22a-emissao',
    '23a-emissao',
    '24a-emissao',
    '25a-emissao',
    '26a-emissao',
];

foreach ($legacyEmissionPages as $legacyPath) {
    Route::permanentRedirect($legacyPath, '/emissoes');
}

/**
 * O WordPress Download Manager publicava 621 documentos em `/download/{slug}`:
 * Termos de Securitização, aditamentos, AGTs, convocações e relatórios anuais.
 *
 * O mapeamento 1:1 seria melhor para busca, mas exigiria casar o slug antigo
 * com o título de um dos documentos do banco — um casamento aproximado. Entregar
 * o aditamento errado de um Termo de Securitização é pior do que não entregar
 * nada: em operação estruturada, documento errado tem efeito jurídico. Por isso
 * o destino é a página pública de documentos, onde há busca por título e filtro
 * por categoria.
 */
Route::get('download/{legacyDocumentSlug}', fn (): RedirectResponse => redirect('/ri', 301))
    ->where('legacyDocumentSlug', '.*')
    ->name('legacy.wordpress.downloads');
