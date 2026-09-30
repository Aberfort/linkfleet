<?php

namespace Tests\Support;

use InvalidArgumentException;

/**
 * Builds a small, valid MaxMind DB file from a list of networks, so tests can
 * exercise the real reader on the real format without downloading a
 * multi-megabyte database.
 *
 * Only what is needed: 24-bit records, IPv6 tree with the IPv4 space at
 * ::/96 (how country databases are actually laid out), maps and strings in
 * the data section. The layout is the published MMDB spec, cross-checked
 * against how MaxMind\Db\Reader walks the file.
 */
final class MmdbFixture
{
    private const RECORD_SIZE = 24;

    /**
     * @param  array<string, string|array<string, mixed>>  $networks  CIDR => ISO country code, or a full record
     */
    public static function build(array $networks, string $databaseType = 'DBIP-Country-Lite', int $ipVersion = 6): string
    {
        // Data section: one record per distinct value, remembered by offset.
        $data = '';
        $offsets = [];

        foreach ($networks as $cidr => $value) {
            $record = is_array($value) ? $value : ['country' => ['iso_code' => $value]];
            $encoded = self::map($record);
            $key = md5($encoded);

            if (! isset($offsets[$key])) {
                $offsets[$key] = strlen($data);
                $data .= $encoded;
            }

            $networks[$cidr] = $offsets[$key];
        }

        // Search tree as nested arrays: node = [left, right], each null (empty),
        // ['node' => n] or ['data' => offset].
        $nodes = [[null, null]];

        foreach ($networks as $cidr => $offset) {
            $bits = self::bitsOf($cidr, $ipVersion);
            $at = 0;

            foreach ($bits as $depth => $bit) {
                if ($depth === count($bits) - 1) {
                    $nodes[$at][$bit] = ['data' => $offset];

                    break;
                }

                if (! isset($nodes[$at][$bit]['node'])) {
                    $nodes[] = [null, null];
                    $nodes[$at][$bit] = ['node' => count($nodes) - 1];
                }

                $at = $nodes[$at][$bit]['node'];
            }
        }

        $nodeCount = count($nodes);
        $tree = '';

        foreach ($nodes as $node) {
            foreach ($node as $record) {
                $value = match (true) {
                    $record === null => $nodeCount,
                    isset($record['node']) => $record['node'],
                    default => $nodeCount + 16 + $record['data'],
                };
                $tree .= substr(pack('N', $value), 1); // 24 bits, big-endian
            }
        }

        $metadata = self::map([
            'node_count' => self::uint(6, $nodeCount),
            'record_size' => self::uint(5, self::RECORD_SIZE),
            'ip_version' => self::uint(5, $ipVersion),
            'database_type' => $databaseType,
            'languages' => self::array(['en']),
            'binary_format_major_version' => self::uint(5, 2),
            'binary_format_minor_version' => self::uint(5, 0),
            'build_epoch' => self::uint64(1_790_000_000),
            'description' => ['en' => 'Test fixture'],
        ]);

        return $tree.str_repeat("\0", 16).$data."\xAB\xCD\xEFMaxMind.com".$metadata;
    }

    /** @return array<int, int> */
    private static function bitsOf(string $cidr, int $ipVersion): array
    {
        [$address, $length] = array_pad(explode('/', $cidr, 2), 2, null);
        $packed = inet_pton($address);

        if ($packed === false) {
            throw new InvalidArgumentException("Not an address: {$cidr}");
        }

        $isV4 = strlen($packed) === 4;
        $length = (int) ($length ?? ($isV4 ? 32 : 128));
        $bits = [];

        foreach (str_split($packed) as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (ord($byte) >> $i) & 1;
            }
        }

        $bits = array_slice($bits, 0, $length);

        // IPv4 lives at ::/96 in an IPv6 tree.
        return $isV4 && $ipVersion === 6 ? [...array_fill(0, 96, 0), ...$bits] : $bits;
    }

    /** @param  array<string, mixed>  $values */
    private static function map(array $values): string
    {
        $out = chr(0xE0 | count($values));

        foreach ($values as $key => $value) {
            $out .= self::string((string) $key).self::value($value);
        }

        return $out;
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            is_string($value) && str_starts_with($value, "\x00RAW") => substr($value, 4),
            is_string($value) => self::string($value),
            is_array($value) => self::map($value),
        };
    }

    private static function string(string $text): string
    {
        return chr(0x40 | strlen($text)).$text;
    }

    /** Wrapped so map() passes it through untouched. */
    private static function uint(int $type, int $value): string
    {
        $bytes = ltrim(pack('N', $value), "\0");

        return "\x00RAW".chr(($type << 5) | strlen($bytes)).$bytes;
    }

    private static function uint64(int $value): string
    {
        $bytes = ltrim(pack('J', $value), "\0");

        // Extended type: the type is stored, minus 7, in the byte after the control byte.
        return "\x00RAW".chr(strlen($bytes)).chr(9 - 7).$bytes;
    }

    /** @param  array<int, string>  $items */
    private static function array(array $items): string
    {
        return "\x00RAW".chr(count($items)).chr(11 - 7).implode('', array_map(self::string(...), $items));
    }
}
