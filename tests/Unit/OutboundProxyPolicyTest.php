<?php

namespace Tests\Unit;

use App\Services\Outbound\OutboundProxyPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutboundProxyPolicyTest extends TestCase
{
    #[Test]
    public function empty_policy_is_disabled_and_never_matches(): void
    {
        $policy = OutboundProxyPolicy::fromConfig(null, '');

        self::assertFalse($policy->isEnabled());
        self::assertFalse($policy->appliesTo('jshh.com'));
        self::assertFalse($policy->appliesTo('anything.test'));
    }

    #[Test]
    public function policy_without_hosts_is_disabled(): void
    {
        $policy = OutboundProxyPolicy::fromConfig('http://proxy.example.com:8080', '');

        self::assertFalse($policy->isEnabled(), 'hosts list empty → proxy must remain off to avoid breaking AI traffic');
    }

    #[Test]
    public function policy_without_url_is_disabled(): void
    {
        $policy = OutboundProxyPolicy::fromConfig(null, 'jshh.com');

        self::assertFalse($policy->isEnabled());
    }

    #[Test]
    public function exact_host_match(): void
    {
        $policy = OutboundProxyPolicy::fromConfig('http://proxy:8080', 'jshh.com');

        self::assertTrue($policy->isEnabled());
        self::assertTrue($policy->appliesTo('jshh.com'));
        self::assertTrue($policy->appliesTo('JSHH.COM'), 'host comparison must be case-insensitive');
        self::assertFalse($policy->appliesTo('www.jshh.com'), 'exact match must not leak to subdomains');
        self::assertFalse($policy->appliesTo('api.example.com'));
    }

    #[Test]
    public function suffix_wildcard_match(): void
    {
        $policy = OutboundProxyPolicy::fromConfig('http://proxy:8080', '*.jshh.com');

        self::assertTrue($policy->appliesTo('www.jshh.com'));
        self::assertTrue($policy->appliesTo('cdn.jshh.com'));
        self::assertFalse($policy->appliesTo('jshh.com'), '*.jshh.com must not match the apex host');
        self::assertFalse($policy->appliesTo('jshh.com.cn'));
        self::assertFalse($policy->appliesTo('eviljshh.com'));
    }

    #[Test]
    public function star_and_dot_act_as_global_match(): void
    {
        foreach (['*', '.'] as $pattern) {
            $policy = OutboundProxyPolicy::fromConfig('http://proxy:8080', $pattern);
            self::assertTrue($policy->appliesTo('jshh.com'));
            self::assertTrue($policy->appliesTo('api.example.com'));
            self::assertTrue($policy->appliesTo('random.test'));
        }
    }

    #[Test]
    public function multiple_patterns_combine_as_or(): void
    {
        $policy = OutboundProxyPolicy::fromConfig(
            'http://proxy:8080',
            'jshh.com, *.cdn.test, exact.match'
        );

        self::assertTrue($policy->appliesTo('jshh.com'));
        self::assertTrue($policy->appliesTo('a.cdn.test'));
        self::assertTrue($policy->appliesTo('exact.match'));
        self::assertFalse($policy->appliesTo('unrelated.test'));
    }

    #[Test]
    public function whitespace_and_empty_entries_are_tolerated(): void
    {
        $policy = OutboundProxyPolicy::fromConfig(
            'http://proxy:8080',
            '  jshh.com ,,  *.example.com ,  '
        );

        self::assertTrue($policy->appliesTo('jshh.com'));
        self::assertTrue($policy->appliesTo('a.example.com'));
    }

    #[Test]
    public function empty_host_never_matches(): void
    {
        $policy = OutboundProxyPolicy::fromConfig('http://proxy:8080', '*');
        self::assertFalse($policy->appliesTo(''));
        self::assertFalse($policy->appliesTo('   '));
    }

    #[Test]
    public function proxy_url_is_trimmed_and_stored(): void
    {
        $policy = OutboundProxyPolicy::fromConfig('  http://user:pass@resi.example.com:8231  ', 'jshh.com');
        self::assertSame('http://user:pass@resi.example.com:8231', $policy->proxyUrl);
    }
}
