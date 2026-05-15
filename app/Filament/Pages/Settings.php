<?php

namespace App\Filament\Pages;

use App\Actions\Emissions\PaymentSpreadsheetTemplate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use UnitEnum;

class Settings extends Page
{
    use WithFileUploads;

    public mixed $paymentTemplateFile = null;

    protected string $view = 'filament.pages.settings';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações';

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

    public function getPaymentTemplateDownloadUrl(): string
    {
        return route('admin.payments.template.download');
    }

    public function hasPaymentTemplate(): bool
    {
        return $this->paymentSpreadsheetTemplate()->exists();
    }

    public function hasCustomPaymentTemplate(): bool
    {
        return $this->paymentSpreadsheetTemplate()->hasCustomTemplate();
    }

    public function getPaymentTemplateStatusLabel(): string
    {
        return $this->hasCustomPaymentTemplate() ? 'Personalizado' : 'Padrão do sistema';
    }

    public function getPaymentTemplateStatusClasses(): string
    {
        return $this->hasCustomPaymentTemplate()
            ? 'border border-amber-400/30 bg-amber-500/15 text-amber-100'
            : 'border border-emerald-400/30 bg-emerald-500/15 text-emerald-100';
    }

    public function getPaymentTemplateDescription(): string
    {
        return $this->hasCustomPaymentTemplate()
            ? 'O arquivo atual foi enviado manualmente nesta área de configurações.'
            : 'O fluxo de pagamentos está usando o template padrão versionado no sistema.';
    }

    protected function paymentSpreadsheetTemplate(): PaymentSpreadsheetTemplate
    {
        return app(PaymentSpreadsheetTemplate::class);
    }
}
