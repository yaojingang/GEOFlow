<?php

namespace App\Services\GeoFlow;

use Closure;
use League\CommonMark\Environment\EnvironmentAwareInterface;
use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Parser\Inline\CloseBracketParser;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use League\CommonMark\Parser\MarkdownParser;
use RuntimeException;

/** Resolve image syntax with the same parser as ArticleHtmlPresenter, preserving source prose. */
final class MarkdownImagePolicy
{
    /** @param Closure(string):bool $accept */
    public function filter(string $markdown, Closure $accept): string
    {
        $spans = [];
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment = $converter->getEnvironment();
        $capture = static function (array $span) use (&$spans): void {
            $spans[] = $span;
        };
        $environment->addInlineParser(new class($capture) implements EnvironmentAwareInterface, InlineParserInterface
        {
            private CloseBracketParser $parser;

            public function __construct(private Closure $capture)
            {
                $this->parser = new CloseBracketParser;
            }

            public function setEnvironment(EnvironmentInterface $environment): void
            {
                $this->parser->setEnvironment($environment);
            }

            public function getMatchDefinition(): InlineParserMatch
            {
                return InlineParserMatch::string(']');
            }

            public function parse(InlineParserContext $context): bool
            {
                $opener = $context->getDelimiterStack()->getLastBracket();
                $image = $opener?->isImage() ?? false;
                $start = $image ? $opener->getPosition() - 2 : 0;
                $node = $opener?->getNode();
                $previous = $node?->previous();
                $parsed = $this->parser->parse($context);
                if ($parsed && $image) {
                    $replacement = $previous ? $previous->next() : $context->getContainer()->firstChild();
                    $syntax = $context->getCursor()->getSubstring($start, $context->getCursor()->getPosition() - $start);
                    ($this->capture)([
                        'syntax' => $syntax, 'url' => $replacement->getUrl(),
                        'line' => $context->getContainer()->getStartLine(), 'end_line' => $context->getContainer()->getEndLine(),
                        'occurrence' => substr_count($context->getCursor()->getSubstring(0, $start), $syntax),
                    ]);
                }

                return $parsed;
            }
        }, 100);
        (new MarkdownParser($environment))->parse($markdown);
        $lines = preg_split('/(?<=\n)/', $markdown);
        $offsets = [0];
        foreach ($lines as $line) {
            $offsets[] = end($offsets) + strlen($line);
        }
        $edits = [];
        foreach ($spans as $span) {
            if ($accept($span['url'])) {
                continue;
            }
            $start = $offsets[max(0, (int) $span['line'] - 1)] ?? 0;
            $end = $offsets[(int) $span['end_line']] ?? strlen($markdown);
            $position = $start;
            for ($index = 0; $index <= $span['occurrence']; $index++) {
                $position = strpos($markdown, $span['syntax'], $position);
                if ($position === false || $position >= $end) {
                    // Block quotes/lists may add a container prefix to continued source lines.
                    // The CommonMark parser has already decided that this span is an image.
                    $pattern = '~'.str_replace("\n", '\\r?\\n[ \t]*(?:>[ \t]*)*', preg_quote($span['syntax'], '~')).'~u';
                    preg_match_all($pattern, substr($markdown, $start, $end - $start), $matches, PREG_OFFSET_CAPTURE);
                    $match = $matches[0][$span['occurrence']] ?? null;
                    if ($match === null) {
                        throw new RuntimeException('generated_image_source_unlocatable');
                    }
                    $position = $start + $match[1];
                    $span['syntax'] = $match[0];
                    break;
                }
                if ($index < $span['occurrence']) {
                    $position += strlen($span['syntax']);
                }
            }
            $edits[$position] = strlen($span['syntax']);
        }
        krsort($edits);
        foreach ($edits as $position => $length) {
            $markdown = substr_replace($markdown, '', $position, $length);
        }

        return $markdown;
    }
}
