<?php

namespace App\Console\GeoFlowCli;

class ArgumentParser
{
    /** @param list<string> $tokens */
    public static function parse(array $tokens): ParsedArguments
    {
        $options = [];
        $positionals = [];
        $parseOptions = true;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($parseOptions && $token === '--') {
                $parseOptions = false;

                continue;
            }

            if ($parseOptions && str_starts_with($token, '-') && ! str_starts_with($token, '--')) {
                self::parseShortOptions($token, $options);

                continue;
            }

            if (! $parseOptions || ! str_starts_with($token, '--')) {
                $positionals[] = $token;

                continue;
            }

            $option = substr($token, 2);
            if ($option === '') {
                continue;
            }

            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                if ($name === '') {
                    throw new CliException('选项名称不能为空');
                }
                self::assertKnownOption($name);
                if (in_array($name, CommandSpec::booleanOptions(), true)) {
                    if ($name === 'verbose' && preg_match('/^[1-3]$/D', $value) === 1) {
                        $options[$name] = (int) $value;

                        continue;
                    }

                    throw new CliException("布尔选项 --{$name} 不接受值");
                }
                $options[$name] = $value;

                continue;
            }

            self::assertKnownOption($option);
            if (in_array($option, CommandSpec::booleanOptions(), true)) {
                $options[$option] = $option === 'verbose'
                    ? ((int) ($options[$option] ?? 0)) + 1
                    : true;

                continue;
            }

            $value = $tokens[$index + 1] ?? null;
            if ($value === null || str_starts_with($value, '--')) {
                throw new CliException("选项 --{$option} 缺少值");
            }

            $options[$option] = $value;
            $index++;
        }

        return new ParsedArguments($options, $positionals);
    }

    /** @param array<string,mixed> $options */
    private static function parseShortOptions(string $token, array &$options): void
    {
        foreach (str_split(substr($token, 1)) as $short) {
            match ($short) {
                'h' => $options['help'] = true,
                'V' => $options['version'] = true,
                'n' => $options['no-interaction'] = true,
                'q' => $options['quiet'] = true,
                'v' => $options['verbose'] = ((int) ($options['verbose'] ?? 0)) + 1,
                default => throw new CliException("未知短选项 -{$short}"),
            };
        }
    }

    private static function assertKnownOption(string $name): void
    {
        if (! in_array($name, CommandSpec::knownOptions(), true)) {
            throw new CliException("未知选项 --{$name}");
        }
    }
}
