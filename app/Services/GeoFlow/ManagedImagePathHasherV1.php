<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Frozen v1 identity contract for managed image paths and migration replay.
 */
class ManagedImagePathHasherV1
{
    /** @var array<string,bool> */
    private array $caseInsensitiveRootsV1 = [];

    /** @var array<string,bool> */
    private array $normalizationInsensitiveRootsV1 = [];

    public function hashManagedPathV1(string $path): string
    {
        [$dbPath, $root] = $this->parseManagedPathV1($path);

        return hash('sha256', $this->physicalIdentityForResolvedPathV1($dbPath, $root));
    }

    public function terminalHashV1(string $path): string
    {
        return hash('sha256', "geoflow:unresolvable-managed-image-path:v1\0".$path);
    }

    protected function physicalIdentityForResolvedPathV1(string $dbPath, string $root): string
    {
        $normalizationInsensitive = $this->normalizationInsensitiveRootsV1[$root]
            ??= $this->filesystemIsNormalizationInsensitive($root);
        $caseInsensitive = $this->caseInsensitiveRootsV1[$root]
            ??= $this->filesystemIsCaseInsensitive($root);
        $identityPath = $normalizationInsensitive ? $this->normalizePathV1($dbPath) : $dbPath;

        return $caseInsensitive ? $this->caseFoldPathV1($identityPath) : $identityPath;
    }

    /**
     * @return array{string,string}
     */
    private function parseManagedPathV1(string $path): array
    {
        if ($path === '' || trim($path) !== $path) {
            throw new InvalidArgumentException('invalid_path');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('control_character');
        }
        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException('backslash');
        }
        if (str_contains($path, '%')) {
            throw new InvalidArgumentException('percent_encoding');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1) {
            throw new InvalidArgumentException('uri_or_drive_path');
        }
        if (str_starts_with($path, '/') || str_contains($path, '//')) {
            throw new InvalidArgumentException('absolute_or_duplicate_separator');
        }

        if (str_starts_with($path, 'storage/uploads/images/')) {
            $remainder = substr($path, strlen('storage/uploads/images/'));
            $dbPath = 'storage/uploads/images/'.$remainder;
            $root = Storage::disk('public')->path('uploads/images');
        } elseif (str_starts_with($path, 'uploads/images/')) {
            $remainder = substr($path, strlen('uploads/images/'));
            $dbPath = 'uploads/images/'.$remainder;
            $root = public_path('uploads/images');
        } else {
            throw new InvalidArgumentException('unmanaged_prefix');
        }

        $segments = explode('/', $remainder);
        if ($remainder === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('invalid_segment');
        }

        return [$dbPath, $root];
    }

    private function caseFoldPathV1(string $path): string
    {
        if (function_exists('mb_convert_case') && defined('MB_CASE_FOLD')) {
            return mb_convert_case($path, MB_CASE_FOLD, 'UTF-8');
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($path, 'UTF-8')
            : strtolower($path);
    }

    private function normalizePathV1(string $path): string
    {
        if (! class_exists(\Normalizer::class)) {
            throw new RuntimeException('managed_image_path_normalization_failed');
        }

        $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
        if (! is_string($normalized)) {
            throw new RuntimeException('managed_image_path_normalization_failed');
        }

        return $normalized;
    }

    protected function filesystemIsCaseInsensitive(string $root): bool
    {
        $this->ensureProbeRootV1($root, 'managed_image_case_probe_failed');
        $suffix = bin2hex(random_bytes(12));
        $lowerPath = $root.DIRECTORY_SEPARATOR.'.geoflow-case-probe-a'.$suffix;
        $upperPath = $root.DIRECTORY_SEPARATOR.'.GEOFLOW-CASE-PROBE-A'.$suffix;

        return $this->probeAliasesV1($lowerPath, $upperPath, 'managed_image_case_probe_failed');
    }

    protected function filesystemIsNormalizationInsensitive(string $root): bool
    {
        $this->ensureProbeRootV1($root, 'managed_image_normalization_probe_failed');
        $suffix = bin2hex(random_bytes(12));
        $composedPath = $root.DIRECTORY_SEPARATOR.'.geoflow-normalization-probe-é-'.$suffix;
        $decomposedPath = $root.DIRECTORY_SEPARATOR.".geoflow-normalization-probe-e\u{0301}-".$suffix;

        return $this->probeAliasesV1($composedPath, $decomposedPath, 'managed_image_normalization_probe_failed');
    }

    private function ensureProbeRootV1(string $root, string $error): void
    {
        if (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new RuntimeException($error);
        }
        if (is_link($root)) {
            throw new RuntimeException($error);
        }
    }

    private function probeAliasesV1(string $primaryPath, string $aliasPath, string $error): bool
    {
        $handle = @fopen($primaryPath, 'x');
        if ($handle === false) {
            throw new RuntimeException($error);
        }

        try {
            clearstatcache(true, $primaryPath);
            clearstatcache(true, $aliasPath);
            $primaryStat = @stat($primaryPath);
            $aliasStat = @stat($aliasPath);

            return is_array($primaryStat)
                && is_array($aliasStat)
                && $primaryStat['dev'] === $aliasStat['dev']
                && $primaryStat['ino'] === $aliasStat['ino'];
        } finally {
            fclose($handle);
            @unlink($primaryPath);
            @unlink($aliasPath);
            clearstatcache(true, $primaryPath);
            clearstatcache(true, $aliasPath);
        }
    }
}
