<?php

namespace App\Console\GeoFlowCli;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Entrypoint
{
    public static function run(
        GeoFlowApplication $application,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $errorOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;

        try {
            return $application->run($input, $output);
        } catch (ApiException $exception) {
            self::renderApiError($errorOutput, $exception, $application->takeDeferredWarnings());

            return 1;
        } catch (CliException $exception) {
            foreach ($application->takeDeferredWarnings() as $warning) {
                $errorOutput->writeln(SecretRedactor::text($warning));
            }
            $errorOutput->writeln(SecretRedactor::text($exception->getMessage()));

            return $exception->exitCode;
        } catch (Throwable $exception) {
            foreach ($application->takeDeferredWarnings() as $warning) {
                $errorOutput->writeln(SecretRedactor::text($warning));
            }
            $errorOutput->writeln('Unexpected error: '.SecretRedactor::text($exception->getMessage()));

            return 1;
        }
    }

    /** @param list<string> $warnings */
    private static function renderApiError(OutputInterface $output, ApiException $exception, array $warnings): void
    {
        $warnings = array_map(
            static fn (string $warning): string => SecretRedactor::text($warning),
            $warnings,
        );
        $hint = match ($exception->httpStatus) {
            401 => '认证失败，请检查 token 或重新运行 geoflow login。',
            403 => '当前 token 缺少所需 API scope。',
            423 => '目标资源已锁定，请稍后重试或检查资源状态。',
            429 => self::rateLimitHint($exception->payload),
            default => null,
        };

        if ($exception->payload !== []) {
            $payload = SecretRedactor::payload($exception->payload);
            if ($hint !== null) {
                $payload['cli_hint'] = $hint;
            }
            if ($exception->httpStatus === 429 && ($retryAfter = self::retryAfter($exception->payload)) !== null) {
                $payload['retry_after'] = $retryAfter;
            }
            if ($warnings !== []) {
                $payload['cli_warnings'] = $warnings;
            }
            $output->writeln(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $message = SecretRedactor::text($exception->getMessage());
            if ($warnings !== []) {
                $message .= PHP_EOL.implode(PHP_EOL, $warnings);
            }
            $output->writeln($hint === null ? $message : $message.PHP_EOL.'提示: '.$hint);
        }
    }

    /** @param array<string,mixed> $payload */
    private static function rateLimitHint(array $payload): string
    {
        $retryAfter = self::retryAfter($payload);

        return $retryAfter === null
            ? '请求过于频繁，请稍后重试。'
            : '请求过于频繁，请在 '.$retryAfter.' 秒后重试。';
    }

    /** @param array<string,mixed> $payload */
    private static function retryAfter(array $payload): int|float|string|null
    {
        $retryAfter = $payload['error']['details']['retry_after']
            ?? $payload['meta']['retry_after']
            ?? $payload['retry_after']
            ?? null;

        if (is_int($retryAfter)) {
            return $retryAfter >= 0 ? $retryAfter : null;
        }
        if (is_float($retryAfter)) {
            return is_finite($retryAfter) && $retryAfter >= 0 ? $retryAfter : null;
        }
        if (is_string($retryAfter) && preg_match('/^\d+(?:\.\d+)?$/D', $retryAfter) === 1) {
            return $retryAfter;
        }

        return null;
    }
}
