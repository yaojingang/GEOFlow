<?php

namespace App\Services\GeoFlow;

use Closure;
use League\CommonMark\Environment\EnvironmentAwareInterface;
use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Parser\Inline\CloseBracketParser;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableSection;
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
                        'container' => $context->getContainer(),
                        'prefix' => $context->getCursor()->getSubstring(0, $start),
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
            if ($span['container'] instanceof TableCell) {
                [$position, $length] = $this->tableSpan($span, $lines, $offsets);
                $edits[$position] = $length;

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

    /** TableParser attaches cells after inline parsing and removes escaped pipe backslashes. */
    private function tableSpan(array $span, array $lines, array $offsets): array
    {
        $cell = $span['container'];
        $row = $cell->parent();
        $section = $row?->parent();
        $table = $section?->parent();
        $rowIndex = 0;
        for ($previous = $row?->previous(); $previous !== null; $previous = $previous->previous()) {
            $rowIndex++;
        }
        $column = 0;
        for ($previous = $cell->previous(); $previous !== null; $previous = $previous->previous()) {
            $column++;
        }
        $lineIndex = (int) $table?->getStartLine() - 1 + $rowIndex
            + ($section?->getType() === TableSection::TYPE_HEAD ? 0 : 2);
        $raw = rtrim($lines[$lineIndex] ?? '', "\r\n");
        // Remove the enclosing block's source prefix before locating table delimiters.
        preg_match('/^[ \t]*(?:>[ \t]*|(?:[-+*]|[0-9]+[.)])[ \t]+)*/u', $raw, $prefix);
        $start = strlen($prefix[0]);
        $length = strlen($raw);
        if (($raw[$start] ?? '') === '|') {
            $start++;
        }
        $cells = [];
        $decoded = '';
        $mapping = [];
        for ($i = $start; $i < $length; $i++) {
            if ($raw[$i] === '|') {
                $mapping[] = $i;
                $cells[] = [$decoded, $mapping];
                $decoded = '';
                $mapping = [];
            } else {
                if ($raw[$i] === '\\' && ($raw[$i + 1] ?? '') === '|') {
                    $mapping[] = $i;
                    $decoded .= '|';
                    $i++;
                } else {
                    $mapping[] = $i;
                    $decoded .= $raw[$i];
                }
            }
        }
        $mapping[] = $length;
        $cells[] = [$decoded, $mapping];
        [$decoded, $mapping] = $cells[$column] ?? ['', []];
        $relative = strlen($decoded) - strlen(ltrim($decoded)) + strlen($span['prefix']);
        $begin = $mapping[$relative] ?? null;
        $end = $mapping[$relative + strlen($span['syntax'])] ?? null;
        if ($begin === null || $end === null || substr(trim($decoded), strlen($span['prefix']), strlen($span['syntax'])) !== $span['syntax']) {
            throw new RuntimeException('generated_image_source_unlocatable');
        }

        return [$offsets[$lineIndex] + $begin, $end - $begin];
    }
}
