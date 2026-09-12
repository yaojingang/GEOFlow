<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformCatalog;
use PHPUnit\Framework\TestCase;

class PlatformCatalogTest extends TestCase
{
    public function test_catalog_lists_toutiao_sohu_and_netease_in_order(): void
    {
        $all = PlatformCatalog::all();

        $this->assertSame(['toutiao', 'sohu', 'netease'], array_keys($all));
        $this->assertSame('头条号', $all['toutiao']['label']);
        $this->assertStringStartsWith('https://mp.toutiao.com', $all['toutiao']['editor_url']);
        $this->assertSame('https://mp.163.com', $all['netease']['origin']);
    }

    public function test_is_supported_and_editor_url_override(): void
    {
        $this->assertTrue(PlatformCatalog::isSupported('toutiao'));
        $this->assertFalse(PlatformCatalog::isSupported('zhihu'));

        $this->assertSame(
            'https://mp.sohu.com/custom',
            PlatformCatalog::editorUrl('sohu', 'https://mp.sohu.com/custom')
        );
        $this->assertStringStartsWith(
            'https://mp.sohu.com',
            PlatformCatalog::editorUrl('sohu', null)
        );
    }
}
