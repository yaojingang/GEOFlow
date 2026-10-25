<?php

use App\Services\GeoFlow\BoundedKnowledgePdfParser;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $path = $argv[1] ?? '';
    if (! is_file($path) || filesize($path) > BoundedKnowledgePdfParser::MAX_CONTENT_BYTES) {
        exit(2);
    }

    $text = (new BoundedKnowledgePdfParser)->parseFile($path)->getText();
    if (strlen($text) > BoundedKnowledgePdfParser::MAX_CONTENT_BYTES) {
        exit(3);
    }
    if (trim($text) === '') {
        exit(4);
    }

    fwrite(STDOUT, $text);
} catch (LengthException) {
    exit(3);
} catch (Throwable $exception) {
    exit($exception->getMessage() === BoundedKnowledgePdfParser::DECODE_LIMIT_MESSAGE ? 3 : 2);
}
