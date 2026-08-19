<?php

namespace App\Services\LegalInstruments;

use App\Enums\LegalInstrumentDocumentRole;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentType;
use App\Models\LegalInstrumentDocument;

/**
 * Monta o prompt de extração de um documento do dossiê.
 *
 * O prompt é montado a partir do tipo do instrumento — os campos procurados
 * saem de {@see LegalInstrumentType::fieldKeys()} — e não escrito à mão por
 * tipo. É o que permite CCI, AFI, AFQ e cessão reaproveitarem a mesma
 * infraestrutura (§24) declarando apenas os seus campos.
 *
 * Quando há posição vigente, ela entra no prompt: pedir ao modelo que compare
 * com o que já está confirmado produz alterações explícitas em vez de uma
 * releitura do documento inteiro, que é o que a revisão precisa (§21).
 */
class InstrumentDocumentPromptBuilder
{
    /**
     * @param  array<string, string>  $currentPosition  rótulo do campo => valor vigente formatado
     */
    public function build(LegalInstrumentDocument $instrumentDocument, array $currentPosition = []): string
    {
        $instrument = $instrumentDocument->instrument;
        $type = $instrument->type;
        $role = $instrumentDocument->role;

        return implode("\n\n", array_filter([
            $this->header($type, $role),
            $this->groundingRules(),
            $this->roleGuidance($role),
            $this->fieldCatalogue($type),
            $this->currentPositionBlock($currentPosition),
            $this->guaranteeBlock(),
            $this->outputContract(),
        ]));
    }

    private function header(LegalInstrumentType $type, LegalInstrumentDocumentRole $role): string
    {
        return <<<PROMPT
        Você é um especialista em direito do mercado de capitais brasileiro, com foco em operações de securitização.

        O documento anexado é: **{$role->label()}** de um instrumento do tipo **{$type->label()}**.

        Sua tarefa é extrair as informações estruturadas que o documento efetivamente declara e identificar o que ele altera em relação à posição vigente informada abaixo.
        PROMPT;
    }

    private function groundingRules(): string
    {
        return <<<'PROMPT'
        REGRAS DE FUNDAMENTAÇÃO (CRÍTICAS — a violação invalida a extração):
        - Extraia SOMENTE o que está escrito no documento. Nunca use conhecimento externo.
        - NUNCA invente número, valor, percentual, matrícula, CNPJ, conta, data, cláusula ou página.
        - Ausência JAMAIS vira 0 nem "Não". Se o dado não está no documento, omita o campo.
        - `excerpt` deve ser citação LITERAL (máx. 400 caracteres) que comprove o valor extraído.
        - Classifique cada campo em `evidence_level`:
          - "explicit": o documento afirma o dado literalmente;
          - "inferred": você deduziu pela relação entre cláusulas;
          - "conflicting": o documento traz informações divergentes entre si.
        - Se o documento não trata de um campo, simplesmente não o inclua na resposta.
        - Prefira uma lista curta e confiável a uma longa e ruidosa.
        PROMPT;
    }

    private function roleGuidance(LegalInstrumentDocumentRole $role): string
    {
        if ($role->isBase()) {
            return <<<'PROMPT'
            PAPEL DESTE DOCUMENTO: é o documento original — ele **constitui** a posição inicial.
            Extraia todos os campos aplicáveis que ele declarar. `effective_date` de cada campo é a data de emissão do instrumento, salvo se a cláusula indicar outra.
            PROMPT;
        }

        if (! $role->canAmendPosition()) {
            return <<<'PROMPT'
            PAPEL DESTE DOCUMENTO: é peça comprobatória (registro, averbação, laudo ou comprovante).
            Ele NÃO altera a regra contratual. Extraia apenas os dados que ele comprova (por exemplo, número de registro ou valor de avaliação) e NÃO proponha alteração de valores contratuais.
            PROMPT;
        }

        return <<<'PROMPT'
        PAPEL DESTE DOCUMENTO: é um aditamento/instrumento modificativo — ele **altera** a posição anterior.
        Extraia SOMENTE os campos que este documento efetivamente muda ou acrescenta. Não repita campos que ele apenas menciona sem alterar.
        `effective_date` é a data a partir da qual a alteração vale (normalmente a data do aditamento, salvo cláusula em contrário).
        Expressões típicas de alteração: "passa a vigorar", "passa de X para Y", "fica alterado", "fica substituído", "fica liberada", "fica incluída", "fica excluída".
        PROMPT;
    }

