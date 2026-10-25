<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\SystemUpdater\RecoveryState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecoveryStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/geoflow-recovery-state-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    private function writeState(string $phase = 'ready', string $epoch = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        $state = ['schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => $epoch,
            'transaction_id' => $phase === 'ready' ? null : 'recovery-test-01', 'phase' => $phase, 'minimum_updater_protocol' => 5];
        file_put_contents($this->directory.'/state.json', json_encode($state));

        return $state;
    }

    public function test_unmanaged_installation_does_not_claim_epoch_protection(): void
    {
        $state = new RecoveryState($this->directory, 'primary', false);
        $this->assertNull($state->snapshot());
        $this->assertNull($state->assertHttpReady());
        $state->assertWriteEpoch(null);
    }

    public function test_reads_atomic_replacements_without_caching_the_epoch(): void
    {
        $state = new RecoveryState($this->directory, 'primary', true);
        $first = $this->writeState();
        $this->assertSame($first, $state->snapshot());
        $next = array_replace($first, ['epoch' => str_repeat('c', 32)]);
        file_put_contents($this->directory.'/replacement.json', json_encode($next));
        rename($this->directory.'/replacement.json', $this->directory.'/state.json');

        $this->assertSame($next, $state->snapshot());
        $this->expectException(ApiException::class);
        $state->assertWriteEpoch($first['epoch']);
    }

    #[DataProvider('invalidStates')]
    public function test_required_invalid_state_fails_closed(?string $bytes): void
    {
        if ($bytes !== null) {
            file_put_contents($this->directory.'/state.json', $bytes);
        }
        try {
            (new RecoveryState($this->directory, 'primary', true))->snapshot();
            $this->fail('An enabled recovery contract requires valid host state.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->getHttpStatus());
            $this->assertSame('recovery_state_unavailable', $exception->getErrorCode());
        }
    }

    public static function invalidStates(): array
    {
        $valid = ['schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => null, 'phase' => 'ready', 'minimum_updater_protocol' => 5];

        return ['missing' => [null], 'corrupt' => ['{'], 'oversized' => [str_repeat(' ', 8193)],
            'foreign instance' => [json_encode(array_replace($valid, ['instance_id' => 'other']))],
            'unknown phase' => [json_encode(array_replace($valid, ['phase' => 'unknown']))],
            'invalid epoch' => [json_encode(array_replace($valid, ['epoch' => '']))],
            'missing transaction' => [json_encode(array_diff_key($valid, ['transaction_id' => true]))],
            'invalid transaction' => [json_encode(array_replace($valid, ['transaction_id' => ['invalid']]))],
            'null active transaction' => [json_encode(array_replace($valid, ['phase' => 'http_ready']))],
            'old protocol' => [json_encode(array_replace($valid, ['minimum_updater_protocol' => 4]))]];
    }

    public function test_restoring_blocks_http_and_background_execution(): void
    {
        $this->writeState('restoring');
        $state = new RecoveryState($this->directory, 'primary', true);
        foreach (['assertHttpReady', 'assertBackgroundReady'] as $method) {
            try {
                $state->{$method}();
                $this->fail('Recovery has not been verified.');
            } catch (ApiException $exception) {
                $this->assertSame(503, $exception->getHttpStatus());
            }
        }
    }

    public function test_http_ready_keeps_background_work_held_and_requires_current_epoch(): void
    {
        $expected = $this->writeState('http_ready');
        $state = new RecoveryState($this->directory, 'primary', true);
        $this->assertSame($expected, $state->assertHttpReady());
        $state->assertWriteEpoch($expected['epoch']);
        foreach ([null, str_repeat('d', 32)] as $epoch) {
            try {
                $state->assertWriteEpoch($epoch);
                $this->fail('Missing or stale epochs cannot authorize a write.');
            } catch (ApiException $exception) {
                $this->assertSame(409, $exception->getHttpStatus());
            }
        }
        $this->expectException(ApiException::class);
        $state->assertBackgroundReady();
    }

    public function test_symbolic_state_is_not_followed(): void
    {
        $this->writeState();
        rename($this->directory.'/state.json', $this->directory.'/actual.json');
        symlink($this->directory.'/actual.json', $this->directory.'/state.json');
        $this->expectException(ApiException::class);
        (new RecoveryState($this->directory, 'primary', true))->snapshot();
    }
}
