<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\B3DiSource;
use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use Carbon\CarbonImmutable;
use FTP\Connection;
use RuntimeException;

final class B3DiFtpSource implements B3DiSource
{
    public function __construct(
        private readonly B3DiFileParser $parser,
    ) {}

    public function fetch(CarbonImmutable $from, CarbonImmutable $to): CdiSourceDataset
    {
        $capturedAt = CarbonImmutable::now();
        $host = (string) config('pu_indexes.b3_di.host');
        $port = (int) config('pu_indexes.b3_di.port', 21);
        $timeout = (int) config('pu_indexes.b3_di.timeout', 30);
        $directory = rtrim((string) config('pu_indexes.b3_di.directory', '/MediaCDI'), '/');
        $connection = @ftp_connect($host, $port, $timeout);

        if (! $connection instanceof Connection) {
            throw new RuntimeException(sprintf('Não foi possível conectar ao FTP oficial da B3 em %s:%d.', $host, $port));
        }

        try {
            $this->authenticate($connection);
            $files = @ftp_nlist($connection, $directory);

            if (! is_array($files)) {
                throw new RuntimeException(sprintf('Não foi possível listar o diretório B3 %s.', $directory));
            }

            $selectedFiles = $this->selectFiles($files, $directory, $from, $to);
            $records = [];
            $payloads = [];
            $issues = [];

            foreach ($selectedFiles as $remotePath) {
                $content = $this->download($connection, $remotePath);
                $sourceReference = sprintf('ftp://%s%s', $host, $remotePath);
                $record = $this->parser->parse(basename($remotePath), $content, $sourceReference);
                $records[] = $record;
                $payloads[] = [
                    'source_reference' => $sourceReference,
                    'sha256' => hash('sha256', $content),
                    'captured_at' => $capturedAt->toIso8601String(),
                    'content_base64' => base64_encode($content),
                ];

                if ($record->issue !== null) {
                    $issues[] = $record->toArray();
                }
            }

            $issues = [...$issues, ...$this->parser->duplicateIssues($records)];

            return new CdiSourceDataset(
                source: 'b3_di',
                requestedFrom: $from,
                requestedTo: $to,
                capturedAt: $capturedAt,
                records: $records,
                payloads: $payloads,
                issues: $issues,
                metadata: [
                    'host' => $host,
                    'directory' => $directory,
                    'file_pattern' => 'YYYYMMDD.txt',
                    'encoding' => '9 dígitos; duas casas decimais implícitas',
                    'unit' => '% a.a., base 252 Dias Úteis',
                    'published_scale' => 2,
                    'documentation_url' => config('pu_indexes.b3_di.documentation_url'),
                    'methodology_url' => config('pu_indexes.b3_di.methodology_url'),
                    'capture_method' => 'FTP público oficial, modo passivo e transferência binária',
                ],
            );
        } finally {
            ftp_close($connection);
        }
    }

    private function authenticate(Connection $connection): void
    {
        if (! @ftp_login(
            $connection,
            (string) config('pu_indexes.b3_di.username', 'anonymous'),
            (string) config('pu_indexes.b3_di.password', 'nimbusplatform@localhost'),
        )) {
            throw new RuntimeException('Não foi possível autenticar anonimamente no FTP oficial da B3.');
        }

        if (! @ftp_pasv($connection, (bool) config('pu_indexes.b3_di.passive', true))) {
            throw new RuntimeException('Não foi possível ativar o modo passivo no FTP oficial da B3.');
        }
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function selectFiles(array $files, string $directory, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return collect($files)
            ->filter(function (string $file) use ($from, $to): bool {
                if (preg_match('/(\d{8})\.txt$/', basename($file), $matches) !== 1) {
                    return false;
                }

                $record = $this->parser->parse(basename($file), '000000000');

                return $record->referenceDate !== null
                    && $record->referenceDate->betweenIncluded($from, $to);
            })
            ->map(function (string $file) use ($directory): string {
                if (str_starts_with($file, '/')) {
                    return $file;
                }

                return sprintf('%s/%s', $directory, ltrim($file, '/'));
            })
            ->sort()
            ->values()
            ->all();
    }

    private function download(Connection $connection, string $remotePath): string
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível abrir o buffer temporário da captura B3.');
        }

        try {
            if (! @ftp_fget($connection, $stream, $remotePath, FTP_BINARY)) {
                throw new RuntimeException(sprintf('Não foi possível capturar o arquivo oficial B3 %s.', $remotePath));
            }

            rewind($stream);
            $content = stream_get_contents($stream);

            if ($content === false) {
                throw new RuntimeException(sprintf('Não foi possível ler o arquivo oficial B3 %s.', $remotePath));
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }
}
