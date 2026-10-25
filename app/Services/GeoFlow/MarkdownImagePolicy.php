<?php

namespace App\Services\GeoFlow;

use App\Support\Site\ArticleHtmlPresenter;
use Closure;
use League\CommonMark\Environment\EnvironmentAwareInterface;
use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Parser\Inline\CloseBracketParser;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\Cursor;
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
        $markdown = ArticleHtmlPresenter::prepareMarkdown($markdown);
        $spans = [];
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment = $converter->getEnvironment();
        $capture = static function (array $span) use (&$spans): void {
            $spans[$span['node']] = $span;
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
                        'syntax' => $syntax, 'url' => $replacement->getUrl(), 'node' => spl_object_id($replacement),
                        'container' => $context->getContainer(),
                        'prefix' => $context->getCursor()->getSubstring(0, $start),
                        'line' => $context->getContainer()->getStartLine(), 'end_line' => $context->getContainer()->getEndLine(),
                        'occurrence' => substr_count($context->getCursor()->getSubstring(0, $start), $syntax),
                    ]);
                }

                return $parsed;
            }
        }, 100);
        $document = (new MarkdownParser($environment))->parse($markdown);
        $lines = preg_split('/(?<=\n)/', $markdown);
        $offsets = [0];
        foreach ($lines as $line) {
            $offsets[] = end($offsets) + strlen($line);
        }
        $edits = [];
        $acceptedUrls = [];
        foreach ($this->renderedImages($document) as $image) {
            $span = $spans[spl_object_id($image)];
            if ($accept($span['url'])) {
                $acceptedUrls[] = $span['url'];

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
            // A text entity keeps adjacent syntax and leading code indentation isolated through re-rendering.
            $markdown = substr_replace($markdown, '&#32;', $position, $length);
        }

        $final = (new MarkdownParser($environment))->parse(ArticleHtmlPresenter::prepareMarkdown($markdown));
        if (array_map(static fn (Image $image): string => $image->getUrl(), $this->renderedImages($final)) !== $acceptedUrls) {
            throw new RuntimeException('generated_image_render_mismatch');
        }

        return $markdown;
    }

    /** Alt text can contain Image nodes; only the outer image produces a rendered img element. */
    private function renderedImages(Node $document): array
    {
        $images = [];
        $walker = $document->walker();
        while ($event = $walker->next()) {
            $node = $event->getNode();
            if (! $event->isEntering() || ! $node instanceof Image) {
                continue;
            }
            for ($ancestor = $node->parent(); $ancestor !== null; $ancestor = $ancestor->parent()) {
                if ($ancestor instanceof Image) {
                    continue 2;
                }
            }
            $images[] = $node;
        }

        return $images;
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
        // Consume only actual AST containers. A cell's literal "1. " or "- " is table text.
        $ancestors = [];
        for ($ancestor = $table->parent(); $ancestor !== null; $ancestor = $ancestor->parent()) {
            $ancestors[] = $ancestor;
        }
        $cursor = new Cursor($raw);
        foreach (array_reverse($ancestors) as $ancestor) {
            if ($ancestor instanceof ListItem) {
                $data = $ancestor->getListData();
                $cursor->advanceBy($data->markerOffset + $data->padding, true);
            } elseif ($ancestor instanceof BlockQuote) {
                $cursor->advanceToNextNonSpaceOrTab();
                if ($cursor->getCharacter() === '>') {
                    $cursor->advanceBy(1);
                    if (in_array($cursor->getCharacter(), [' ', "\t"], true)) {
                        $cursor->advanceBy(1, true);
                    }
                }
            }
        }
        $cursor->advanceToNextNonSpaceOrTab();
        $start = strlen($cursor->getSubstring(0, $cursor->getPosition()));
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
