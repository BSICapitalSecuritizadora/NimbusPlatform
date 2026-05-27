<?php

namespace App\Console\Commands;

use App\Services\ContaAzulClient;
use Illuminate\Console\Command;

class AuthorizeContaAzul extends Command
{
    protected $signature = 'conta-azul:authorize';

    protected $description = 'Autoriza o acesso à API do Conta Azul via OAuth 2.0';

    public function handle(ContaAzulClient $client): int
    {
        $this->info('=== Autorização do Conta Azul ===');
        $this->newLine();
        $this->line('Abra a URL abaixo no browser e autorize o acesso:');
        $this->newLine();
        $this->line($client->getAuthorizationUrl());
        $this->newLine();
        $this->line('Após autorizar, você será redirecionado para:');
        $this->line('  https://contaazul.com?code=XXXXXX&state=...');
        $this->newLine();

        $code = $this->ask('Cole o valor do ?code= aqui');

        if (blank($code)) {
            $this->error('Código não informado.');

            return self::FAILURE;
        }

        try {
            $client->storeTokenFromCode($code);
            $this->info('✓ Autorização concluída com sucesso.');
        } catch (\Throwable $e) {
            $this->error('Falha na autorização: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
