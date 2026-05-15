<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InitialDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $adminEmail = 'admin@bsi.local';

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Admin Super',
                'password' => Hash::make('Admin@123456'),
                'approved_at' => now(),
                'is_active' => true,
            ]
        );

        if (method_exists($admin, 'assignRole')) {
            $admin->assignRole('super-admin');
        }

        $emission = Emission::firstOrCreate(
            ['name' => 'Serie Exemplo - CRI 001'],
            [
                'type' => 'CRI',
                'if_code' => 'IF-EXEMPLO-001',
                'isin_code' => 'BRBSIEXEMPLO01',
                'status' => 'active',
                'issuer_situation' => 'Adimplente',
                'issuer' => 'BSI Capital',
                'settlement_bank' => 'Banco Liquidante Exemplo',
                'registrar' => 'Escriturador Exemplo',
                'fiduciary_regime' => 'Sim',
                'issue_date' => now()->subDays(30)->toDateString(),
                'maturity_date' => now()->addYears(5)->toDateString(),
                'monetary_update_period' => 'Mensal',
                'series' => '001',
                'emission_number' => '001',
                'issued_quantity' => 1000,
                'monetary_update_months' => '12',
                'interest_payment_frequency' => 'Mensal',
                'offer_type' => 'CVM 160',
                'concentration' => 'Concentrado',
                'issued_price' => 1000.00,
                'amortization_frequency' => 'Mensal',
                'integralized_quantity' => 1000,
                'trustee_agent' => 'Agente Fiduciario Exemplo',
                'debtor' => 'Devedor Exemplo',
                'law_firm' => 'Escritorio de Advocacia Exemplo',
                'remuneration_indexer' => 'CDI',
                'remuneration_rate' => 2.00,
                'prepayment_possibility' => true,
                'registered_with_cvm' => 'Sim',
                'form_type' => 'Escritural',
                'segment' => 'Real Estate',
                'issued_volume' => 1000000.00,
                'corporate_purpose' => 'Exploracao de direitos creditórios imobiliarios.',
                'subscription_and_integralization_terms' => 'Integralizacao em moeda corrente nacional.',
                'amortization_payment_schedule' => 'Ultimo dia util de cada mes.',
                'remuneration_payment_schedule' => 'Todo dia 15.',
                'use_of_proceeds' => 'Capital de giro e expansao.',
                'repactuation' => 'Nao aplicavel.',
                'optional_early_redemption' => 'Permitido conforme escritura.',
                'early_amortization' => 'Possivel em eventos extraordinarios.',
                'remuneration_calculation' => 'Base 252 com spread pactuado.',
                'guarantee_fund' => 'Sim',
                'expense_fund' => 'Nao',
                'reserve_fund' => 'Sim',
                'works_fund' => 'Nao',
                'property_description' => 'Portifolio de imoveis vinculados a operacao.',
                'segregated_estate' => 'Constituido em favor dos titulares.',
                'guarantees_description' => 'Cessao fiduciaria de recebiveis e alienacao fiduciaria.',
                'is_public' => false,
                'description' => 'Emissao de exemplo para desenvolvimento.',
            ]
        );

        $document = Document::firstOrCreate(
            ['title' => 'Relatorio Anual - Exemplo'],
            [
                'category' => 'relatorios_anuais',
                'file_path' => 'documents/demo/relatorio-anual-exemplo.pdf',
                'file_name' => 'relatorio-anual-exemplo.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 123456,
                'storage_disk' => Document::defaultStorageDisk(),
                'is_published' => true,
                'is_public' => false,
                'published_at' => now(),
                'published_by' => $admin->id,
            ]
        );

        Document::firstOrCreate(
            ['title' => 'Informativo Trimestral - Publico'],
            [
                'category' => 'anuncios',
                'file_path' => 'documents/demo/informativo-trimestral-publico.pdf',
                'file_name' => 'informativo-trimestral-publico.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 98765,
                'storage_disk' => Document::defaultStorageDisk(),
                'is_published' => true,
                'is_public' => true,
                'published_at' => now(),
                'published_by' => $admin->id,
            ]
        );

        $investor = Investor::firstOrCreate(
            ['email' => 'investidor@demo.local'],
            [
                'name' => 'Investidor Demo',
                'password' => Hash::make('Investor@123456'),
                'phone' => '(11) 3333-4444',
                'mobile' => '(11) 98888-7777',
                'cpf' => '123.456.789-00',
                'rg' => '12.345.678-9',
                'is_active' => true,
                'last_login_at' => null,
                'last_portal_seen_at' => null,
                'notes' => 'Conta demo para testes do portal.',
                'remember_token' => Str::random(10),
            ]
        );

        $investor->emissions()->syncWithoutDetaching([$emission->id]);
        $investor->documents()->syncWithoutDetaching([$document->id]);
        $document->emissions()->syncWithoutDetaching([$emission->id]);

        $currentDate = \Carbon\Carbon::parse($emission->issue_date)->addMonth();
        $limitDate = now()->addMonths(12);
        while ($currentDate->lte($limitDate)) {
            \App\Models\Payment::create([
                'emission_id' => $emission->id,
                'payment_date' => $currentDate->copy()->endOfMonth()->format('Y-m-d'),
                'premium_value' => rand(2, 8),
                'interest_value' => rand(10, 30),
                'amortization_value' => rand(15, 40),
                'extra_amortization_value' => rand(0, 10) > 8 ? rand(5, 20) : 0,
            ]);
            $currentDate->addMonth();
        }
    }
}
