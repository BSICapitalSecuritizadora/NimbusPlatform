<?php

namespace App\Services\Nimbus\Migration;

/**
 * Resolves legacy file paths to source filesystem candidates.
 * Never writes legacy absolute paths to target.
 */
class LegacyFilePathResolver
{
    public function __construct(private readonly string $legacyRoot) {}

    /**
     * @param  LegacySubmissionFileDto|LegacyPortalDocumentDto|LegacyGeneralDocumentDto  $fileDto
     * @param  string[]  $availableFiles  list of absolute/relative source paths available (read-only enumeration)
     * @return array{status: string, resolved_path: ?string, candidates: string[]}
     */
    public function resolve(mixed $fileDto, array $availableFiles): array
    {
        $legacyPath = $fileDto->storagePath ?? $fileDto->filePath ?? '';
        $candidates = $this->candidatePaths($legacyPath);

        $matches = [];
        foreach ($candidates as $candidate) {
            foreach ($availableFiles as $avail) {
                // Normalize both for comparison (separators, case-sensitive? keep case-sensitive for now)
                $normAvail = str_replace('\\', '/', $avail);
                $normCandidate = str_replace('\\', '/', $candidate);
                // Exact suffix match or exact basename match
                if ($normAvail === $normCandidate || str_ends_with($normAvail, $normCandidate)) {
                    $matches[$candidate][] = $avail;
                }
            }
        }

        // Flatten matches
        $allMatches = [];
        foreach ($matches as $list) {
            foreach ($list as $m) {
                $allMatches[] = $m;
            }
        }
        $allMatches = array_values(array_unique($allMatches));

        // Also try basename fallback only if exactly one file with that basename exists
        if (count($allMatches) === 0) {
            $basename = basename(str_replace('\\', '/', $legacyPath));
            $basenameMatches = array_values(array_filter($availableFiles, fn ($f) => basename(str_replace('\\', '/', $f)) === $basename));
            if (count($basenameMatches) === 1) {
                return ['status' => 'RESOLVED', 'resolved_path' => $basenameMatches[0], 'candidates' => $candidates, 'fallback' => 'BASENAME_UNIQUE'];
            }
            if (count($basenameMatches) === 0) {
                return ['status' => 'MISSING_SOURCE_FILE', 'resolved_path' => null, 'candidates' => $candidates];
            }

            // Multiple basename candidates → ambiguous
            return ['status' => 'AMBIGUOUS_SOURCE_FILE', 'resolved_path' => null, 'candidates' => $candidates, 'basename_matches' => $basenameMatches];
        }

        if (count($allMatches) === 1) {
            return ['status' => 'RESOLVED', 'resolved_path' => $allMatches[0], 'candidates' => $candidates];
        }

        // Multiple candidate matches — need disambiguation by checksum/size if available, else ambiguous fail-closed
        // For M1, we treat multiple exact candidate matches as ambiguous unless caller provides checksum filtering.
        return ['status' => 'AMBIGUOUS_SOURCE_FILE', 'resolved_path' => null, 'candidates' => $candidates, 'matches' => $allMatches];
    }

    /**
     * Build deterministic candidate list from legacy path.
     *
     * @return string[]
     */
    public function candidatePaths(string $legacyPath): array
    {
        $p = trim($legacyPath);
        // Windows separators → /
        $p = str_replace('\\', '/', $p);
        // Strip drive letter C:/...
        $p = preg_replace('#^[A-Za-z]:/#', '/', $p);
        // Resolve . and ..
        $p = $this->normalizeDots($p);

        $candidates = [];

        // 1. Normalized path as-is (after stripping)
        $candidates[] = ltrim($p, '/');

        // 2. Strip known NimbusDocs prefixes
        $prefixes = [
            'xampp/htdocs/NimbusDocs/storage/documents/',
            'xampp/htdocs/NimbusDocs/storage/general_documents/',
            'xampp/htdocs/NimbusDocs/src/Presentation/Controller/Admin/../../../../storage/general_documents/',
            'storage/documents/',
            'storage/general_documents/',
            'storage/',
            'documents/',
            'general_documents/',
        ];
        foreach ($prefixes as $pref) {
            if (str_starts_with(ltrim($p, '/'), $pref)) {
                $candidates[] = substr(ltrim($p, '/'), strlen($pref));
            }
            // Also try with leading slash
            if (str_starts_with($p, '/'.$pref)) {
                $candidates[] = substr($p, strlen('/'.$pref));
            }
        }

        // 3. Basename only (for fallback logic, but also as candidate)
        $candidates[] = basename($p);

        // Dedupe, remove empty
        $candidates = array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '' && $c !== '.')));

        // Also add legacyRoot-prefixed versions for enumeration comparison
        $withRoot = [];
        foreach ($candidates as $c) {
            $withRoot[] = rtrim($this->legacyRoot, '/').'/'.$c;
            $withRoot[] = $c;
        }

        return array_values(array_unique(array_merge($candidates, $withRoot)));
    }

    private function normalizeDots(string $path): string
    {
        $parts = explode('/', $path);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($out);

                continue;
            }
            $out[] = $part;
        }
        // Preserve leading slash if original had it
        $leading = str_starts_with($path, '/') ? '/' : '';

        return $leading.implode('/', $out);
    }

    /**
     * Planned target private path — never contains C:\xampp or ..
     */
    public static function plannedTargetPath(string $entity, int|string $targetSubmissionId, string $storedName): string
    {
        $safeName = basename(str_replace('\\', '/', $storedName));

        return match ($entity) {
            'portal_submission_files' => "submissions/{$targetSubmissionId}/{$safeName}",
            'portal_documents' => "portal_documents/{$targetSubmissionId}/{$safeName}",
            'general_documents' => "general_documents/{$targetSubmissionId}/{$safeName}",
            default => "nimbus/{$entity}/{$safeName}",
        };
    }
}
