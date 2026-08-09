<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\ArgumentParser;
use App\Console\GeoFlowCli\CliException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ArgumentParserTest extends TestCase
{
    #[Test]
    public function global_options_are_parsed_before_and_after_subcommands(): void
    {
        $parsed = ArgumentParser::parse([
            '--config', '/tmp/geoflow.json',
            'task', 'get', '12',
            '--base-url=https://api.example.com',
            '--token', 'secret-token',
            '--timeout', '8',
            '--allow-insecure-http',
        ]);

        $this->assertSame(['task', 'get', '12'], $parsed->positionals);
        $this->assertSame('/tmp/geoflow.json', $parsed->options['config']);
        $this->assertSame('https://api.example.com', $parsed->options['base-url']);
        $this->assertSame('secret-token', $parsed->options['token']);
        $this->assertSame('8', $parsed->options['timeout']);
        $this->assertTrue($parsed->options['allow-insecure-http']);
    }

    #[Test]
    public function short_help_is_a_boolean_option(): void
    {
        $parsed = ArgumentParser::parse(['task', '-h']);

        $this->assertSame(['task'], $parsed->positionals);
        $this->assertTrue($parsed->options['help']);
    }

    #[Test]
    public function missing_option_value_is_rejected(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--token');

        ArgumentParser::parse(['catalog', '--token']);
    }

    #[Test]
    public function symfony_standard_flags_do_not_consume_the_command(): void
    {
        $parsed = ArgumentParser::parse(['--no-interaction', '-qvv', 'task', 'get', '12']);

        $this->assertSame(['task', 'get', '12'], $parsed->positionals);
        $this->assertTrue($parsed->options['no-interaction']);
        $this->assertTrue($parsed->options['quiet']);
        $this->assertSame(2, $parsed->options['verbose']);
    }

    #[Test]
    public function misspelled_options_are_rejected(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('未知选项 --tokne');

        ArgumentParser::parse(['catalog', '--tokne', 'secret']);
    }

    #[Test]
    public function boolean_options_reject_explicit_values(): void
    {
        $this->expectException(CliException::class);
        $this->expectExceptionMessage('--version');

        ArgumentParser::parse(['--version=false']);
    }

    #[Test]
    public function verbose_keeps_its_supported_numeric_form(): void
    {
        $parsed = ArgumentParser::parse(['--verbose=2', 'catalog']);

        $this->assertSame(2, $parsed->options['verbose']);
        $this->assertSame(['catalog'], $parsed->positionals);
    }
}
