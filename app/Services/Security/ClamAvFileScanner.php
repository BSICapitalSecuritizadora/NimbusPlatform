<?php

namespace App\Services\Security;

use App\Jobs\ScanFileForMalware;
use Illuminate\Support\Str;

/**
 * Synchronous, opt-in ClamAV scanner used to block dangerous uploads before
 * they are persisted (e.g. obligation evidences).
 *
 * It reuses the project-wide `uploads.clamav.*` configuration already consumed
 * by the asynchronous {@see ScanFileForMalware} job, but performs the
 * check inline so the caller can reject the upload with a friendly message.
 *
 * When disabled (the default), every scan returns CLEAN so local development
 * and environments without a clamd daemon are never blocked.
 */
class ClamAvFileScanner
{
    private const STREAM_CHUNK_BYTES = 8192;

    public const RESULT_CLEAN = 'clean';

    public const RESULT_INFECTED = 'infected';

    public const RESULT_UNAVAILABLE = 'unavailable';

    public function isEnabled(): bool
    {
        return (bool) config('uploads.clamav.enabled', false);
    }

    /**
     * Scan a file on disk. Returns one of the RESULT_* constants.
     *
     * - CLEAN when scanning is disabled or clamd reports the file is OK;
     * - INFECTED when clamd reports a signature match;
     * - UNAVAILABLE when the file is missing or clamd cannot be reached.
     */
    public function scan(?string $absolutePath): string
    {
        if (! $this->isEnabled()) {
            return self::RESULT_CLEAN;
        }

        if ($absolutePath === null || ! is_file($absolutePath)) {
            return self::RESULT_UNAVAILABLE;
        }

        $fileStream = @fopen($absolutePath, 'rb');

        if (! is_resource($fileStream)) {
            return self::RESULT_UNAVAILABLE;
        }

        try {
            return $this->scanStream($fileStream);
        } finally {
            fclose($fileStream);
        }
    }

    /**
     * @param  resource  $fileStream
     */
    public function scanStream(mixed $fileStream): string
    {
        if (! $this->isEnabled() || ! is_resource($fileStream)) {
            return self::RESULT_UNAVAILABLE;
        }

        $socket = config('uploads.clamav.socket');
        $address = $socket
            ? "unix://{$socket}"
            : 'tcp://'.config('uploads.clamav.host', '127.0.0.1').':'.config('uploads.clamav.port', 3310);

        $response = $this->sendStreamScanCommand($address, $fileStream);

        if ($response === null) {
            return self::RESULT_UNAVAILABLE;
        }

        $normalizedResponse = trim(str_replace("\0", '', $response));

        if (Str::endsWith($normalizedResponse, 'OK')) {
            return self::RESULT_CLEAN;
        }

        if (Str::contains($normalizedResponse, 'FOUND')) {
            return self::RESULT_INFECTED;
        }

        return self::RESULT_UNAVAILABLE;
    }

    /**
     * @param  resource  $fileStream
     */
    private function sendStreamScanCommand(string $address, mixed $fileStream): ?string
    {
        $timeout = (int) config('uploads.clamav.timeout', 30);

        $socketStream = @stream_socket_client($address, $errorCode, $errorMessage, $timeout);

        if (! is_resource($socketStream)) {
            return null;
        }

        stream_set_timeout($socketStream, $timeout);

        try {
            if (! $this->writeAll($socketStream, "zINSTREAM\0")) {
                return null;
            }

            while (! feof($fileStream)) {
                $chunk = fread($fileStream, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    return null;
                }

                if ($chunk !== '' && ! $this->writeAll($socketStream, pack('N', strlen($chunk)).$chunk)) {
                    return null;
                }
            }

            if (! $this->writeAll($socketStream, pack('N', 0))) {
                return null;
            }

            $response = '';

            while (! feof($socketStream)) {
                $responseChunk = fread($socketStream, 4096);

                if ($responseChunk === false) {
                    return null;
                }

                if ($responseChunk === '') {
                    break;
                }

                $response .= $responseChunk;

                if (str_contains($response, "\0")) {
                    break;
                }
            }

            $metadata = stream_get_meta_data($socketStream);

            return ($metadata['timed_out'] ?? false) ? null : $response;
        } finally {
            fclose($socketStream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll(mixed $stream, string $data): bool
    {
        while ($data !== '') {
            $writtenBytes = fwrite($stream, $data);

            if ($writtenBytes === false || $writtenBytes === 0) {
                return false;
            }

            $data = substr($data, $writtenBytes);
        }

        return true;
    }
}
