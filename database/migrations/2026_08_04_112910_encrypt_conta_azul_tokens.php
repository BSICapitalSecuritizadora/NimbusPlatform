<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * O model `ContaAzulToken` passou a cifrar `access_token` e `refresh_token` em
 * repouso. Os registros gravados antes disso estão em texto claro e, com o novo
 * cast, o primeiro `getAccessToken()` falharia ao tentar decifrá-los — esta
 * migration recifra o que já existe.
 *
 * As duas direções são idempotentes: um valor que já está no formato de destino
 * é deixado como está, então repetir a migration não corrompe nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteTokens(fn (string $value): string => $this->isEncrypted($value)
            ? $value
            : Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->rewriteTokens(fn (string $value): string => $this->isEncrypted($value)
            ? Crypt::decryptString($value)
            : $value);
    }

    /**
     * @param  callable(string): string  $transform
     */
    private function rewriteTokens(callable $transform): void
    {
        DB::table('conta_azul_tokens')
            ->orderBy('id')
            ->each(function (object $token) use ($transform): void {
                DB::table('conta_azul_tokens')
                    ->where('id', $token->id)
                    ->update([
                        'access_token' => $transform((string) $token->access_token),
                        'refresh_token' => $transform((string) $token->refresh_token),
                    ]);
            });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
