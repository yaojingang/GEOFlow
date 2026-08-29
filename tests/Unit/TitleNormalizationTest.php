<?php

namespace Tests\Unit;

use App\Models\Title;
use PHPUnit\Framework\TestCase;

class TitleNormalizationTest extends TestCase
{
    public function test_normalization_preserves_joiners_that_carry_text_and_emoji_semantics(): void
    {
        $emojiWithJoiner = "👩\u{200D}💻";
        $emojiWithoutJoiner = '👩💻';
        $persianWithNonJoiner = "می\u{200C}روم";
        $persianWithoutNonJoiner = 'میروم';

        $this->assertSame($emojiWithJoiner, Title::normalizeText($emojiWithJoiner));
        $this->assertNotSame(
            Title::fingerprintFor($emojiWithJoiner),
            Title::fingerprintFor($emojiWithoutJoiner),
        );
        $this->assertSame($persianWithNonJoiner, Title::normalizeText($persianWithNonJoiner));
        $this->assertNotSame(
            Title::fingerprintFor($persianWithNonJoiner),
            Title::fingerprintFor($persianWithoutNonJoiner),
        );
    }

    public function test_normalization_still_removes_forbidden_control_and_format_characters(): void
    {
        $this->assertSame('GEO标题', Title::normalizeText("GEO\u{200B}\0标\u{2060}题"));
    }

    public function test_nfkc_normalization_keeps_a_falsy_zero_result(): void
    {
        $this->assertSame('0', Title::normalizeText('０'));
        $this->assertSame(Title::fingerprintFor('0'), Title::fingerprintFor('０'));
    }
}
