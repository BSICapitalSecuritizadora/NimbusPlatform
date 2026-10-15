<?php

use App\Http\Middleware\ApplySpreadsheetImportUploadRules;

return [
    'temporary_file_upload' => [
        /*
        |----------------------------------------------------------------------
        | Disco dos envios temporários
        |----------------------------------------------------------------------
        |
        | O padrão é o disco `local`, e não o `filesystems.default`.
        |
        | Sem valor aqui, o Livewire cai no disco padrão da aplicação, que em
        | produção é o `azure` (container público). Ali o `getRealPath()` do
        | envio devolve um caminho relativo (`livewire-tmp/<nome>.xlsx`): as
        | conferências das importações não liam a planilha, e planilhas com
        | CPF/CNPJ ficavam no container público. O `local` grava em
        | `PRIVATE_STORAGE_ROOT`, fora do wwwroot, compartilhado entre as
        | instâncias e sem URL pública (`serve => false`).
        |
        | `?:` em vez do segundo argumento de `env()`: uma variável definida e
        | vazia chega aqui como `''`, e o Livewire trataria `''` como ausente --
        | de volta ao disco padrão.
        |
        | Nunca aponte para um disco remoto (`azure`, `private`, `s3`): o envio
        | carrega dados pessoais, e uma conferência precisa ler a planilha do
        | disco. A leitura passa por `App\Support\Uploads\LocalUploadedFile`, que
        | copia o arquivo quando não há caminho local, mas a cópia é a defesa, não
        | o caminho previsto.
        |
        */
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK') ?: 'local',
        'rules' => [
            'required',
            'file',
            'max:'.(int) env('LIVEWIRE_TEMPORARY_UPLOAD_MAX_KB', 102400),
            'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
        ],

        /*
        |----------------------------------------------------------------------
        | Perfil de importação de planilha (CSV/XLSX)
        |----------------------------------------------------------------------
        |
        | A regra acima vale para todo envio temporário e não aceita CSV. Os
        | campos de importação que leem CSV (índices publicados, série projetada
        | do IPCA, cronograma de pagamentos) pedem uma URL de envio assinada com
        | `purpose=spreadsheet-import`, e só nela o middleware abaixo troca a
        | regra por esta. Os demais envios da aplicação não mudam.
        |
        | `mimes` lê o conteúdo, não o nome: CSV separado por vírgula sai como
        | `text/csv`, os demais textos como `text/plain` (`txt`). Executável,
        | HTML, PHP ou PDF renomeado para `.csv` é recusado aqui. `extensions`
        | exige o nome `.csv`/`.xlsx`. Um texto qualquer que passe por CSV ainda
        | encontra os tipos aceitos do campo e a leitura estrita do importador.
        |
        */
        'spreadsheet_import_rules' => [
            'required',
            'file',
            'max:10240',
            'mimes:csv,txt,xlsx',
            'extensions:csv,xlsx',
        ],
        'directory' => null,
        // O `throttle:60,1` é o padrão do Livewire quando a chave é nula.
        'middleware' => ['throttle:60,1', ApplySpreadsheetImportUploadRules::class],
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => (int) env('LIVEWIRE_TEMPORARY_MAX_UPLOAD_TIME', 15),
        'cleanup' => true,
    ],

    'csp_safe' => false,
];
