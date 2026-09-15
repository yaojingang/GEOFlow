<?php

declare(strict_types=1);

namespace GeoFlow\Distribution;

use RuntimeException;

final class StandaloneArguments
{
    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $valueOptions
     * @param  list<string>  $flags
     * @return array<string, string|true>
     */
    public static function parse(array $arguments, array $valueOptions, array $flags = []): array
    {
        $options = [];
        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];
            if (! str_starts_with($argument, '--') || $argument === '--') {
                throw new RuntimeException('Unexpected positional argument. Use named options only.');
            }
            $parts = explode('=', substr($argument, 2), 2);
            $name = $parts[0];
            if (! in_array($name, $valueOptions, true) && ! in_array($name, $flags, true)) {
                throw new RuntimeException('Unknown option --'.$name.'.');
            }
            if (array_key_exists($name, $options)) {
                throw new RuntimeException('Duplicate option --'.$name.'.');
            }
            if (in_array($name, $flags, true)) {
                if (count($parts) !== 1) {
                    throw new RuntimeException('Flag --'.$name.' does not accept a value.');
                }
                $options[$name] = true;

                continue;
            }
            $value = $parts[1] ?? $arguments[++$index] ?? null;
            if ($value === null || $value === '' || (count($parts) === 1 && str_starts_with($value, '--'))) {
                throw new RuntimeException('Option --'.$name.' requires a non-empty value.');
            }
            $options[$name] = $value;
        }

        return $options;
    }
}
