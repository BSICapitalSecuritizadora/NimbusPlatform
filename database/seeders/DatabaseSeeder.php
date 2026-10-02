<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Semeia um ambiente de desenvolvimento ou de demonstração.
     *
     * Recusa produção antes de qualquer escrita. O seeder padrão cria contas de
     * demonstração com senha conhecida -- `admin@bsi.local`, super-admin, e
     * `investidor@demo.local` --, uma Emissão fictícia, e ressincroniza as
     * permissões dos papéis, o que revogaria em silêncio o que foi concedido
     * pela tela de papéis. Um `db:seed --force` por engano no App Service
     * deixaria a produção com um super-admin de senha pública.
     *
     * Não há variável de escape: semear só os papéis num ambiente novo é
     * `--class=RolesAndPermissionsSeeder`, de propósito e com o aviso dele.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'O DatabaseSeeder não roda em produção: ele cria contas de demonstração com senha conhecida '
                    .'(admin@bsi.local, investidor@demo.local), uma Emissão fictícia e ressincroniza as permissões dos papéis. '
                    .'Num ambiente novo, para semear só os papéis, use php artisan db:seed --class=RolesAndPermissionsSeeder --force, '
                    .'ciente de que ele sobrescreve as permissões dos papéis não-admin.'
            );
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            ProposalSectorSeeder::class,
            InitialDemoSeeder::class,
        ]);
    }
}
