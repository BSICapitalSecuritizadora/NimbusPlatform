<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * O `refresh_token` do Conta Azul é de longa duração: quem o extrair de um
     * dump, de um backup mal protegido ou por leitura direta do banco ganha
     * acesso persistente ao sistema financeiro da empresa, fora da aplicação e
     * sem passar por nenhum log dela. Por isso os dois tokens ficam cifrados em
     * repouso — as colunas já são `text`, o que acomoda o ciphertext.
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
