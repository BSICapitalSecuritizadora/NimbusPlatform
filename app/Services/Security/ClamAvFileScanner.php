<?php

namespace App\Services\Security;

/**
 * Synchronous, opt-in ClamAV scanner used to block dangerous uploads before
 * they are persisted (e.g. obligation evidences).
 *
 * It reuses the project-wide `uploads.clamav.*` configuration already consumed
 * by the asynchronous {@see \App\Jobs\ScanFileForMalware} job, but performs the
 * check inline so the caller can reject the upload with a friendly message.
 *
 * When disabled (the default), every scan returns CLEAN so local development
 * and environments without a clamd daemon are never blocked.
 */
class ClamAvFileScanner
{
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

        $socket = config('uploads.clamav.socket');
        $address = $socket
            ? "unix://{$socket}"
            : 'tcp://'.config('uploads.clamav.host', '127.0.0.1').':'.config('uploads.clamav.port', 3310);

        $response = $this->sendScanCommand($address, $absolutePath);

        if ($response === null) {
            return self::RESULT_UNAVAILABLE;
        }

        return str_ends_with(trim($response), 'OK')
            ? self::RESULT_CLEAN
            : self::RESULT_INFECTED;
    }

    private function sendScanCommand(string $address, string $filePath): ?string
    {
        $timeout = (int) config('uploads.clamav.timeout', 30);

        $stream = @stream_socket_client($address, $errno, $errstr, $timeout);

        if (! $stream) {
            return null;
        }

        fwrite($stream, "SCAN {$filePath}\n");

        $response = '';
        while (! feof($stream)) {
            $response .= fread($stream, 4096);
        }

        fclose($stream);

        return $response;
    }
}
