<?php

/*
|--------------------------------------------------------------------------
| Private Storage Root
|--------------------------------------------------------------------------
|
| Raiz dos documentos privados (documentos de operações, arquivos de
| propostas/submissões e currículos). Em produção ela DEVE apontar para um
| diretório fora da pasta de deploy — por exemplo `/home/data/private` no
| Azure App Service — porque o pacote de deploy substitui o conteúdo de
| `/home/site/wwwroot` e apagaria os arquivos já enviados.
|
| Só caminhos absolutos são aceitos: um valor relativo (ou um nome de disco
| informado por engano) faria o Laravel gravar em um diretório relativo ao CWD
| do processo — php-fpm, filas e artisan cada um em um lugar diferente. Nesse
| caso o valor é ignorado e vale o padrão da aplicação.
|
*/

$configuredPrivateStorageRoot = rtrim((string) env('PRIVATE_STORAGE_ROOT', ''), '/');

$privateStorageRoot = str_starts_with($configuredPrivateStorageRoot, '/')
    ? $configuredPrivateStorageRoot
    : storage_path('app/private');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Private Documents Disk
    |--------------------------------------------------------------------------
    |
    | Disco usado pelo `DocumentStorageService` para gravar e ler documentos
    | privados. O padrão `local` grava em `PRIVATE_STORAGE_ROOT`; troque para
    | `private` (Azure Blob Storage) quando o container estiver provisionado.
    |
    */

    'private_disk' => env('PRIVATE_FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => $privateStorageRoot,
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'resumes' => [
            'driver' => 'local',
            'root' => $privateStorageRoot.'/resumes',
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'azure' => [
            'driver' => 'azure-storage-blob',
            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
            'container' => env('AZURE_STORAGE_CONTAINER'),
        ],

        'private' => [
            'driver' => 'azure-storage-blob',
            'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
            'container' => env('AZURE_STORAGE_PRIVATE_CONTAINER', 'bsi-docs-privados'),
            'visibility' => 'private',
            'throw' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
