<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Writes small MMDB files for the tests, in the format of MaxMind DB 2.0:
 * an IPv4 search tree with 24-bit records, sixteen null bytes, the data
 * section, then the metadata after its marker. Networks must not overlap.
 */

/**
 * Writes an MMDB file holding records for IPv4 networks.
 *
 * @param string                              $path
 * @param string                              $type      database_type of the metadata.
 * @param array<string, array<string, mixed>> $networks  Records by network, as 8.8.8.0/24.
 * @param string[]                            $languages
 *
 * @return string The path.
 */
function make_mmdb(string $path, string $type, array $networks, array $languages = ['en']): string
{
    // Each node holds its two children: a node index, a record (as
    // ['data' => key]), or null for no data.
    $nodes = [[null, null]];
    $records = [];

    foreach ($networks as $network => $record) {
        [$ip, $prefix] = explode('/', $network);
        $bits = sprintf('%032b', ip2long($ip));
        $node = 0;
        $records[$network] = $record;

        for ($i = 0; $i < (int) $prefix; $i++) {
            $bit = (int) $bits[$i];

            if ($i === (int) $prefix - 1) {
                $nodes[$node][$bit] = ['data' => $network];
                break;
            }

            if (! is_int($nodes[$node][$bit])) {
                $nodes[] = [null, null];
                $nodes[$node][$bit] = count($nodes) - 1;
            }

            $node = $nodes[$node][$bit];
        }
    }

    $data = '';
    $offsets = [];

    foreach ($records as $network => $record) {
        $offsets[$network] = strlen($data);
        $data .= mmdb_encode($record);
    }

    $count = count($nodes);
    $tree = '';

    foreach ($nodes as $children) {
        foreach ($children as $child) {
            $value = match (true) {
                is_int($child) => $child,
                is_array($child) => $count + 16 + $offsets[$child['data']],
                default => $count,
            };
            $tree .= substr(pack('N', $value), 1);
        }
    }

    $metadata = mmdb_encode([
        'binary_format_major_version' => 2,
        'binary_format_minor_version' => 0,
        'build_epoch' => 1791288000,
        'database_type' => $type,
        'description' => ['en' => 'Test database'],
        'ip_version' => 4,
        'languages' => $languages,
        'node_count' => $count,
        'record_size' => 24,
    ]);

    file_put_contents($path, $tree . str_repeat("\0", 16) . $data . "\xAB\xCD\xEFMaxMind.com" . $metadata);

    return $path;
}

/**
 * A value in the data format of MaxMind DB
 *
 * @param mixed $value
 *
 * @return string
 */
function mmdb_encode(mixed $value): string
{
    return match (true) {
        is_string($value) => mmdb_control(2, strlen($value)) . $value,
        is_bool($value) => mmdb_control(14, (int) $value),
        is_float($value) => mmdb_control(3, 8) . pack('E', $value),
        is_int($value) => mmdb_control(6, strlen(ltrim(pack('N', $value), "\0"))) . ltrim(pack('N', $value), "\0"),
        is_array($value) && array_is_list($value) && $value !== [] => mmdb_control(11, count($value)) . implode('', array_map('mmdb_encode', $value)),
        is_array($value) => mmdb_control(7, count($value)) . implode('', array_map(static fn($key, $item): string => mmdb_encode((string) $key) . mmdb_encode($item), array_keys($value), $value)),
        default => throw new InvalidArgumentException('Cannot encode ' . get_debug_type($value)),
    };
}

/**
 * The control byte of a value, with its extended type and size bytes
 *
 * @param int $type
 * @param int $size
 *
 * @return string
 */
function mmdb_control(int $type, int $size): string
{
    [$sizeBits, $sizeBytes] = match (true) {
        $size < 29 => [$size, ''],
        $size < 285 => [29, chr($size - 29)],
        $size < 65821 => [30, pack('n', $size - 285)],
        default => [31, substr(pack('N', $size - 65821), 1)],
    };

    return $type > 7
        ? chr($sizeBits) . chr($type - 7) . $sizeBytes
        : chr(($type << 5) | $sizeBits) . $sizeBytes;
}
