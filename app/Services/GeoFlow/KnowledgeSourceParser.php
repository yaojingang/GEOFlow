<?php

namespace App\Services\GeoFlow;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class KnowledgeSourceParser
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    private const MAX_DOCX_XML_BYTES = 16 * 1024 * 1024;

    private const MAX_DOCX_COMPRESSION_RATIO = 100;

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

        return in_array($fileType, ['markdown', 'word', 'text'], true) ? $fileType : 'markdown';
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

    private function assertContentSize(string $content): void
    {
        if (strlen($content) > self::MAX_KNOWLEDGE_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
        }
    }
}
