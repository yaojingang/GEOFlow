<?php

namespace App\Services\Admin;

use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackagePathGuard;
use App\Support\Site\SiteThemePackageStorage;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SiteThemePackageGuard
{
    public const FORMAT = 'geoflow-theme-package';

    public const CONTRACTS = ['site-theme-view-resolver' => 1];

    public const PAGES = ['home', 'category', 'article', 'about', 'archive-index', 'archive-month'];

    public const ASSET_MIMES = [
        'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png',
        'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'ttf' => 'font/ttf', 'otf' => 'font/otf',
    ];

    public function __construct(
        private readonly ThemeReplicationPackagePathGuard $paths,
        private readonly SiteThemePackageStorage $storage,
    ) {}

    public function themeId(string $id): string
    {
        try {
            return $this->paths->validatedThemeId($id);
        } catch (RuntimeException) {
            $this->storage->fail('invalid_theme_id');
        }
    }

    public function limit(string $name): int
    {
        $defaults = ['max_archive_bytes' => 10 * 1024 * 1024, 'max_files' => 500, 'max_file_bytes' => 5 * 1024 * 1024, 'max_total_bytes' => 25 * 1024 * 1024];

        return max(1, (int) config('geoflow.theme_packages.'.$name, $defaults[$name]));
    }

    public function filePath(string $path, string $id): void
    {
        $this->storage->relative($path);
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.') || str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
                $this->storage->fail('invalid_path');
            }
        }
        $views = 'resources/views/theme/'.$id.'/';
        $public = 'public/themes/'.$id.'/';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $asset = isset(self::ASSET_MIMES[$extension]);
        if (str_starts_with($path, $public) && $asset) {
            return;
        }
        if (str_starts_with($path, $views) && (str_ends_with($path, '.blade.php') || in_array($extension, ['json', 'md', 'txt'], true) || (str_starts_with($path, $views.'assets/') && $asset))) {
            return;
        }
        $this->storage->fail('file_not_allowed', ['path' => $path]);
    }

    /** @return array{package:array,risks:list<string>} */
    public function unpack(string $archive, string $destination): array
    {
        $this->storage->regular($archive);
        if (filesize($archive) > $this->limit('max_archive_bytes')) {
            $this->storage->fail('archive_too_large');
        }
        $zip = new ZipArchive;
        if (@$zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->storage->fail('invalid_archive');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > $this->limit('max_files') * 8 + 20) {
                $this->storage->fail('too_many_files');
            }
            $entries = [];
            $folded = [];
            $fileCount = 0;
            $headerBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                    $this->storage->fail('invalid_archive');
                }
                $name = $stat['name'];
                $directory = str_ends_with($name, '/');
                $canonical = $directory ? substr($name, 0, -1) : $name;
                $this->storage->relative($canonical);
                $lower = strtolower($canonical);
                if (isset($folded[$lower])) {
                    $this->storage->fail('duplicate_path');
                }
                $folded[$lower] = true;
                $os = $attributes = 0;
                if (! $zip->getExternalAttributesIndex($index, $os, $attributes, ZipArchive::FL_UNCHANGED)) {
                    $this->storage->fail('invalid_archive');
                }
                $type = ($attributes >> 16) & 0170000;
                if (($type !== 0 && $type !== ($directory ? 0040000 : 0100000)) || (($attributes & 0x10) !== 0 && ! $directory)) {
                    $this->storage->fail('invalid_archive_entry');
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    $this->storage->fail('encrypted_archive');
                }
                if (! is_int($stat['size'] ?? null) || $stat['size'] < 0 || $stat['size'] > ($name === 'package.json' ? 1024 * 1024 : $this->limit('max_file_bytes'))) {
                    $this->storage->fail('file_too_large');
                }
                if ($directory && $stat['size'] !== 0) {
                    $this->storage->fail('invalid_archive_entry');
                }
                if (! $directory && $name !== 'package.json') {
                    $fileCount++;
                    $headerBytes += $stat['size'];
                }
                if ($fileCount > $this->limit('max_files')) {
                    $this->storage->fail('too_many_files');
                }
                if ($headerBytes > $this->limit('max_total_bytes')) {
                    $this->storage->fail('total_too_large');
                }
                $entries[$name] = ['index' => $index, 'stat' => $stat, 'directory' => $directory];
            }
            if (! isset($entries['package.json'])) {
                $this->storage->fail('invalid_package');
            }
            $metadata = $this->readEntry($zip, $entries['package.json'], null, 1024 * 1024, 1024 * 1024);
            $package = json_decode($metadata['contents'], true);
            $files = $this->package($package);
            $id = $package['theme']['id'];
            $actual = [];
            $total = 0;
            foreach ($entries as $name => $entry) {
                if ($name === 'package.json') {
                    continue;
                }
                if ($entry['directory']) {
                    $this->directoryEntry($name, $id, array_keys($files));

                    continue;
                }
                $this->filePath($name, $id);
                if (! isset($files[$name])) {
                    $this->storage->fail('manifest_mismatch');
                }
                $target = $destination.'/'.$name;
                $this->storage->directory(dirname($target));
                $result = $this->readEntry($zip, $entry, $this->storage->path($target), $this->limit('max_file_bytes'), $this->limit('max_total_bytes') - $total);
                $total += $result['bytes'];
                if ($files[$name]['bytes'] !== $result['bytes'] || ! hash_equals($files[$name]['sha256'], $result['sha256'])) {
                    $this->storage->fail('manifest_mismatch');
                }
                $actual[$name] = true;
            }
            if (count($actual) !== count($files)) {
                $this->storage->fail('manifest_mismatch');
            }
            $manifest = $this->manifest($destination, $id);
            foreach (['id', 'name', 'version'] as $field) {
                if (isset($manifest[$field]) && $manifest[$field] !== $package['theme'][$field]) {
                    $this->storage->fail('manifest_mismatch');
                }
            }
            $risks = $this->dependencies($package, $destination, $manifest);

            return ['package' => $package, 'risks' => $risks];
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,array{path:string,bytes:int,sha256:string}> */
    public function package(mixed $package, bool $checkCompatibility = true, bool $checkLimits = true): array
    {
        if (! is_array($package) || ($package['format'] ?? null) !== self::FORMAT || ($package['format_version'] ?? null) !== 1 || ! is_array($package['theme'] ?? null)) {
            $this->storage->fail('invalid_package');
        }
        foreach (['id', 'name', 'version'] as $key) {
            if (! is_string($package['theme'][$key] ?? null) || trim($package['theme'][$key]) === '' || strlen($package['theme'][$key]) > 200) {
                $this->storage->fail('invalid_package');
            }
        }
        $id = $this->themeId($package['theme']['id']);
        foreach (['geoflow', 'php', 'laravel'] as $component) {
            if (! is_string($package['exported_with'][$component] ?? null) || $package['exported_with'][$component] === '') {
                $this->storage->fail('invalid_package');
            }
        }
        if (($package['exported_with']['contracts'] ?? null) !== self::CONTRACTS) {
            $this->storage->fail('incompatible', ['component' => 'exported_with.contracts']);
        }
        if (! is_string($package['created_at'] ?? null) || strtotime($package['created_at']) === false || ! is_array($package['exported_with'] ?? null) || ! is_array($package['requires'] ?? null) || ! is_array($package['distribution'] ?? null) || ! is_array($package['pages'] ?? null) || ! is_array($package['files'] ?? null) || ! array_is_list($package['files']) || ! $this->hashValue($package['content_sha256'] ?? null)) {
            $this->storage->fail('invalid_package');
        }
        $this->distributionMetadata($package['distribution']);
        if (count($package['files']) < 1 || ($checkLimits && count($package['files']) > $this->limit('max_files'))) {
            $this->storage->fail('too_many_files');
        }
        $files = [];
        $folded = [];
        $total = 0;
        $components = [];
        foreach ($package['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_int($file['bytes'] ?? null) || $file['bytes'] < 0 || ! $this->hashValue($file['sha256'] ?? null)) {
                $this->storage->fail('invalid_package');
            }
            $this->filePath($file['path'], $id);
            $prefix = '';
            foreach (explode('/', $file['path']) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
                $foldedPrefix = strtolower($prefix);
                if (isset($components[$foldedPrefix]) && $components[$foldedPrefix] !== $prefix) {
                    $this->storage->fail('duplicate_path');
                }
                $components[$foldedPrefix] = $prefix;
            }
            if (isset($folded[strtolower($file['path'])])) {
                $this->storage->fail('duplicate_path');
            }
            if ($checkLimits && $file['bytes'] > $this->limit('max_file_bytes')) {
                $this->storage->fail('file_too_large');
            }
            $total += $file['bytes'];
            if ($checkLimits && $total > $this->limit('max_total_bytes')) {
                $this->storage->fail('total_too_large');
            }
            $folded[strtolower($file['path'])] = true;
            $files[$file['path']] = $file;
        }
        foreach (array_keys($files) as $path) {
            $parent = dirname($path);
            while ($parent !== '.') {
                if (isset($folded[strtolower($parent)])) {
                    $this->storage->fail('duplicate_path');
                }
                $parent = dirname($parent);
            }
        }
        if (! hash_equals($package['content_sha256'], $this->contentHash(array_values($files)))) {
            $this->storage->fail('manifest_mismatch');
        }
        if (! isset($files['resources/views/theme/'.$id.'/home.blade.php'])) {
            $this->storage->fail('missing_home');
        }
        $expectedPages = $this->pages(array_values($files), $id);
        if (count($package['pages']) !== 2) {
            $this->storage->fail('invalid_package');
        }
        foreach ($expectedPages as $kind => $expected) {
            $actual = $package['pages'][$kind] ?? null;
            if (! is_array($actual) || ! array_is_list($actual)) {
                $this->storage->fail('invalid_package');
            }
            foreach ($actual as $page) {
                if (! is_string($page) || ! in_array($page, self::PAGES, true)) {
                    $this->storage->fail('invalid_package');
                }
            }
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                $this->storage->fail('invalid_package');
            }
        }
        if ($checkCompatibility) {
            $this->compatibility($package['requires']);
        }

        return $files;
    }

    public function contentHash(array $files): string
    {
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, $file['path']."\0".$file['bytes']."\0".$file['sha256']."\n");
        }

        return hash_final($hash);
    }

    public function manifest(string $root, string $id, ?int $maxBytes = null): array
    {
        $path = $root.'/resources/views/theme/'.$id.'/manifest.json';
        if (! is_file($this->storage->path($path))) {
            return [];
        }

        $manifest = $this->storage->readJson($path, $maxBytes ?? $this->limit('max_file_bytes'));
        foreach (['id', 'name', 'version', 'description', 'visibility'] as $field) {
            if (array_key_exists($field, $manifest) && ! is_string($manifest[$field])) {
                $this->storage->fail('invalid_package');
            }
        }
        if (array_key_exists('distribution', $manifest)) {
            $this->distributionMetadata($manifest['distribution']);
        }

        return $manifest;
    }

    private function distributionMetadata(mixed $distribution): void
    {
        if (! is_array($distribution)) {
            $this->storage->fail('invalid_package');
        }
        foreach (['visibility', 'note', 'customer'] as $field) {
            if (array_key_exists($field, $distribution) && ! is_string($distribution[$field])) {
                $this->storage->fail('invalid_package');
            }
        }
        if (array_key_exists('publish_to_repository', $distribution) && ! is_bool($distribution['publish_to_repository'])) {
            $this->storage->fail('invalid_package');
        }
    }

    /** @return array{provided:list<string>,fallback:list<string>} */
    public function pages(array $files, string $id): array
    {
        $paths = array_column($files, 'path');
        $pages = ['provided' => [], 'fallback' => []];
        foreach (self::PAGES as $page) {
            $kind = in_array('resources/views/theme/'.$id.'/'.$page.'.blade.php', $paths, true) ? 'provided' : 'fallback';
            $pages[$kind][] = $page;
        }

        return $pages;
    }

    public function exportRequirements(array $manifest, array $previous = []): array
    {
        return $this->mergeRequirements($this->defaultRequirements(), $previous, $this->manifestRequirements($manifest));
    }

    private function manifestRequirements(array $manifest): array
    {
        $dependencies = $manifest['dependencies'] ?? [];
        $requires = $manifest['requires'] ?? [];
        if (! is_array($dependencies) || ! is_array($requires)) {
            $this->storage->fail('invalid_package');
        }

        return $this->mergeRequirements($dependencies, $requires);
    }

    private function mergeRequirements(array ...$declarations): array
    {
        $merged = array_replace(...$declarations);
        foreach (['views', 'routes', 'assets'] as $kind) {
            $merged[$kind] = [];
            foreach ($declarations as $declaration) {
                $list = $declaration[$kind] ?? [];
                if (! is_array($list) || ! array_is_list($list)) {
                    $this->storage->fail('invalid_package');
                }
                foreach ($list as $reference) {
                    if (! is_string($reference) || $reference === '') {
                        $this->storage->fail('invalid_package');
                    }
                    $merged[$kind][] = $reference;
                }
            }
            $merged[$kind] = array_values(array_unique($merged[$kind]));
        }

        return $merged;
    }

    public function defaultRequirements(): array
    {
        return [
            'geoflow' => (string) config('geoflow.app_version'),
            'php' => PHP_MAJOR_VERSION.'.*',
            'laravel' => explode('.', app()->version())[0].'.*',
            'contracts' => self::CONTRACTS,
            'views' => [], 'routes' => [], 'assets' => [],
        ];
    }

    public function compatibility(array $requires): void
    {
        foreach (['geoflow' => (string) config('geoflow.app_version'), 'php' => PHP_VERSION, 'laravel' => app()->version()] as $key => $version) {
            $constraint = $requires[$key] ?? null;
            try {
                $compatible = is_string($constraint) && $constraint !== '' && Semver::satisfies(ltrim($version, 'v'), $constraint);
            } catch (Throwable) {
                $compatible = false;
            }
            if (! $compatible) {
                $this->storage->fail('incompatible', ['component' => $key]);
            }
        }
        if (($requires['contracts']['site-theme-view-resolver'] ?? null) !== 1) {
            $this->storage->fail('incompatible', ['component' => 'site-theme-view-resolver']);
        }
        foreach (array_keys($requires['contracts']) as $contract) {
            if ($contract !== 'site-theme-view-resolver') {
                $this->storage->fail('incompatible', ['component' => (string) $contract]);
            }
        }
    }

    /** @return list<string> */
    public function dependencies(array $package, string $root, array $manifest, ?array &$resolved = null): array
    {
        $requires = $package['requires'];
        $declared = $this->manifestRequirements($manifest);
        $this->compatibility(array_replace($requires, array_intersect_key($declared, array_flip(['geoflow', 'php', 'laravel', 'contracts']))));
        $dependencies = ['views' => [], 'routes' => [], 'assets' => []];
        foreach ($dependencies as $kind => $_) {
            foreach ([$requires[$kind] ?? [], $declared[$kind] ?? []] as $list) {
                if (! is_array($list) || ! array_is_list($list)) {
                    $this->storage->fail('invalid_package');
                }
                foreach ($list as $reference) {
                    if (! is_string($reference)) {
                        $this->storage->fail('invalid_package');
                    }
                    $dependencies[$kind][] = $reference;
                }
            }
        }
        foreach ($package['pages']['fallback'] as $page) {
            $dependencies['views'][] = 'site.'.$page;
        }
        $risks = [];
        $paths = array_column($package['files'], 'path');
        $id = $package['theme']['id'];
        foreach ($package['files'] as $file) {
            if (! str_ends_with($file['path'], '.blade.php')) {
                continue;
            }
            $path = $this->storage->path($root.'/'.$file['path']);
            $this->storage->regular($path);
            $source = file_get_contents($path);
            if (! is_string($source)) {
                $this->storage->fail('storage_failed');
            }
            $source = $this->scannableSource($source);
            $pattern = '/(?:\B(?<!@)@(?<directive>include|includeIf|includeOnce|includeWhen|includeUnless|includeFirst|includeIsolated|each|extends|component)|(?<![\w>])(?<helper>view|route|asset))\s*\(\s*/';
            $offset = $calls = 0;
            while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
                if (++$calls > 1000) {
                    $this->storage->fail('invalid_package');
                }
                $offset = $match[0][1] + strlen($match[0][0]);
                $call = $match['directive'][0] ?? $match['helper'][0];
                $kind = match ($call) {
                    'route' => 'routes',
                    'asset' => 'assets',
                    default => 'views',
                };
                $arguments = $this->arguments($source, $offset);
                if ($call === 'includeFirst') {
                    $candidates = isset($arguments[0]) ? $this->literalArray($arguments[0]) : null;
                    if ($candidates !== null) {
                        $available = null;
                        foreach ($candidates as $candidate) {
                            if ($this->dependencyExists('views', $candidate, $id, $paths)) {
                                $available = $candidate;
                                break;
                            }
                        }
                        if ($available === null) {
                            $this->storage->fail('missing_dependency', ['dependency' => 'views: '.implode(' | ', $candidates)]);
                        }

                        continue;
                    }
                    $this->dynamicDependency($requires, $declared, $kind, $file['path'], $risks);

                    continue;
                }
                $indices = in_array($call, ['includeWhen', 'includeUnless'], true) ? [1] : [0];
                if ($call === 'each' && isset($arguments[3])) {
                    $indices[] = 3;
                }
                foreach ($indices as $index) {
                    $literal = isset($arguments[$index]) ? $this->literalString($arguments[$index]) : null;
                    if ($literal === null) {
                        $this->dynamicDependency($requires, $declared, $kind, $file['path'], $risks);
                    } elseif ($call !== 'includeIf' && ! ($call === 'each' && $index === 3 && str_starts_with($literal, 'raw|'))) {
                        $dependencies[$kind][] = $literal;
                    }
                }
            }
            $this->componentDependencies($source, $file['path'], $requires, $declared, $dependencies, $risks, $id, $paths);
        }
        foreach ($dependencies as $kind => $references) {
            foreach (array_unique($references) as $reference) {
                if (! $this->dependencyExists($kind, $reference, $id, $paths)) {
                    $this->storage->fail('missing_dependency', ['dependency' => $kind.': '.$reference]);
                }
            }
        }

        $resolved = $this->mergeRequirements($requires, $declared, $dependencies);

        return array_values(array_unique($risks));
    }

    private function scannableSource(string $source): string
    {
        $source = preg_replace(['/(?<!@)@verbatim.*?@endverbatim/s', '/\{\{--.*?--\}\}/s'], ' ', $source) ?? $source;
        $offset = 0;
        $parts = [];
        while (preg_match('/\B@@\w+(?:::\w+)?[ \t]*/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            if (($source[$end] ?? null) === '(') {
                $expressionEnd = null;
                $this->arguments($source, $end + 1, maximum: PHP_INT_MAX, endOffset: $expressionEnd, maximumBytes: strlen($source));
                $end = $expressionEnd ?? $end;
            }
            $parts[] = substr($source, $offset, $start - $offset).' ';
            $offset = $end;
        }

        return implode('', $parts).substr($source, $offset);
    }

    private function componentDependencies(string $source, string $path, array $requires, array $declared, array &$dependencies, array &$risks, string $id, array $files): void
    {
        $offset = $count = 0;
        $aliases = app('blade.compiler')->getClassComponentAliases();
        while (preg_match('/<\s*x[-:]([A-Za-z0-9_.:-]+)(?=[\s\/>])/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            if (++$count > 1000) {
                $this->storage->fail('invalid_package');
            }
            $offset = $match[0][1] + strlen($match[0][0]);
            $component = $match[1][0];
            if ($component === 'slot' || str_starts_with($component, 'slot:')) {
                continue;
            }
            $classPath = app_path('View/Components/'.implode('/', array_map(Str::studly(...), explode('.', $component))));
            $classFile = $classPath.'.php';
            $nestedClassFile = $classPath.'/'.basename($classPath).'.php';
            if ($component === 'dynamic-component' || str_contains($component, '::') || isset($aliases[$component]) || is_file($classFile) || is_file($nestedClassFile)) {
                $this->dynamicDependency($requires, $declared, 'views', $path, $risks);

                continue;
            }
            $candidates = ['components.'.$component, 'components.'.$component.'.index', 'components.'.$component.'.'.Str::afterLast($component, '.')];
            $found = false;
            foreach ($candidates as $view) {
                if ($this->dependencyExists('views', $view, $id, $files)) {
                    $dependencies['views'][] = $view;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $this->storage->fail('missing_dependency', ['dependency' => 'views: components.'.$component]);
            }
        }
    }

    private function dynamicDependency(array $requires, array $declared, string $kind, string $path, array &$risks): void
    {
        if (empty($requires[$kind]) && empty($declared[$kind])) {
            $this->storage->fail('dynamic_dependency', ['dependency' => $kind, 'path' => $path]);
        }
        $risks[] = $path.': '.$kind;
    }

    /** @return list<string>|null */
    private function arguments(string $source, int $offset, string $closing = ')', int $maximum = 4, ?int &$endOffset = null, int $maximumBytes = 65536): ?array
    {
        $arguments = [];
        $stack = [$closing];
        $start = $offset;
        $quote = null;
        $limit = min(strlen($source), $offset + $maximumBytes);
        for ($index = $offset; $index < $limit; $index++) {
            $character = $source[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;

                continue;
            }
            if ($character === '#' || substr($source, $index, 2) === '//') {
                $lineEnd = strpos($source, "\n", $index);
                if ($lineEnd === false || $lineEnd >= $limit) {
                    return null;
                }
                $index = $lineEnd;

                continue;
            }
            if (substr($source, $index, 2) === '/*') {
                $commentEnd = strpos($source, '*/', $index + 2);
                if ($commentEnd === false || $commentEnd >= $limit) {
                    return null;
                }
                $index = $commentEnd + 1;

                continue;
            }
            if (isset(['(' => ')', '[' => ']', '{' => '}'][$character])) {
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$character];

                continue;
            }
            if (in_array($character, [')', ']', '}'], true)) {
                if (array_pop($stack) !== $character) {
                    return null;
                }
                if ($stack === []) {
                    $endOffset = $index + 1;
                    $last = trim(substr($source, $start, $index - $start));
                    if ($last !== '') {
                        $arguments[] = $last;
                    }

                    return $arguments;
                }
            }
            if ($character === ',' && count($stack) === 1) {
                $arguments[] = trim(substr($source, $start, $index - $start));
                if (count($arguments) >= $maximum) {
                    return $arguments;
                }
                $start = $index + 1;
            }
        }

        return null;
    }

    private function literalString(string $argument): ?string
    {
        $argument = trim($argument);
        if (preg_match('/\A([\'\"])([^\'\"\\\\\r\n]*)\1\z/D', $argument, $match) !== 1 || ($match[1] === '"' && str_contains($match[2], '$'))) {
            return null;
        }

        return $match[2];
    }

    /** @return list<string>|null */
    private function literalArray(string $argument): ?array
    {
        $argument = trim($argument);
        if (! str_starts_with($argument, '[') || ! str_ends_with($argument, ']')) {
            return null;
        }
        $items = $this->arguments($argument, 1, ']', 1000);
        if ($items === null) {
            return null;
        }
        $values = [];
        foreach ($items as $item) {
            $value = $this->literalString($item);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        return $values;
    }

    private function dependencyExists(string $kind, string $reference, string $id, array $files): bool
    {
        if ($kind === 'routes') {
            return Route::has($reference);
        }
        if ($kind === 'views') {
            if (! preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D', $reference)) {
                return false;
            }
            $relative = str_replace('.', '/', $reference).'.blade.php';
            if (str_starts_with($reference, 'theme.'.$id.'.')) {
                return in_array('resources/views/'.$relative, $files, true);
            }
            if (str_starts_with($reference, 'theme.') && count(explode('.', $reference)) < 3) {
                return false;
            }
            $path = resource_path('views/'.$relative);
        } else {
            $this->storage->relative($reference);
            if (str_starts_with($reference, 'themes/'.$id.'/')) {
                return in_array('public/'.$reference, $files, true);
            }
            if (str_starts_with($reference, 'themes/')) {
                $parts = explode('/', $reference);
                if (count($parts) < 3) {
                    return false;
                }
                try {
                    $this->storage->assertDirectory(resource_path('views/theme/'.$this->themeId($parts[1])));
                } catch (RuntimeException) {
                    return false;
                }
            }
            $path = public_path($reference);
        }
        if (! is_file($path)) {
            return false;
        }
        try {
            $this->storage->regular($path);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function directoryEntry(string $name, string $id, array $files): void
    {
        $allowed = ['resources/', 'resources/views/', 'resources/views/theme/', 'resources/views/theme/'.$id.'/', 'public/', 'public/themes/', 'public/themes/'.$id.'/'];
        if (in_array($name, $allowed, true)) {
            return;
        }
        foreach ($files as $file) {
            if (str_starts_with($file, $name)) {
                return;
            }
        }
        $this->storage->fail('invalid_path');
    }

    /** @return array{bytes:int,sha256:string,contents:string} */
    private function readEntry(ZipArchive $zip, array $entry, ?string $target, int $fileLimit, int $remaining): array
    {
        $source = @$zip->getStreamIndex($entry['index'], ZipArchive::FL_UNCHANGED);
        if ($source === false) {
            $this->storage->fail('invalid_archive');
        }
        $output = null;
        try {
            if ($target !== null) {
                $output = @fopen($target, 'xb');
                if ($output === false) {
                    $this->storage->fail('storage_failed');
                }
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $contents = '';
            while (! feof($source)) {
                $chunk = @fread($source, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    $this->storage->fail('invalid_archive');
                }
                $bytes += strlen($chunk);
                if ($bytes > $fileLimit) {
                    $this->storage->fail('file_too_large');
                }
                if ($bytes > $remaining) {
                    $this->storage->fail('total_too_large');
                }
                hash_update($hash, $chunk);
                if ($output !== null) {
                    $this->storage->write($output, $chunk);
                } else {
                    $contents .= $chunk;
                }
            }
            if ($bytes !== $entry['stat']['size']) {
                $this->storage->fail('manifest_mismatch');
            }
            if ($output !== null && ! fflush($output)) {
                $this->storage->fail('storage_failed');
            }

            return ['bytes' => $bytes, 'sha256' => hash_final($hash), 'contents' => $contents];
        } finally {
            fclose($source);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function hashValue(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) === 1;
    }
}