    private function fieldCatalogue(LegalInstrumentType $type): string
    {
        $lines = array_map(
            static fn (LegalInstrumentFieldKey $key): string => sprintf(
                '- `%s` (%s) — %s',
                $key->value,
                $key->valueType()->value,
                $key->label(),
            ),
            $type->fieldKeys(),
        );

        $catalogue = implode("\n", $lines);

        return <<<PROMPT
        CAMPOS RECONHECIDOS (use exatamente estas chaves em `field_key`):
        {$catalogue}

        Convenções de valor:
        - `money`: número puro, sem símbolo nem separador de milhar (ex.: 35000000).
        - `percentage`: fração decimal (130% => 1.3).
        - `date`: "YYYY-MM-DD".
        - `number`: número puro.
        - `text`: o texto como está no documento.
        PROMPT;
    }

    /**
     * @param  array<string, string>  $currentPosition
     */
    private function currentPositionBlock(array $currentPosition): ?string
    {
        if ($currentPosition === []) {
            return null;
        }

        $lines = [];

        foreach ($currentPosition as $label => $value) {
            $lines[] = "- {$label}: {$value}";
        }

        $position = implode("\n", $lines);

        return <<<PROMPT
        POSIÇÃO VIGENTE JÁ CONFIRMADA NO SISTEMA (compare o documento com ela):
        {$position}

        Só devolva um campo se o documento o alterar ou se ele ainda não constar acima. Se o documento repete um valor idêntico ao vigente, não o devolva.
        PROMPT;
    }

    private function guaranteeBlock(): string
    {
        return <<<'PROMPT'
        GARANTIAS: identifique as garantias que o documento constitui, altera, reforça, substitui ou libera.
        Use `type` com um destes valores: af_imovel, af_quotas, cf_recebiveis, cf_direitos_creditorios, promessa_cessao_fiduciaria, hipoteca, penhor, aval, fianca, fundo_reserva, fundo_juros, fundo_obras, conta_reserva, conta_vinculada, recebiveis, estoque, unidades, carta_fianca, seguro_garantia, aplicacao_financeira, outra.
        Use `event` com um destes: constitution, amendment, reinforcement, substitution, release.
        Em `identification`, use as chaves da família da garantia, apenas com o que o documento trouxer:
        - Imóvel: registration_number (matrícula), registry_office (cartório), city, state, owner, construction, unit
        - Quotas: company, tax_id (CNPJ), quota_quantity, pledged_percentage, grantor
        - Recebíveis: portfolio, construction, contracts, assigned_percentage, receiving_account
        - Fundos e contas vinculadas: fund_type, bank (nome do banco), agency (agência), account (conta), composition_rule
        - Pessoais e seguros: guarantor, tax_id, issuer, policy_number
        NUNCA use `registry_office` para agência nem `company` para banco: cartório e sociedade são outra coisa, e a troca impede o sistema de reconhecer a conta já cadastrada.
        PROMPT;
    }

    private function outputContract(): string
    {
        return <<<'PROMPT'
        Retorne SOMENTE um JSON com esta estrutura:

        {
          "fields": [
            {
              "field_key": "principal_amount",
              "value": "35000000",
              "effective_date": "2024-06-18",
              "evidence_level": "explicit",
              "confidence_score": 0.95,
              "clause": "2.1",
              "page": 3,
              "excerpt": "citação literal"
            }
          ],
          "guarantees": [
            {
              "type": "af_imovel",
              "event": "substitution",
              "name": "AF Imóvel",
              "identification": {"registration_number": "18.900"},
              "effective_date": "2025-02-02",
              "evidence_level": "explicit",
              "confidence_score": 0.9,
              "clause": "3.1",
              "page": 4,
              "excerpt": "citação literal"
            }
          ],
          "obligations": [
            {
              "title": "Reavaliar o imóvel anualmente",
              "recurrence": "Anual",
              "clause": "6.4",
              "page": 9,
              "excerpt": "citação literal"
            }
          ],
          "effect_summary": "resumo curto do que o documento faz"
        }

        Se o documento não trouxer nada de uma seção, devolva a lista vazia.
        Não adicione texto antes ou depois do JSON.
        PROMPT;
    }
}
