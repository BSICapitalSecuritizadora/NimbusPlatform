<?php

use App\Models\ContaAzulToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Refresh tokens OAuth são de longa duração: extraído de um dump ou de um
 * backup, um deles dá acesso persistente ao sistema financeiro da empresa, fora
 * da aplicação e sem passar por nenhum log dela.
 */
it('never stores the conta azul tokens in clear text', function () {
    ContaAzulToken::query()->create([
        'access_token' => 'access-token-em-claro',
        'refresh_token' => 'refresh-token-em-claro',
        'expires_at' => now()->addHour(),
    ]);

    $raw = DB::table('conta_azul_tokens')->first();

    expect($raw->access_token)->not->toBe('access-token-em-claro')
        ->and($raw->refresh_token)->not->toBe('refresh-token-em-claro')
        ->and(Crypt::decryptString($raw->access_token))->toBe('access-token-em-claro')
        ->and(Crypt::decryptString($raw->refresh_token))->toBe('refresh-token-em-claro');
});

it('reads the tokens back transparently through the model', function () {
    ContaAzulToken::query()->create([
        'access_token' => 'access-token-em-claro',
        'refresh_token' => 'refresh-token-em-claro',
        'expires_at' => now()->addHour(),
    ]);

    $token = ContaAzulToken::query()->first();

    expect($token->access_token)->toBe('access-token-em-claro')
        ->and($token->refresh_token)->toBe('refresh-token-em-claro');
});

it('keeps the tokens out of the serialized model', function () {
    $token = ContaAzulToken::query()->create([
        'access_token' => 'access-token-em-claro',
        'refresh_token' => 'refresh-token-em-claro',
        'expires_at' => now()->addHour(),
    ]);

    expect($token->toArray())->not->toHaveKey('access_token')
        ->and($token->toArray())->not->toHaveKey('refresh_token');
});
