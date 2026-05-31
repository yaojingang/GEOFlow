<?php

namespace App\Services\Geo;

use App\Models\GeoCitationPageSnapshot;
use App\Models\GeoCitationSource;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GeoReferencePageCrawler
{
    public function crawl(GeoCitationSource|string $source): GeoCitationPageSnapshot
    {
        $citationSource = $source instanceof GeoCitationSource ? $source : null;
        $url = $citationSource?->url ?? $source;
        $domain = $this->domainFromUrl($url);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'GEOAmplifyReferenceCrawler/1.0'])
                ->get($url);

            $status = $response->status();
            $body = (string) $response->body();

            if (! $response->successful()) {
                return $this->storeFailure($citationSource, $url, $domain, $status, 'HTTP request failed with status '.$status);
            }

            $parsed = $this->parseHtml($body);
            $contentHash = hash('sha256', $parsed['content_text'] ?: $body);

            return GeoCitationPageSnapshot::query()->create([
                'geo_citation_source_id' => $citationSource?->id,
                'url' => $url,
                'domain' => $domain,
                'title' => $parsed['title'],
                'description' => $parsed['description'],
                'content_summary' => Str::limit($parsed['content_text'], 500, ''),
                'content_text' => $parsed['content_text'],
                'http_status' => $status,
                'crawl_status' => 'succeeded',
                'error_message' => null,
                'content_hash' => $contentHash,
                'crawled_at' => now(),
                'metadata' => [
                    'content_length' => strlen($body),
                    'text_length' => mb_strlen($parsed['content_text']),
                ],
            ]);
        } catch (RequestException $exception) {
            return $this->storeFailure($citationSource, $url, $domain, $exception->response?->status(), $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->storeFailure($citationSource, $url, $domain, null, $exception->getMessage());
        }
    }

    /**
     * @return array{title: string, description: string|null, content_text: string}
     */
    private function parseHtml(string $html): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//style|//noscript|//svg') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $description = $this->metaContent($xpath, 'description');
        $main = $xpath->query('//article|//main')->item(0);
        $textNode = $main ?: $document->documentElement;
        $text = $this->normalizeText((string) ($textNode?->textContent ?? ''));

        if ($title === '') {
            $title = trim((string) ($xpath->query('//h1')->item(0)?->textContent ?? ''));
        }

        return [
            'title' => Str::limit($this->normalizeText($title), 500, ''),
            'description' => $description === null ? null : Str::limit($this->normalizeText($description), 1000, ''),
            'content_text' => $text,
        ];
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $name);
        $value = $xpath->query($query)->item(0)?->nodeValue;

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function storeFailure(?GeoCitationSource $source, string $url, string $domain, ?int $status, string $message): GeoCitationPageSnapshot
    {
        return GeoCitationPageSnapshot::query()->create([
            'geo_citation_source_id' => $source?->id,
            'url' => $url,
            'domain' => $domain,
            'title' => '',
            'description' => null,
            'content_summary' => null,
            'content_text' => null,
            'http_status' => $status,
            'crawl_status' => 'failed',
            'error_message' => Str::limit($message, 2000, ''),
            'content_hash' => '',
            'crawled_at' => now(),
        ]);
    }

    private function domainFromUrl(string $url): string
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
