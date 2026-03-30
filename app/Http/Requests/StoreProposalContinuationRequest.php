<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProposalContinuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $proposalId = $this->route('access')?->proposal_id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'site' => ['nullable', 'url', 'max:255'],
            'valor_solicitado' => ['required', 'string', 'max:50'],
            'valor_mercado_terreno' => ['nullable', 'string', 'max:50'],
            'area_terreno' => ['required', 'numeric', 'min:0'],
            'data_lancamento' => ['required', 'date_format:Y-m'],
            'lancamento_vendas' => ['required', 'date_format:Y-m'],
            'inicio_obras' => ['required', 'date_format:Y-m'],
            'previsao_entrega' => ['required', 'date_format:Y-m'],
            'prazo_remanescente' => ['nullable', 'integer', 'min:0'],
            'cep' => ['required', 'string', 'max:9'],
            'logradouro' => ['required', 'string', 'max:255'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:50'],
            'bairro' => ['required', 'string', 'max:255'],
            'cidade' => ['required', 'string', 'max:255'],
            'estado' => ['required', 'string', 'size:2'],
            'project_id' => ['nullable', 'array'],
            'project_id.*' => [
                'nullable',
                'integer',
                Rule::exists('proposal_projects', 'id')->where(
                    fn ($query) => $query->where('proposal_id', $proposalId),
                ),
            ],
            'nome_empreendimento' => ['required', 'array', 'min:1'],
            'nome_empreendimento.*' => ['required', 'string', 'max:255'],
            'unidades_permutadas' => ['required', 'array'],
            'unidades_permutadas.*' => ['nullable', 'integer', 'min:0'],
            'unidades_quitadas' => ['required', 'array'],
            'unidades_quitadas.*' => ['nullable', 'integer', 'min:0'],
            'unidades_nao_quitadas' => ['required', 'array'],
            'unidades_nao_quitadas.*' => ['nullable', 'integer', 'min:0'],
            'unidades_estoque' => ['required', 'array'],
            'unidades_estoque.*' => ['nullable', 'integer', 'min:0'],
            'custo_incidido' => ['required', 'array'],
            'custo_incidido.*' => ['nullable', 'string', 'max:50'],
            'custo_a_incorrer' => ['required', 'array'],
            'custo_a_incorrer.*' => ['nullable', 'string', 'max:50'],
            'valor_quitadas' => ['required', 'array'],
            'valor_quitadas.*' => ['nullable', 'string', 'max:50'],
            'valor_nao_quitadas' => ['required', 'array'],
            'valor_nao_quitadas.*' => ['nullable', 'string', 'max:50'],
            'valor_estoque' => ['required', 'array'],
            'valor_estoque.*' => ['nullable', 'string', 'max:50'],
            'valor_ja_recebido' => ['required', 'array'],
            'valor_ja_recebido.*' => ['nullable', 'string', 'max:50'],
            'valor_ate_chaves' => ['required', 'array'],
            'valor_ate_chaves.*' => ['nullable', 'string', 'max:50'],
            'valor_chaves_pos' => ['required', 'array'],
            'valor_chaves_pos.*' => ['nullable', 'string', 'max:50'],
            'car_bloco' => ['required', 'integer', 'min:1'],
            'car_pavimentos' => ['required', 'integer', 'min:1'],
            'car_andares_tipo' => ['required', 'integer', 'min:1'],
            'car_unidades_andar' => ['required', 'integer', 'min:1'],
            'car_total' => ['nullable', 'integer', 'min:1'],
            'tipo_total' => ['required', 'array', 'min:1'],
            'tipo_total.*' => ['required', 'integer', 'min:1'],
            'tipo_dormitorios' => ['required', 'array', 'min:1'],
            'tipo_dormitorios.*' => ['required', 'string', 'max:255'],
            'tipo_vagas' => ['required', 'array', 'min:1'],
            'tipo_vagas.*' => ['required', 'string', 'max:255'],
            'tipo_area' => ['required', 'array', 'min:1'],
            'tipo_area.*' => ['required', 'numeric', 'gt:0'],
            'tipo_preco_medio' => ['required', 'array', 'min:1'],
            'tipo_preco_medio.*' => ['required', 'string', 'max:50'],
            'arquivos' => ['nullable', 'array'],
            'arquivos.*' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome principal do empreendimento é obrigatório.',
            'nome_empreendimento.required' => 'Adicione ao menos um empreendimento.',
            'nome_empreendimento.*.required' => 'A identificação de cada empreendimento é obrigatória.',
            'data_lancamento.date_format' => 'O lançamento deve estar no formato mm/aaaa.',
            'lancamento_vendas.date_format' => 'O lançamento das vendas deve estar no formato mm/aaaa.',
            'inicio_obras.date_format' => 'O início das obras deve estar no formato mm/aaaa.',
            'previsao_entrega.date_format' => 'A previsão de entrega deve estar no formato mm/aaaa.',
            'arquivos.*.mimes' => 'Os arquivos devem estar nos formatos PDF, DOC, DOCX, XLS, XLSX, PNG, JPG ou JPEG.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                filled($this->input('inicio_obras'))
                && filled($this->input('previsao_entrega'))
                && $this->input('previsao_entrega') < $this->input('inicio_obras')
            ) {
                $validator->errors()->add('previsao_entrega', 'A previsão de entrega deve ser posterior ao início das obras.');
            }

            $expectedProjects = count($this->input('nome_empreendimento', []));
            $perProjectFields = [
                'unidades_permutadas',
                'unidades_quitadas',
                'unidades_nao_quitadas',
                'unidades_estoque',
                'custo_incidido',
                'custo_a_incorrer',
                'valor_quitadas',
                'valor_nao_quitadas',
                'valor_estoque',
                'valor_ja_recebido',
                'valor_ate_chaves',
                'valor_chaves_pos',
            ];
            $typeFields = [
                'tipo_total',
                'tipo_dormitorios',
                'tipo_vagas',
                'tipo_area',
                'tipo_preco_medio',
            ];

            foreach ($perProjectFields as $field) {
                if (count($this->input($field, [])) !== $expectedProjects) {
                    $validator->errors()->add($field, 'Os blocos de empreendimentos enviados estão incompletos.');
                }
            }

            if (filled($this->input('project_id')) && (count($this->input('project_id', [])) !== $expectedProjects)) {
                $validator->errors()->add('project_id', 'Os identificadores dos empreendimentos enviados estão incompletos.');
            }

            $expectedTypes = count($this->input('tipo_total', []));

            foreach ($typeFields as $field) {
                if (count($this->input($field, [])) !== $expectedTypes) {
                    $validator->errors()->add($field, 'Os tipos enviados estão incompletos.');
                }
            }
        });
    }
}
