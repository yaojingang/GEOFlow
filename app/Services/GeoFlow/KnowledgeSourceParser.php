<?php

namespace App\Services\GeoFlow;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class KnowledgeSourceParser
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    private const MAX_DOCX_XML_BYTES = 16 * 1024 * 1024;

    private const MAX_DOCX_COMPRESSION_RATIO = 100;

    private const MAX_PPTX_ENTRY_BYTES = 16 * 1024 * 1024;

    private const MAX_PPTX_TOTAL_BYTES = 32 * 1024 * 1024;

    private const MAX_PPTX_ENTRIES = 2048;

    private const MAX_PPTX_COMPRESSION_RATIO = 100;

    private const MAX_PPTX_RELATIONSHIPS_PER_PART = 2048;

    private const MAX_PPTX_RELATIONSHIPS = 16384;

    private const MAX_PPTX_TEXT_NODES = 100000;

    private const MAX_PPTX_ATTRIBUTE_BYTES = 1024;

    private const MAX_PPTX_ID_BYTES = 255;

    private const MAX_PPTX_PATH_SEGMENTS = 64;

    private const PRESENTATION_NAMESPACES = ['http://schemas.openxmlformats.org/presentationml/2006/main', 'http://purl.oclc.org/ooxml/presentationml/main'];

    private const DRAWING_NAMESPACES = ['http://schemas.openxmlformats.org/drawingml/2006/main', 'http://purl.oclc.org/ooxml/drawingml/main'];

    private const DOCUMENT_RELATIONSHIP_NAMESPACES = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'http://purl.oclc.org/ooxml/officeDocument/relationships'];

    public function storeUploadedKnowledgeFile(UploadedFile $file, string $relativeDirectory = 'uploads/knowledge'): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'txt');
        $filename = uniqid('', true).'.'.$extension;
        $relativePath = Storage::disk('local')->putFileAs($relativeDirectory, $file, $filename);
        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
        }

        return $relativePath;
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function uploadedKnowledgeFiles(Request $request): array
    {
        return $this->uploadedFilesFromFields($request, ['knowledge_file', 'knowledge_files']);
    }

    /**
     * @param  list<string>  $fieldNames
     * @return array<int, UploadedFile>
     */
    public function uploadedFilesFromFields(Request $request, array $fieldNames): array
    {
        $files = [];

        foreach ($fieldNames as $fieldName) {
            $uploaded = $request->file($fieldName, []);
            if ($uploaded instanceof UploadedFile) {
                $files[] = $uploaded;

                continue;
            }

            if (is_array($uploaded)) {
                foreach ($uploaded as $file) {
                    if ($file instanceof UploadedFile) {
                        $files[] = $file;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     * @param  array<int, string>  $storedPaths
     * @return array<int, array{content:string,file_type:string,original_name:string,file_path:string}>
     */
    public function parseUploadedKnowledgeFiles(array $uploadedFiles, array &$storedPaths, string $relativeDirectory = 'uploads/knowledge'): array
    {
        $totalUploadBytes = 0;
        foreach ($uploadedFiles as $uploadedFile) {
            $fileBytes = max(0, (int) $uploadedFile->getSize());
            $totalUploadBytes += $fileBytes;
            if ($fileBytes > self::MAX_KNOWLEDGE_BYTES || $totalUploadBytes > self::MAX_KNOWLEDGE_BYTES) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.total_files_too_large'));
            }
        }

        $parsedFiles = [];
        $parsedBytes = 0;

        foreach ($uploadedFiles as $uploadedFile) {
            $storedRelativePath = $this->storeUploadedKnowledgeFile($uploadedFile, $relativeDirectory);
            $storedPaths[] = $storedRelativePath;
            $parsed = $this->parseUploadedKnowledgeFile(
                Storage::disk('local')->path($storedRelativePath),
                $uploadedFile->getClientOriginalName()
            );

            $parsedFiles[] = [
                'content' => $parsed['content'],
                'file_type' => $parsed['file_type'],
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
                'file_path' => $storedRelativePath,
            ];
            $parsedBytes += strlen((string) $parsed['content']);
            if ($parsedBytes > self::MAX_KNOWLEDGE_BYTES) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
            }
        }

        return $parsedFiles;
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    public function mergeKnowledgeSources(string $manualContent, array $parsedFiles): string
    {
        $this->assertContentSize($manualContent);

        if ($manualContent !== '' && $parsedFiles === []) {
            return $manualContent;
        }

        $blocks = [];
        if ($manualContent !== '') {
            $blocks[] = "# 手动输入内容\n\n".$manualContent;
        }

        foreach ($parsedFiles as $parsedFile) {
            $fileName = trim((string) $parsedFile['original_name']);
            $blocks[] = '# 文件：'.$fileName."\n\n".trim((string) $parsedFile['content']);
        }

        $merged = $this->normalizeKnowledgeText(implode("\n\n---\n\n", $blocks));
        $this->assertContentSize($merged);

        return $merged;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedFiles
     */
    public function inferKnowledgeName(array $uploadedFiles): string
    {
        if ($uploadedFiles === []) {
            return '';
        }

        $firstName = pathinfo((string) $uploadedFiles[0]->getClientOriginalName(), PATHINFO_FILENAME);
        $firstName = trim($firstName);
        if (count($uploadedFiles) === 1) {
            return $firstName;
        }

        return $firstName === ''
            ? __('admin.knowledge_bases.imported_multi_file_name', ['count' => count($uploadedFiles)])
            : __('admin.knowledge_bases.imported_multi_file_name_with_first', [
                'name' => $firstName,
                'count' => count($uploadedFiles),
            ]);
    }

    public function inferKnowledgeNameFromContent(string $content): string
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        foreach ($lines as $line) {
            $candidate = trim((string) $line);
            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/^#{1,6}\s*/u', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/^[-*+]\s+/u', '', $candidate) ?? $candidate;
            $candidate = trim(strip_tags($candidate));
            $candidate = trim($candidate, " \t\n\r\0\x0B#*_`>");

            if ($candidate !== '') {
                return mb_substr($candidate, 0, 60, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{content:string,file_type:string,original_name:string}>  $parsedFiles
     */
    public function resolveKnowledgeFileType(string $requestedType, string $manualContent, array $parsedFiles): string
    {
        if ($parsedFiles === []) {
            return in_array($requestedType, ['markdown', 'word', 'text'], true) ? $requestedType : 'markdown';
        }

        if ($manualContent !== '' || count($parsedFiles) > 1) {
            return 'markdown';
        }

        $fileType = (string) ($parsedFiles[0]['file_type'] ?? 'markdown');

        return in_array($fileType, ['markdown', 'word', 'text', 'pdf', 'presentation'], true) ? $fileType : 'markdown';
    }

    /**
     * @param  array<int, string>  $storedPaths
     */
    public function encodeKnowledgeFilePaths(array $storedPaths): string
    {
        if ($storedPaths === []) {
            return '';
        }

        if (count($storedPaths) === 1) {
            return (string) $storedPaths[0];
        }

        return (string) json_encode(array_values($storedPaths), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{content:string,file_type:string}
     */
    public function parseUploadedKnowledgeFile(string $absolutePath, string $originalName): array
    {
        if (@filesize($absolutePath) > self::MAX_KNOWLEDGE_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.file_too_large'));
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, ['txt', 'md', 'markdown'], true)) {
            if (@filesize($absolutePath) > self::MAX_KNOWLEDGE_BYTES) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_too_large'));
            }

            $raw = @file_get_contents($absolutePath);
            if ($raw === false) {
                throw new \RuntimeException(__('admin.knowledge_bases.message.upload_failed'));
            }

            $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($raw));
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.content_required'));
            }

            return [
                'content' => $content,
                'file_type' => in_array($extension, ['md', 'markdown'], true) ? 'markdown' : 'text',
            ];
        }

        if ($extension === 'docx') {
            $content = $this->extractDocxContent($absolutePath);
            if ($content === '') {
                throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
            }

            return [
                'content' => $content,
                'file_type' => 'word',
            ];
        }

        if ($extension === 'pdf') {
            return ['content' => $this->extractPdfContent($absolutePath), 'file_type' => 'pdf'];
        }

        if ($extension === 'pptx') {
            return ['content' => $this->extractPptxContent($absolutePath), 'file_type' => 'presentation'];
        }

        throw new \RuntimeException(__('admin.knowledge_bases.error.file_type_invalid'));
    }

    /**
     * @return array<int, string>
     */
    public function decodeKnowledgeFilePaths(string $storedValue): array
    {
        $storedValue = trim($storedValue);
        if ($storedValue === '') {
            return [];
        }

        $decoded = json_decode($storedValue, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($path): bool => is_string($path) && trim($path) !== ''));
        }

        return [$storedValue];
    }

    /**
     * @param  array<int, string>  $relativePaths
     */
    public function cleanupKnowledgeFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $this->deleteKnowledgeFilePath($relativePath);
        }
    }

    public function deleteKnowledgeFilePath(string $relativePath): void
    {
        $relativePath = $this->normalizeDeletableKnowledgePath($relativePath);
        if ($relativePath === null) {
            return;
        }

        if (Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    private function normalizeDeletableKnowledgePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || preg_match('/^[A-Za-z]:\//', $relativePath) === 1
            || str_contains('/'.$relativePath.'/', '/../')
        ) {
            return null;
        }

        foreach (['knowledge-bases/', 'uploads/knowledge/', 'uploads/enterprise-knowledge/'] as $allowedPrefix) {
            if (str_starts_with($relativePath, $allowedPrefix)) {
                return $relativePath;
            }
        }

        return null;
    }

    public function convertUploadedTextToUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'BIG5', 'UTF-16LE', 'UTF-16BE'], true);
        if (! $detectedEncoding || strtoupper($detectedEncoding) === 'UTF-8') {
            return $text;
        }

        $converted = @mb_convert_encoding($text, 'UTF-8', $detectedEncoding);

        return $converted === false ? $text : $converted;
    }

    public function normalizeKnowledgeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/u', ' ', (string) $text);

        return trim((string) $text);
    }

    public function extractDocxContent(string $absolutePath): string
    {
        if (! class_exists('ZipArchive') || ! class_exists('XMLReader')) {
            return '';
        }

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }

        $stat = $zip->statName('word/document.xml');
        if (! is_array($stat)) {
            $zip->close();

            return '';
        }

        $uncompressedSize = max(0, (int) ($stat['size'] ?? 0));
        $compressedSize = max(1, (int) ($stat['comp_size'] ?? 0));
        if (
            $uncompressedSize > self::MAX_DOCX_XML_BYTES
            || ($uncompressedSize / $compressedSize) > self::MAX_DOCX_COMPRESSION_RATIO
        ) {
            $zip->close();
            throw new \RuntimeException(__('admin.knowledge_bases.error.docx_expansion_too_large'));
        }

        $source = $zip->getStream('word/document.xml');
        $temporary = tmpfile();
        if (! is_resource($source) || ! is_resource($temporary)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temporary)) {
                fclose($temporary);
            }
            $zip->close();

            return '';
        }

        $copiedBytes = stream_copy_to_stream($source, $temporary, self::MAX_DOCX_XML_BYTES + 1);
        fclose($source);
        $zip->close();
        if (! is_int($copiedBytes) || $copiedBytes > self::MAX_DOCX_XML_BYTES) {
            fclose($temporary);
            throw new \RuntimeException(__('admin.knowledge_bases.error.docx_expansion_too_large'));
        }

        $metadata = stream_get_meta_data($temporary);
        $temporaryPath = (string) ($metadata['uri'] ?? '');
        $reader = new \XMLReader;
        if ($temporaryPath === '' || ! @$reader->open($temporaryPath, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            fclose($temporary);

            return '';
        }

        $wordNamespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $textOutput = tmpfile();
        if (! is_resource($textOutput)) {
            $reader->close();
            fclose($temporary);

            return '';
        }
        $contentBytes = 0;
        while ($reader->read()) {
            if (
                $reader->nodeType === \XMLReader::ELEMENT
                && $reader->localName === 't'
                && $reader->namespaceURI === $wordNamespace
            ) {
                $value = trim($reader->readString());
                if ($value !== '') {
                    $contentBytes += strlen($value) + 1;
                    if ($contentBytes > self::MAX_KNOWLEDGE_BYTES) {
                        $reader->close();
                        fclose($temporary);
                        fclose($textOutput);
                        throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
                    }
                    fwrite($textOutput, $value."\n");
                }
            }
        }
        $reader->close();
        fclose($temporary);
        rewind($textOutput);
        $content = stream_get_contents($textOutput, self::MAX_KNOWLEDGE_BYTES + 1);
        fclose($textOutput);

        return is_string($content) ? $this->normalizeKnowledgeText($content) : '';
    }

    public function extractPdfContent(string $absolutePath): string
    {
        if (@filesize($absolutePath) > self::MAX_KNOWLEDGE_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.file_too_large'));
        }

        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false || ! function_exists('proc_open')) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pdf_parser_unavailable'));
        }

        $process = new Process([
            $php, '-d', 'memory_limit=128M', '-d', 'max_execution_time=10',
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'allow_url_fopen=0',
            base_path('scripts/extract-knowledge-pdf.php'), $absolutePath,
        ], base_path(), null, null, 10);
        $process->disableOutput();
        $output = '';

        try {
            $process->run(function (string $type, string $chunk) use (&$output): void {
                if ($type !== Process::OUT) {
                    return;
                }

                if (strlen($output) + strlen($chunk) > self::MAX_KNOWLEDGE_BYTES) {
                    throw new \LengthException('PDF output budget exceeded.');
                }
                $output .= $chunk;
            });
        } catch (ProcessTimedOutException|\LengthException $exception) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pdf_resource_limit'), previous: $exception);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pdf_parser_unavailable'), previous: $exception);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        if (! $process->isSuccessful()) {
            $key = match ($process->getExitCode()) {
                2 => 'pdf_invalid',
                4 => 'pdf_no_text',
                default => 'pdf_resource_limit',
            };
            throw new \RuntimeException(__('admin.knowledge_bases.error.'.$key));
        }

        $content = $this->normalizeKnowledgeText($this->convertUploadedTextToUtf8($output));
        if ($content === '') {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pdf_no_text'));
        }

        return $content;
    }

    public function extractPptxContent(string $absolutePath): string
    {
        if (@filesize($absolutePath) > self::MAX_KNOWLEDGE_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.file_too_large'));
        }

        $zip = new \ZipArchive;
        if (@$zip->open($absolutePath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
        }

        try {
            $entries = $this->validatePptxArchive($zip);
            $relationships = [];
            $totalRelationships = 0;
            foreach ($entries as $name => $entry) {
                if (str_ends_with($name, '.rels')) {
                    $relationships[$name] = $this->pptxRelationships($zip, $name, $entries, $totalRelationships);
                }
            }

            $slideIds = [];
            $seenSlideIds = [];
            $listDepth = null;
            $this->readPptxXml($zip, 'ppt/presentation.xml', function (\XMLReader $reader) use (&$slideIds, &$seenSlideIds, &$listDepth): void {
                if (! in_array($reader->namespaceURI, self::PRESENTATION_NAMESPACES, true)) {
                    return;
                }
                if ($reader->localName === 'sldIdLst') {
                    $listDepth = $reader->nodeType === \XMLReader::ELEMENT ? $reader->depth : null;
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'sldId' || $listDepth === null || $reader->depth !== $listDepth + 1) {
                    return;
                }
                if (count($slideIds) >= self::MAX_PPTX_ENTRIES) {
                    throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
                }

                $id = null;
                foreach (self::DOCUMENT_RELATIONSHIP_NAMESPACES as $namespace) {
                    $id ??= $reader->getAttributeNs('id', $namespace);
                }
                if (! is_string($id) || $id === '') {
                    throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                }
                $this->assertPptxAttributeBudget($id, self::MAX_PPTX_ID_BYTES);
                if (isset($seenSlideIds[$id])) {
                    throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                }
                $seenSlideIds[$id] = true;
                $slideIds[] = $id;
            });

            if ($slideIds === []) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_no_text'));
            }

            $slideRelationships = $relationships['ppt/_rels/presentation.xml.rels'] ?? [];
            $blocks = [];
            $contentBytes = 0;
            $textNodes = 0;
            $visitedSlides = [];
            foreach ($slideIds as $index => $id) {
                $relationship = $slideRelationships[$id] ?? null;
                if (! is_array($relationship)
                    || ! in_array($relationship['type'], array_map(fn (string $namespace): string => $namespace.'/slide', self::DOCUMENT_RELATIONSHIP_NAMESPACES), true)
                    || ! str_starts_with($relationship['target'], 'ppt/slides/')
                    || ! str_ends_with($relationship['target'], '.xml')
                    || isset($visitedSlides[$relationship['target']])) {
                    throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                }
                $visitedSlides[$relationship['target']] = true;

                $lines = [];
                $this->readPptxXml($zip, $relationship['target'], function (\XMLReader $reader) use (&$lines, &$contentBytes, &$textNodes): void {
                    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 't' || ! in_array($reader->namespaceURI, self::DRAWING_NAMESPACES, true)) {
                        return;
                    }
                    if (++$textNodes > self::MAX_PPTX_TEXT_NODES) {
                        throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
                    }
                    $value = trim($reader->readString());
                    if ($value === '') {
                        return;
                    }

                    $contentBytes += strlen($value) + 1;
                    if ($contentBytes > self::MAX_KNOWLEDGE_BYTES) {
                        throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
                    }
                    $lines[] = $value;
                });
                if ($lines !== []) {
                    $heading = '# Slide '.($index + 1)."\n\n";
                    $contentBytes += strlen($heading) + 2;
                    $blocks[] = $heading.implode("\n", $lines);
                }
            }

            if ($blocks === []) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_no_text'));
            }

            $content = $this->normalizeKnowledgeText(implode("\n\n", $blocks));
            $this->assertContentSize($content);

            return $content;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, true> */
    private function validatePptxArchive(\ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_PPTX_ENTRIES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        }

        $totalBytes = 0;
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? ($stat['name'] ?? '') : '';
            if (! is_string($name) || $name === '' || str_starts_with($name, '/') || str_contains($name, '//')
                || preg_match('~[\\\\\x00-\x1f:]|(?:^|/)\.{1,2}(?:/|$)~', $name)
                || isset($entries[$name]) || ! is_int($stat['size'] ?? null) || $stat['size'] < 0
                || ($stat['encryption_method'] ?? \ZipArchive::EM_NONE) !== \ZipArchive::EM_NONE) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
            }
            $this->assertPptxPathBudget($name);

            $bytes = max(0, (int) ($stat['size'] ?? 0));
            $totalBytes += $bytes;
            if ($bytes > self::MAX_PPTX_ENTRY_BYTES || $totalBytes > self::MAX_PPTX_TOTAL_BYTES
                || $bytes / max(1, (int) ($stat['comp_size'] ?? 0)) > self::MAX_PPTX_COMPRESSION_RATIO) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
            }
            $entries[$name] = true;
        }

        return $entries;
    }

    /**
     * @param  array<string, true>  $entries
     * @return array<string, array{target:string,type:string}>
     */
    private function pptxRelationships(\ZipArchive $zip, string $name, array $entries, int &$totalRelationships): array
    {
        if (! preg_match('~^(?:(.*)/)?_rels/[^/]*\.rels$~', $name, $matches)) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
        }
        $base = ($matches[1] ?? '') === '' ? '' : $matches[1].'/';
        $relationships = [];
        $partRelationships = 0;
        $this->readPptxXml($zip, $name, function (\XMLReader $reader) use (&$relationships, &$partRelationships, &$totalRelationships, $base, $entries): void {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship'
                || $reader->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships') {
                return;
            }
            $partRelationships++;
            $totalRelationships++;
            if ($partRelationships > self::MAX_PPTX_RELATIONSHIPS_PER_PART || $totalRelationships > self::MAX_PPTX_RELATIONSHIPS) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
            }

            $id = $reader->getAttribute('Id') ?? '';
            $target = $reader->getAttribute('Target') ?? '';
            $mode = $reader->getAttribute('TargetMode') ?? 'Internal';
            $type = $reader->getAttribute('Type') ?? '';
            $this->assertPptxAttributeBudget($id, self::MAX_PPTX_ID_BYTES);
            $this->assertPptxAttributeBudget($mode);
            $this->assertPptxAttributeBudget($type);
            $this->assertPptxPathBudget($target);
            if ($id === '' || isset($relationships[$id]) || $mode !== 'Internal'
                || $target === '' || str_starts_with($target, '//') || preg_match('~[\\\\\x00-\x20:%?#]~', $target)) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
            }

            $relativeTarget = str_starts_with($target, '/') ? substr($target, 1) : $base.$target;
            $this->assertPptxPathBudget($relativeTarget);
            $segments = [];
            foreach (explode('/', $relativeTarget) as $segment) {
                if ($segment === '..') {
                    if ($segments === []) {
                        throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                    }
                    array_pop($segments);
                } elseif ($segment !== '' && $segment !== '.') {
                    $segments[] = $segment;
                }
            }
            $resolved = implode('/', $segments);
            if (! isset($entries[$resolved])) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
            }
            $relationships[$id] = ['target' => $resolved, 'type' => $type];
        });

        return $relationships;
    }

    private function assertPptxAttributeBudget(string $value, int $maxBytes = self::MAX_PPTX_ATTRIBUTE_BYTES): void
    {
        if (strlen($value) > $maxBytes) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        }
    }

    private function assertPptxPathBudget(string $path): void
    {
        $this->assertPptxAttributeBudget($path);
        if (substr_count($path, '/') >= self::MAX_PPTX_PATH_SEGMENTS) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        }
    }

    private function readPptxXml(\ZipArchive $zip, string $name, callable $visit): void
    {
        $source = @$zip->getStream($name);
        if (! is_resource($source)) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
        }
        try {
            $xml = stream_get_contents($source, self::MAX_PPTX_ENTRY_BYTES + 1);
        } finally {
            fclose($source);
        }
        if (! is_string($xml) || strlen($xml) > self::MAX_PPTX_ENTRY_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_expansion_too_large'));
        }
        $stat = $zip->statName($name);
        if (strlen($xml) !== ($stat['size'] ?? null) || preg_match('/<!DOCTYPE/i', str_replace("\0", '', $xml))) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
        }

        $previousErrors = libxml_use_internal_errors(true);
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static fn (): null => null);
        libxml_clear_errors();
        $reader = new \XMLReader;
        $hasRoot = false;

        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
            }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY_REF], true)) {
                    throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 0) {
                    [$root, $namespaces] = match (true) {
                        str_ends_with($name, '.rels') => ['Relationships', ['http://schemas.openxmlformats.org/package/2006/relationships']],
                        $name === 'ppt/presentation.xml' => ['presentation', self::PRESENTATION_NAMESPACES],
                        default => ['sld', self::PRESENTATION_NAMESPACES],
                    };
                    if ($reader->localName !== $root || ! in_array($reader->namespaceURI, $namespaces, true)) {
                        throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
                    }
                    $hasRoot = true;
                }
                $visit($reader);
            }
            if (! $hasRoot || libxml_get_errors() !== []) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.pptx_invalid'));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_set_external_entity_loader($previousLoader);
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function assertContentSize(string $content): void
    {
        if (strlen($content) > self::MAX_KNOWLEDGE_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
        }
    }
}
