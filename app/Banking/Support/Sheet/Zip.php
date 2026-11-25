<?php

namespace App\Banking\Support\Sheet;

use RuntimeException;

/**
 * Reads the entries of a ZIP archive held in memory (an .xlsx file is one) with nothing but zlib — the server does not
 * need the zip extension. Only what a spreadsheet uses: stored and deflated entries, no encryption, no ZIP64.
 * An entry is refused beyond `MAX_ENTRY_BYTES` once inflated, so a crafted archive cannot fill the memory.
 */
final class Zip
{
    public const MAX_ENTRY_BYTES = 48 * 1024 * 1024;

    private const MAX_ENTRIES = 4000;

    /** @var array<string, array{method:int, compressed:int, size:int, offset:int}> by entry name */
    private array $entries = [];

    public function __construct(private readonly string $bytes)
    {
        $this->readDirectory();
    }

    public static function looksLikeZip(string $bytes): bool
    {
        return strncmp($bytes, "PK\x03\x04", 4) === 0;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /** The content of an entry, or null when the archive has none of that name. */
    public function read(string $name): ?string
    {
        $e = $this->entries[$name] ?? null;
        if (! $e) {
            return null;
        }
        if ($e['size'] > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('entry too large');
        }
        $header = substr($this->bytes, $e['offset'], 30);
        if (strlen($header) < 30 || substr($header, 0, 4) !== "PK\x03\x04") {
            throw new RuntimeException('bad local header');
        }
        $lengths = unpack('vname/vextra', substr($header, 26, 4));
        $data = substr($this->bytes, $e['offset'] + 30 + $lengths['name'] + $lengths['extra'], $e['compressed']);
        if ($e['method'] === 0) {
            return $data;
        }
        if ($e['method'] !== 8) {
            throw new RuntimeException('unsupported compression');
        }
        $out = @gzinflate($data, self::MAX_ENTRY_BYTES + 1);
        if ($out === false || strlen($out) > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('entry cannot be inflated');
        }

        return $out;
    }

    private function readDirectory(): void
    {
        $length = strlen($this->bytes);
        // the end-of-central-directory record sits in the last 22 bytes + an optional comment (≤ 65535 bytes)
        $tail = substr($this->bytes, max(0, $length - 65557));
        $at = strrpos($tail, "PK\x05\x06");
        if ($at === false || strlen($tail) - $at < 22) {
            throw new RuntimeException('not a zip archive');
        }
        $end = unpack('vdisk/vcdDisk/vhere/vtotal/Vsize/Voffset', substr($tail, $at + 4, 16));
        if ($end['total'] > self::MAX_ENTRIES || $length < $end['offset'] + $end['size']) {
            throw new RuntimeException('bad central directory');
        }
        $p = $end['offset'];
        for ($i = 0; $i < $end['total']; $i++) {
            if (substr($this->bytes, $p, 4) !== "PK\x01\x02") {
                throw new RuntimeException('bad central directory entry');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdiskNo/vinternal/Vexternal/Voffset', substr($this->bytes, $p + 4, 42));
            $name = substr($this->bytes, $p + 46, $h['name']);
            if ($h['flags'] & 1) {
                throw new RuntimeException('encrypted archive');
            }
            $this->entries[$name] = ['method' => $h['method'], 'compressed' => $h['compressed'], 'size' => $h['size'], 'offset' => $h['offset']];
            $p += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
    }
}
