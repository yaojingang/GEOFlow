<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CliException;
use App\Console\GeoFlowCli\GeoFlowApplication;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class GeoFlowApplicationTest extends TestCase
{
    #[Test]
    public function version_is_emitted_as_the_stable_json_contract(): void
    {
        $application = new GeoFlowApplication(new HttpFactory);
        $application->setAutoExit(false);
        $output = new BufferedOutput;

        $status = $application->run(new ArgvInput(['geoflow', '--version']), $output);

        $this->assertSame(0, $status);
        $this->assertSame([
            'name' => 'geoflow',
            'version' => '0.2.0',
        ], json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function help_documents_image_upload_as_the_public_interface(): void
    {
        $application = new GeoFlowApplication(new HttpFactory);
        $application->setAutoExit(false);
        $output = new BufferedOutput;

        $status = $application->run(new ArgvInput(['geoflow', '--help']), $output);

        $this->assertSame(0, $status);
        $help = $output->fetch();
        $this->assertStringContainsString('item-upload TYPE ID --image FILE', $help);
        $this->assertStringNotContainsString('--token TOKEN', $help);
        $this->assertStringNotContainsString('--password PASS', $help);
        $this->assertStringContainsString('task jobs TASK_ID [--status STATUS] [--limit N]', $help);
        $this->assertStringContainsString('article review ARTICLE_ID --status STATUS [--note TEXT] [--risk-override-reason TEXT]', $help);
        $this->assertStringContainsString('Article update direct fields:', $help);
        $this->assertStringContainsString('--quiet (-q)', $help);
    }

    #[Test]
    public function legacy_config_help_keeps_its_json_success_contract(): void
    {
        $application = new GeoFlowApplication(new HttpFactory);
        $application->setAutoExit(false);
        $output = new BufferedOutput;

        $status = $application->run(new ArgvInput(['geoflow', 'config', 'help']), $output);

        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status);
        $this->assertCount(2, $payload['usage']);
    }

    #[Test]
    public function local_help_and_version_commands_reject_extra_positionals(): void
    {
        $application = new GeoFlowApplication(new HttpFactory);
        $application->setAutoExit(false);

        foreach (['help', 'version'] as $command) {
            try {
                $application->run(new ArgvInput(['geoflow', $command, 'extra']), new BufferedOutput);
                $this->fail("Expected {$command} to reject an extra positional.");
            } catch (CliException $exception) {
                $this->assertStringContainsString('不接受位置参数', $exception->getMessage());
            }
        }
    }
}
