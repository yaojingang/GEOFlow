<?php

namespace App\Services\GeoFlow;

use LengthException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\FilterHelper;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * Runs only inside the memory- and time-limited PDF extraction process.
 */
final class BoundedKnowledgePdfParser extends Parser
{
    public const MAX_CONTENT_BYTES = 8 * 1024 * 1024;

    public const MAX_STREAM_BYTES = 16 * 1024 * 1024;

    public const MAX_DECODED_BYTES = 32 * 1024 * 1024;

    public const DECODE_LIMIT_MESSAGE = 'PDF decoded stream budget exceeded.';

    public function __construct()
    {
        $config = new Config;
        $config->setDecodeMemoryLimit(self::MAX_STREAM_BYTES + 1);
        $config->setRetainImageContent(false);
        parent::__construct([], $config);

        $this->rawDataParser = new class($config) extends RawDataParser
        {
            public function __construct(Config $config)
            {
                parent::__construct(['ignore_filter_decoding_errors' => false, 'ignore_missing_filter_decoders' => false], $config);
                $this->filterHelper = new class extends FilterHelper
                {
                    private int $decodedBytes = 0;

                    public function decodeFilter(string $filter, string $data, int $decodeMemoryLimit = 0): string
                    {
                        $decoded = parent::decodeFilter($filter, $data, $decodeMemoryLimit);
                        $this->decodedBytes += strlen($decoded);

                        /** FlateDecode's fallback can return a truncated prefix at the configured limit. */
                        if (strlen($decoded) > BoundedKnowledgePdfParser::MAX_STREAM_BYTES
                            || $this->decodedBytes > BoundedKnowledgePdfParser::MAX_DECODED_BYTES) {
                            throw new LengthException(BoundedKnowledgePdfParser::DECODE_LIMIT_MESSAGE);
                        }

                        return $decoded;
                    }
                };
            }
        };
    }
}
