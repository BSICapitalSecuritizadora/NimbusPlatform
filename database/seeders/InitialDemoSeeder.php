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

        // 1) Admin super (Filament)
        $adminEmail = 'admin@bsi.local';

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Admin Super',
                'password' => Hash::make('Admin@123456'),
            ]
        );

        // Se você já implementou Spatie Roles:
        if (method_exists($admin, 'assignRole')) {
            $admin->assignRole('super-admin');
        }

        // 2) Emissão exemplo
        $emission = Emission::firstOrCreate(
            ['name' => 'Série Exemplo - CRI 001'],
            [
                'type' => 'CRI',
                'if_code' => 'IF-EXEMPLO-001',
                'isin_code' => 'BRB S IEXEMPLO01', // placeholder
                'status' => 'active',

                'issuer' => 'BSI Capital',
                'fiduciary_regime' => 'Sim',
                'issue_date' => now()->subDays(30)->toDateString(),
                'maturity_date' => now()->addYears(5)->toDateString(),
                'monetary_update_period' => 'Mensal',
                'series' => '001',
                'emission_number' => '001',
                'issued_quantity' => 1000,
                'monetary_update_months' => '12',
                'interest_payment_frequency' => 'Mensal',
                'offer_type' => '476',
                'concentration' => 'N/A',
                'issued_price' => 1000.00,
                'amortization_frequency' => 'Mensal',
                'integralized_quantity' => 1000,
                'trustee_agent' => 'Agente Fiduciário Exemplo',
                'debtor' => 'Devedor Exemplo',
                'remuneration' => 'CDI + 2,00% a.a.',
                'prepayment_possibility' => true,
                'segment' => 'Real Estate',
                'issued_volume' => 1000000.00,

                'is_public' => false,
                'description' => 'Emissão de exemplo para desenvolvimento.',
            ]
        );

        // 3) Documento exemplo
        $document = Document::firstOrCreate(
            ['title' => 'Relatório Anual - Exemplo'],
            [
                'category' => 'relatorios_anuais',
                // como é seed de demo, file_path pode ser um placeholder:
                'file_path' => 'documents/demo/relatorio-anual-exemplo.pdf',

                'file_name' => 'relatorio-anual-exemplo.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 123456,

                'is_published' => true,
                'is_public' => false,
            ]
        );

        // 4) Investidor exemplo
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
                'notes' => 'Conta demo para testes do portal.',
                'remember_token' => Str::random(10),
            ]
        );

        // 5) Vínculos (pivot)
        // investidor ↔ emissão
        $investor->emissions()->syncWithoutDetaching([$emission->id]);

        // investidor ↔ documento
        $investor->documents()->syncWithoutDetaching([$document->id]);

        // documento ↔ emissão (série)
        $document->emissions()->syncWithoutDetaching([$emission->id]);
    }
}
