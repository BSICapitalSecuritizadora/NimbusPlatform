<?php

namespace App\Support\Proposals;

use App\Rules\NonNegativeDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class ContinuationValidationRules
{
    /** @return array<string, mixed> */
    public function livewire(int $proposalId): array
    {
        return [
            'developmentName' => ['required', 'string', 'max:255'],
            'websiteUrl' => ['nullable', 'url', 'max:255'],
            'requestedAmount' => ['required', new NonNegativeDecimal],
            'landMarketValue' => ['nullable', new NonNegativeDecimal],
            'landArea' => ['required', new NonNegativeDecimal],
            'launchDate' => ['required', 'date_format:Y-m'],
            'salesLaunchDate' => ['required', 'date_format:Y-m'],
            'constructionStartDate' => ['required', 'date_format:Y-m'],
            'deliveryForecastDate' => ['required', 'date_format:Y-m', 'after_or_equal:constructionStartDate'],
            'remainingMonths' => ['nullable', 'integer', 'min:0'],
            'zipCode' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:255'],
            'addressComplement' => ['nullable', 'string', 'max:255'],
            'addressNumber' => ['required', 'string', 'max:50'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'projects' => ['required', 'array', 'min:1', 'max:20'],
            'projects.*.id' => ['nullable', 'integer', Rule::exists('proposal_projects', 'id')->where('proposal_id', $proposalId)],
            'projects.*.name' => ['required', 'string', 'max:255'],
            'projects.*.exchangedUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.paidUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.unpaidUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.stockUnits' => ['nullable', 'integer', 'min:0'],
            'projects.*.incurredCost' => ['nullable', new NonNegativeDecimal],
            'projects.*.costToIncur' => ['nullable', new NonNegativeDecimal],
            'projects.*.paidSalesValue' => ['nullable', new NonNegativeDecimal],
            'projects.*.unpaidSalesValue' => ['nullable', new NonNegativeDecimal],
            'projects.*.stockSalesValue' => ['nullable', new NonNegativeDecimal],
            'projects.*.receivedValue' => ['nullable', new NonNegativeDecimal],
            'projects.*.valueUntilKeys' => ['nullable', new NonNegativeDecimal],
            'projects.*.valueAfterKeys' => ['nullable', new NonNegativeDecimal],
            'blockCount' => ['required', 'integer', 'min:1'],
            'floorCount' => ['required', 'integer', 'min:1'],
            'typicalFloorCount' => ['required', 'integer', 'min:1'],
            'unitsPerFloor' => ['required', 'integer', 'min:1'],
            'totalUnits' => ['nullable', 'integer', 'min:1'],
            'unitTypes' => ['required', 'array', 'min:1', 'max:20'],
            'unitTypes.*.totalUnits' => ['required', 'integer', 'min:1'],
            'unitTypes.*.bedrooms' => ['required', 'string', 'max:255'],
            'unitTypes.*.parkingSpaces' => ['required', 'string', 'max:255'],
            'unitTypes.*.usableArea' => ['required', 'numeric', 'gt:0'],
            'unitTypes.*.averagePrice' => ['required', new NonNegativeDecimal],
            'uploads' => ['nullable', 'array', 'max:10'],
            'uploads.*' => [$this->fileRule()],
        ];
    }

    /** @return array<string, mixed> */
    public function http(int $proposalId): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'site' => ['nullable', 'url', 'max:255'],
            'valor_solicitado' => ['required', new NonNegativeDecimal],
            'valor_mercado_terreno' => ['nullable', new NonNegativeDecimal],
            'area_terreno' => ['required', new NonNegativeDecimal],
            'data_lancamento' => ['required', 'date_format:Y-m'],
            'lancamento_vendas' => ['required', 'date_format:Y-m'],
            'inicio_obras' => ['required', 'date_format:Y-m'],
            'previsao_entrega' => ['required', 'date_format:Y-m', 'after_or_equal:inicio_obras'],
            'prazo_remanescente' => ['nullable', 'integer', 'min:0'],
            'cep' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            'logradouro' => ['required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:50'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'estado' => ['required', 'string', 'size:2'],
            'nome_empreendimento' => ['required', 'array', 'min:1', 'max:20'],
            'nome_empreendimento.*' => ['required', 'string', 'max:255'],
            'project_id' => ['nullable', 'array'],
            'project_id.*' => ['nullable', 'integer', Rule::exists('proposal_projects', 'id')->where('proposal_id', $proposalId)],
            ...$this->projectArrayRules('unidades_permutadas', ['nullable', 'integer', 'min:0']),
            ...$this->projectArrayRules('unidades_quitadas', ['nullable', 'integer', 'min:0']),
            ...$this->projectArrayRules('unidades_nao_quitadas', ['nullable', 'integer', 'min:0']),
            ...$this->projectArrayRules('unidades_estoque', ['nullable', 'integer', 'min:0']),
            ...$this->projectArrayRules('custo_incidido', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('custo_a_incorrer', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_quitadas', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_nao_quitadas', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_estoque', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_ja_recebido', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_ate_chaves', ['nullable', new NonNegativeDecimal]),
            ...$this->projectArrayRules('valor_chaves_pos', ['nullable', new NonNegativeDecimal]),
            'car_bloco' => ['required', 'integer', 'min:1'],
            'car_pavimentos' => ['required', 'integer', 'min:1'],
            'car_andares_tipo' => ['required', 'integer', 'min:1'],
            'car_unidades_andar' => ['required', 'integer', 'min:1'],
            'car_total' => ['nullable', 'integer', 'min:1'],
            'tipo_total' => ['required', 'array', 'min:1', 'max:20'],
            'tipo_total.*' => ['required', 'integer', 'min:1'],
            'tipo_dormitorios' => ['required', 'array'],
            'tipo_dormitorios.*' => ['required', 'string', 'max:255'],
            'tipo_vagas' => ['required', 'array'],
            'tipo_vagas.*' => ['required', 'string', 'max:255'],
            'tipo_area' => ['required', 'array'],
            'tipo_area.*' => ['required', 'numeric', 'gt:0'],
            'tipo_preco_medio' => ['required', 'array'],
            'tipo_preco_medio.*' => ['required', new NonNegativeDecimal],
            'arquivos' => ['nullable', 'array', 'max:10'],
            'arquivos.*' => [$this->fileRule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxMb = (int) ceil($this->maxUploadKilobytes() / 1024);

        return [
            'deliveryForecastDate.after_or_equal' => 'A previsão de entrega não pode ser anterior ao início das obras.',
            'previsao_entrega.after_or_equal' => 'A previsão de entrega não pode ser anterior ao início das obras.',
            'projects.required' => 'Informe ao menos um empreendimento vinculado à operação.',
            'nome_empreendimento.required' => 'Informe ao menos um empreendimento vinculado à operação.',
            'uploads.*.mimes' => 'Os arquivos devem estar nos formatos PDF, DOC, DOCX, XLS, XLSX, PNG, JPG ou JPEG.',
            'arquivos.*.mimes' => 'Os arquivos devem estar nos formatos PDF, DOC, DOCX, XLS, XLSX, PNG, JPG ou JPEG.',
            'uploads.*.max' => "Cada arquivo não pode exceder {$maxMb} MB.",
            'arquivos.*.max' => "Cada arquivo não pode exceder {$maxMb} MB.",
        ];
    }

    /**
     * @param  array<int, mixed>  $itemRules
     * @return array<string, mixed>
     */
    private function projectArrayRules(string $field, array $itemRules): array
    {
        return [
            $field => ['nullable', 'array'],
            "{$field}.*" => $itemRules,
        ];
    }

    private function fileRule(): File
    {
        return File::types((array) config('uploads.proposal_continuation.allowed_extensions'))
            ->max($this->maxUploadKilobytes());
    }

    private function maxUploadKilobytes(): int
    {
        return (int) config('uploads.proposal_continuation.max_kb', 20480);
    }
}
