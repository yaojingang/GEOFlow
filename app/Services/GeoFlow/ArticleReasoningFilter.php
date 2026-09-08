<?php

namespace App\Services\GeoFlow;

final class ArticleReasoningFilter
{
    private string $buffer = '';

    private bool $insideReasoning = false;

    private bool $bodyStarted = false;

    private bool $removedReasoning = false;

    public static function clean(string $content): string
    {
        $filter = new self;

        return $filter->push($content).$filter->finish();
    }

    public function push(string $delta): string
    {
        if ($this->bodyStarted) {
            return $delta;
        }

        $this->buffer .= $delta;

        while (true) {
            if ($this->insideReasoning) {
                $end = stripos($this->buffer, '</think>');
                if ($end === false) {
                    $this->buffer = substr($this->buffer, -7);

                    return '';
                }

                $this->buffer = ltrim(substr($this->buffer, $end + 8));
                $this->insideReasoning = false;
            }

            if ($this->removedReasoning) {
                $this->buffer = ltrim($this->buffer);
            }

            $prefix = ltrim($this->buffer);
            if ($prefix === '' || str_starts_with('<think', strtolower($prefix))) {
                return '';
            }

            if (preg_match('/\A<think(?:\s[^>]*)?>/i', $prefix, $match) === 1) {
                $this->buffer = substr($prefix, strlen($match[0]));
                $this->insideReasoning = true;
                $this->removedReasoning = true;

                continue;
            }

            if (preg_match('/\A<think\s[^>]*\z/i', $prefix) === 1) {
                return '';
            }

            $this->bodyStarted = true;
            $content = $this->buffer;
            $this->buffer = '';

            return $content;
        }
    }

    public function finish(): string
    {
        $content = $this->buffer;
        $this->buffer = '';

        if ($this->insideReasoning || preg_match('/\A\s*<think(?:\s[^>]*)?\z/i', $content) === 1) {
            return '';
        }

        return $content;
    }
}
