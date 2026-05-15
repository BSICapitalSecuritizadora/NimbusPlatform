<?php

namespace App\Filament\Pages;

use App\Actions\Emissions\IntegralizationHistorySpreadsheetTemplate;
use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use App\Actions\Emissions\PuHistorySpreadsheetTemplate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use UnitEnum;

class Settings extends Page
{
    use WithFileUploads;

    public mixed $paymentTemplateFile = null;

    public mixed $puHistoryTemplateFile = null;

    public mixed $integralizationHistoryTemplateFile = null;

    protected string $view = 'filament.pages.settings';

    protected static string|UnitEnum|null $navigationGroup = "Configura\u{00E7}\u{00F5}es";

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = "Configura\u{00E7}\u{00F5}es";

    protected static ?string $title = "Configura\u{00E7}\u{00F5}es";

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.view') ?? false;
    }

    public function savePaymentTemplate(PaymentSpreadsheetTemplate $paymentSpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $validated = $this->validate([
            'paymentTemplateFile' => [
                'required',
                'file',
                'mimes:xlsx',
            ],
        ], [
            'paymentTemplateFile.required' => 'Selecione uma planilha para atualizar o template.',
            'paymentTemplateFile.file' => 'Envie um arquivo válido.',
            'paymentTemplateFile.mimes' => 'Envie uma planilha Excel válida no formato .xlsx.',
        ]);

        $paymentSpreadsheetTemplate->store($validated['paymentTemplateFile']);

        $this->paymentTemplateFile = null;

        Notification::make()
            ->title('Template atualizado com sucesso.')
            ->body('O novo arquivo já está disponível para download no fluxo de pagamentos.')
            ->success()
            ->send();
    }

    public function restoreDefaultPaymentTemplate(PaymentSpreadsheetTemplate $paymentSpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $paymentSpreadsheetTemplate->restoreDefault();

        Notification::make()
            ->title('Template padrão restaurado.')
            ->body('O fluxo de pagamentos voltou a usar o arquivo padrão do sistema.')
            ->success()
            ->send();
    }

    public function savePuHistoryTemplate(PuHistorySpreadsheetTemplate $puHistorySpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $validated = $this->validate([
            'puHistoryTemplateFile' => [
                'required',
                'file',
                'mimes:xlsx',
            ],
        ], [
            'puHistoryTemplateFile.required' => 'Selecione uma planilha para atualizar o template.',
            'puHistoryTemplateFile.file' => 'Envie um arquivo válido.',
            'puHistoryTemplateFile.mimes' => 'Envie uma planilha Excel válida no formato .xlsx.',
        ]);

        $puHistorySpreadsheetTemplate->store($validated['puHistoryTemplateFile']);

        $this->puHistoryTemplateFile = null;

        Notification::make()
            ->title('Template atualizado com sucesso.')
            ->body('O novo arquivo já está disponível para download no histórico de PU.')
            ->success()
            ->send();
    }

    public function restoreDefaultPuHistoryTemplate(PuHistorySpreadsheetTemplate $puHistorySpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $puHistorySpreadsheetTemplate->restoreDefault();

        Notification::make()
            ->title('Template padrão restaurado.')
            ->body('O histórico de PU voltou a usar o arquivo padrão do sistema.')
            ->success()
            ->send();
    }

    public function saveIntegralizationHistoryTemplate(IntegralizationHistorySpreadsheetTemplate $integralizationHistorySpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $validated = $this->validate([
            'integralizationHistoryTemplateFile' => [
                'required',
                'file',
                'mimes:xlsx',
            ],
        ], [
            'integralizationHistoryTemplateFile.required' => 'Selecione uma planilha para atualizar o template.',
            'integralizationHistoryTemplateFile.file' => 'Envie um arquivo válido.',
            'integralizationHistoryTemplateFile.mimes' => 'Envie uma planilha Excel válida no formato .xlsx.',
        ]);

        $integralizationHistorySpreadsheetTemplate->store($validated['integralizationHistoryTemplateFile']);

        $this->integralizationHistoryTemplateFile = null;

        Notification::make()
            ->title('Template atualizado com sucesso.')
            ->body('O novo arquivo já está disponível para download no histórico de integralizações.')
            ->success()
            ->send();
    }

    public function restoreDefaultIntegralizationHistoryTemplate(IntegralizationHistorySpreadsheetTemplate $integralizationHistorySpreadsheetTemplate): void
    {
        abort_unless(static::canAccess(), 403);

        $integralizationHistorySpreadsheetTemplate->restoreDefault();

        Notification::make()
            ->title('Template padrão restaurado.')
            ->body('O histórico de integralizações voltou a usar o arquivo padrão do sistema.')
            ->success()
            ->send();
    }

    public function getPaymentTemplateDownloadUrl(): string
    {
        return route('admin.payments.template.download');
    }

    public function getPuHistoryTemplateDownloadUrl(): string
    {
        return route('admin.pu-histories.template.download');
    }

    public function getIntegralizationHistoryTemplateDownloadUrl(): string
    {
        return route('admin.integralization-histories.template.download');
    }

    public function hasPaymentTemplate(): bool
    {
        return $this->paymentSpreadsheetTemplate()->exists();
    }

    public function hasPuHistoryTemplate(): bool
    {
        return $this->puHistorySpreadsheetTemplate()->exists();
    }

    public function hasIntegralizationHistoryTemplate(): bool
    {
        return $this->integralizationHistorySpreadsheetTemplate()->exists();
    }

    public function hasCustomPaymentTemplate(): bool
    {
        return $this->paymentSpreadsheetTemplate()->hasCustomTemplate();
    }

    public function hasCustomPuHistoryTemplate(): bool
    {
        return $this->puHistorySpreadsheetTemplate()->hasCustomTemplate();
    }

    public function hasCustomIntegralizationHistoryTemplate(): bool
    {
        return $this->integralizationHistorySpreadsheetTemplate()->hasCustomTemplate();
    }

    public function getPaymentTemplateStatusLabel(): string
    {
        return $this->hasCustomPaymentTemplate() ? 'Personalizado' : 'Padrão do sistema';
    }

    public function getPuHistoryTemplateStatusLabel(): string
    {
        return $this->hasCustomPuHistoryTemplate() ? 'Personalizado' : 'Padrão do sistema';
    }

    public function getIntegralizationHistoryTemplateStatusLabel(): string
    {
        return $this->hasCustomIntegralizationHistoryTemplate() ? 'Personalizado' : 'Padrão do sistema';
    }

    public function getPaymentTemplateStatusClasses(): string
    {
        return $this->hasCustomPaymentTemplate()
            ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100'
            : 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-100';
    }

    public function getPuHistoryTemplateStatusClasses(): string
    {
        return $this->hasCustomPuHistoryTemplate()
            ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100'
            : 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-100';
    }

    public function getIntegralizationHistoryTemplateStatusClasses(): string
    {
        return $this->hasCustomIntegralizationHistoryTemplate()
            ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100'
            : 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-100';
    }

    public function getPaymentTemplateDescription(): string
    {
        return $this->hasCustomPaymentTemplate()
            ? 'O arquivo atual foi enviado manualmente nesta área de configurações.'
            : 'O fluxo de pagamentos está usando o template padrão versionado no sistema.';
    }

    public function getPuHistoryTemplateDescription(): string
    {
        return $this->hasCustomPuHistoryTemplate()
            ? 'O arquivo atual foi enviado manualmente nesta área de configurações.'
            : 'O histórico de PU está usando o template padrão versionado no sistema.';
    }

    public function getIntegralizationHistoryTemplateDescription(): string
    {
        return $this->hasCustomIntegralizationHistoryTemplate()
            ? 'O arquivo atual foi enviado manualmente nesta área de configurações.'
            : 'O histórico de integralizações está usando o template padrão versionado no sistema.';
    }

    protected function paymentSpreadsheetTemplate(): PaymentSpreadsheetTemplate
    {
        return app(PaymentSpreadsheetTemplate::class);
    }

    protected function puHistorySpreadsheetTemplate(): PuHistorySpreadsheetTemplate
    {
        return app(PuHistorySpreadsheetTemplate::class);
    }

    protected function integralizationHistorySpreadsheetTemplate(): IntegralizationHistorySpreadsheetTemplate
    {
        return app(IntegralizationHistorySpreadsheetTemplate::class);
    }
}
