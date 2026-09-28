<?php

namespace App\Providers;

use App\Actions\Clients\ClientSpreadsheetTemplate;
use App\Actions\ConstructionUnits\ConstructionUnitSpreadsheetTemplate;
use App\Actions\ConstructionUnitValues\UnitValueSpreadsheetTemplate;
use App\Actions\ContractInstallments\ContractInstallmentSpreadsheetTemplate;
use App\Actions\Contracts\ContractSpreadsheetTemplate;
use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateDefinition;
use App\Support\SpreadsheetTemplates\SpreadsheetTemplateRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers every spreadsheet template managed on the
 * "Templates de Planilhas" settings page.
 *
 * To expose a new template, register one definition here (or from the
 * module's own service provider): it automatically appears on the settings
 * page, grouped by category, with download wired to the given route.
 * The handler class must be the same one used by the import flow and the
 * download route, so the page always reflects the effective template.
 */
class SpreadsheetTemplateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SpreadsheetTemplateRegistry::class);
    }

    public function boot(SpreadsheetTemplateRegistry $registry): void
    {
        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'payments',
            title: 'Fluxo de pagamentos',
            category: 'Emissões',
            context: 'Planilha de importação de pagamentos da emissão',
            description: 'O fluxo de pagamentos está usando o template padrão versionado no sistema.',
            downloadRoute: 'admin.payments.template.download',
            handler: PaymentSpreadsheetTemplate::class,
            replaceable: true,
            downloadAbilities: ['emissions.view', 'settings.view'],
            restoreConfirmation: 'Restaurar o template padrão do fluxo de pagamentos? O arquivo personalizado atual deixará de ser usado.',
            customizedNotificationBody: 'O novo arquivo já está disponível para download no fluxo de pagamentos.',
            restoredNotificationBody: 'O fluxo de pagamentos voltou a usar o arquivo padrão do sistema.',
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'pu-histories',
            title: 'Histórico de PU',
            category: 'Emissões',
            context: 'Planilha de importação do histórico de PU da emissão',
            description: 'O histórico de PU está usando o template padrão versionado no sistema.',
            downloadRoute: 'admin.pu-histories.template.download',
            handler: PuHistorySpreadsheetTemplate::class,
            replaceable: true,
            downloadAbilities: ['emissions.view', 'settings.view'],
            restoreConfirmation: 'Restaurar o template padrão do histórico de PU? O arquivo personalizado atual deixará de ser usado.',
            customizedNotificationBody: 'O novo arquivo já está disponível para download no histórico de PU.',
            restoredNotificationBody: 'O histórico de PU voltou a usar o arquivo padrão do sistema.',
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'integralization-histories',
            title: 'Histórico de integralizações',
            category: 'Emissões',
            context: 'Planilha de importação do histórico de integralizações da emissão',
            description: 'O histórico de integralizações está usando o template padrão versionado no sistema.',
            downloadRoute: 'admin.integralization-histories.template.download',
            handler: IntegralizationHistorySpreadsheetTemplate::class,
            replaceable: true,
            downloadAbilities: ['emissions.view', 'settings.view'],
            restoreConfirmation: 'Restaurar o template padrão do histórico de integralizações? O arquivo personalizado atual deixará de ser usado.',
            customizedNotificationBody: 'O novo arquivo já está disponível para download no histórico de integralizações.',
            restoredNotificationBody: 'O histórico de integralizações voltou a usar o arquivo padrão do sistema.',
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'clients',
            title: 'Clientes',
            category: 'Comercial',
            context: 'Planilha de importação de clientes',
            description: 'Modelo de importação de clientes pessoas físicas e jurídicas.',
            downloadRoute: 'admin.clients.template.download',
            handler: ClientSpreadsheetTemplate::class,
            dynamic: true,
            downloadAbilities: ['clients.view'],
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'contracts',
            title: 'Contratos',
            category: 'Comercial',
            context: 'Planilha de importação de contratos',
            description: 'Modelo de importação de contratos de venda das unidades.',
            downloadRoute: 'admin.contracts.template.download',
            handler: ContractSpreadsheetTemplate::class,
            dynamic: true,
            downloadAbilities: ['contracts.view'],
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'contract-installments',
            title: 'Parcelas dos contratos',
            category: 'Comercial',
            context: 'Planilha de importação de parcelas dos contratos',
            description: 'Modelo de importação das parcelas vinculadas aos contratos.',
            downloadRoute: 'admin.contract-installments.template.download',
            handler: ContractInstallmentSpreadsheetTemplate::class,
            dynamic: true,
            downloadAbilities: ['contract-installments.view'],
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'construction-units',
            title: 'Unidades dos empreendimentos',
            category: 'Empreendimentos',
            context: 'Planilha de importação de unidades',
            description: 'Modelo de importação das unidades dos empreendimentos.',
            downloadRoute: 'admin.construction-units.template.download',
            handler: ConstructionUnitSpreadsheetTemplate::class,
            dynamic: true,
            downloadAbilities: ['constructions.view'],
        ));

        $registry->register(new SpreadsheetTemplateDefinition(
            key: 'construction-unit-values',
            title: 'Valores das unidades',
            category: 'Empreendimentos',
            context: 'Planilha de atualização de valores das unidades',
            description: 'Modelo de atualização em lote dos valores das unidades.',
            downloadRoute: 'admin.construction-unit-values.template.download',
            handler: UnitValueSpreadsheetTemplate::class,
            dynamic: true,
            downloadAbilities: ['constructions.update'],
        ));
    }
}
