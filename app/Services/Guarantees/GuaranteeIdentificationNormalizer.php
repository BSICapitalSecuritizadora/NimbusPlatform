<?php

namespace App\Services\Guarantees;

use App\Enums\GuaranteeCategory;
use App\Enums\GuaranteeType;
use Illuminate\Support\Str;

/**
 * Põe a identificação extraída no vocabulário da categoria da garantia e
 * normaliza valores para comparação.
 *
 * O extrator devolve um conjunto fixo de chaves, e um fundo de obras acabava
 * com a agência em `registry_office` e o banco em `company` — chaves que sequer
 * existem em {@see GuaranteeCategory::FundAccount}. Na tela isso aparecia como
 * "Registry Office: 7748", e, na comparação com o cadastro, como campo nenhum.
 *
 * O remapeamento é deliberadamente estreito: só corrige chaves que a categoria
 * não prevê e cujo destino é inequívoco. Adivinhar mais do que isso trocaria um
 * erro visível por um silencioso.
 */
class GuaranteeIdentificationNormalizer
{
    /**
     * Chaves que o extrator usa fora do lugar, por categoria de destino.
     *
     * @var array<string, array<string, string>>
     */
    private const MISPLACED_KEYS = [
        GuaranteeCategory::FundAccount->value => [
            'registry_office' => 'agency',
            'company' => 'bank',
            'issuer' => 'bank',
            'account_number' => 'account',
        ],
        GuaranteeCategory::Receivables->value => [
            'account' => 'receiving_account',
        ],
    ];

    /**
     * @param  array<string, mixed>|null  $identification
     * @return array<string, mixed>|null
     */
    public function normalize(?array $identification, ?GuaranteeType $type): ?array
    {
        if ($identification === null || $identification === []) {
            return $identification;
        }

        $category = $type?->category();

        if ($category === null) {
            return $identification;
        }

        $expected = $category->identificationFields();
        $remap = self::MISPLACED_KEYS[$category->value] ?? [];

        $normalized = [];

        foreach ($identification as $key => $value) {
            $key = (string) $key;

            // Chave prevista pela categoria fica onde está, mesmo que também
            // apareça no mapa de correção de outra categoria.
            if (array_key_exists($key, $expected)) {
                $normalized[$key] = $value;

                continue;
            }

            $target = $remap[$key] ?? null;

            // Só move para um destino previsto e ainda vago: sobrescrever o que
            // o extrator acertou por causa de um palpite seria pior.
            if ($target !== null && array_key_exists($target, $expected) && blank($normalized[$target] ?? null)) {
                $normalized[$target] = $value;

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Rótulo humano de uma chave de identificação.
     */
    public function labelFor(string $key, ?GuaranteeType $type): string
    {
        $fields = $type?->category()->identificationFields() ?? [];

        return $fields[$key] ?? Str::of($key)->replace('_', ' ')->title()->value();
    }

    /**
     * Forma canônica de um valor para comparação de igualdade.
     *
     * Bancos e contas chegam escritos de todo jeito — "Banco Bradesco S.A.
     * (cód. 237)" e "Bradesco" são o mesmo banco, "185187-P" e "185187 P" a
     * mesma conta. Já "185187-P" e "185187-0" não são, e a normalização não
     * pode apagar essa diferença.
     */
    public function canonicalize(string $key, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($key === 'bank') {
            // Um nome que era só o código ("237") não sobrevive à limpeza, e
            // ficar sem identificador nenhum é pior do que comparar o código.
            $value = $this->stripBankNoise($value) ?: $value;
        }

        $normalized = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));

        return ($normalized === null || $normalized === '') ? null : $normalized;
    }

    /**
     * Remove código, forma societária e a palavra "banco" do nome da
     * instituição, que variam entre documento e cadastro sem mudar quem é.
     */
    private function stripBankNoise(string $value): string
    {
        $value = (string) preg_replace('/\([^)]*\)/u', ' ', $value);
        $value = (string) preg_replace('/\b(banco|s\/?a\.?|s\.a\.?|ltda\.?|cod\.?|c[oó]d(igo)?\.?)\b/iu', ' ', $value);
        $value = (string) preg_replace('/\b\d{3}\b/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
