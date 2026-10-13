<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Reads a value that comes untyped, from an option, a request, a constant
 * or a record of a database, as the type the code needs: a scalar is
 * converted, anything else gives the default.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
final class Value
{
    /**
     * @param mixed  $value
     * @param string $default
     *
     * @return string
     */
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param mixed $value
     * @param int   $default
     *
     * @return int
     */
    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param mixed $value
     * @param float $default
     *
     * @return float
     */
    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param mixed $value
     *
     * @return array<mixed>
     */
    public static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The scalars of a list, as strings; anything else left out
     *
     * @param mixed $value
     *
     * @return string[]
     */
    public static function strings(mixed $value): array
    {
        $strings = [];

        foreach (self::list($value) as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}
