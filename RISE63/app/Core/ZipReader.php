<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * Minimal read-only ZIP reader.
 *
 * Why not ZipArchive? Because relying on it would make the updater work on some hosts and fail on
 * others with an error the operator cannot act on — and this project's own test sandbox has no PHP
 * extensions at all. The central directory is parsed directly and the entries are inflated by the
 * caller with the best method available:
 *
 *   - stored (method 0)      a straight copy
 *   - deflated (method 8)    gzinflate() when zlib is loaded
 *   - anything else          reported as unsupported, never guessed at
 *
 * Only what a release archive needs is supported: no ZIP64, no encryption, no multi-disk. Those are
 * rejected explicitly rather than half-read, because a release archive that uses them was not built
 * by the provider's own upload path.
 */
final class ZipReader
{
    private const EOCD_SIGNATURE    = "PK\x05\x06";
    private const CENTRAL_SIGNATURE = "PK\x01\x02";
    private const LOCAL_SIGNATURE   = "PK\x03\x04";
    private const MAX_CENTRAL       = 1048576; // 1 MiB of central directory is already ~10k entries

    /** @var list<array{name:string,dir:bool,size:int,packed:int,method:int,offset:int,crc:int,flags:int}> */
    private array $entries = [];

    private bool $valid = false;
    private string $problem = '';

    public function __construct(private readonly string $path)
    {
        try {
            $this->parse();
            $this->valid = true;
        } catch (RuntimeException $error) {
            $this->problem = $error->getMessage();
        }
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    public function problem(): string
    {
        return $this->problem;
    }

    /** @return list<array{name:string,dir:bool,size:int,packed:int,method:int,offset:int,crc:int,flags:int}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Read one entry's uncompressed contents, or null when it cannot be produced.
     */
    public function read(string $name): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['name'] !== $name || $entry['dir']) {
                continue;
            }
            return $this->extract($entry);
        }
        return null;
    }

    /** The sha256 of an entry's uncompressed contents, without holding it in memory for long. */
    public function sha256(string $name): string
    {
        $contents = $this->read($name);
        return $contents === null ? '' : hash('sha256', $contents);
    }

    /* ─────────────────────────────────── parsing ────────────────────────────────── */

    private function parse(): void
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The archive could not be opened.');
        }

        try {
            $size = (int) (filesize($this->path) ?: 0);
            if ($size < 22) {
                throw new RuntimeException('The archive is too small to be a ZIP file.');
            }

            // The end-of-central-directory record sits in the last 22 bytes plus a comment of up to
            // 65,535 characters, so the search window is bounded.
            $tailLength = min($size, 22 + 65535);
            fseek($handle, $size - $tailLength);
            $tail = (string) fread($handle, $tailLength);
            $at   = strrpos($tail, self::EOCD_SIGNATURE);
            if ($at === false) {
                throw new RuntimeException('This file is not a ZIP archive (no end-of-directory record).');
            }

            // 22 bytes: 4 signature + 2 disk + 2 cd-disk + 2 entries-on-disk + 2 entries +
            // 4 cd-size + 4 cd-offset + 2 comment length. Reading only 20 shifts every field.
            $eocd = unpack('vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset/vcommentlen', substr($tail, $at + 4, 18));
            if ($eocd === false) {
                throw new RuntimeException('The ZIP directory could not be read.');
            }
            if ($eocd['disk'] !== 0 || $eocd['cddisk'] !== 0 || $eocd['diskentries'] !== $eocd['entries']) {
                throw new RuntimeException('Multi-part ZIP archives are not supported. Upload one single .zip.');
            }
            if ($eocd['entries'] === 0xFFFF || $eocd['cdsize'] === 0xFFFFFFFF || $eocd['cdoffset'] === 0xFFFFFFFF) {
                throw new RuntimeException('ZIP64 archives are not supported. Re-export the release as a standard ZIP.');
            }

            $cdSize = (int) $eocd['cdsize'];
            if ($cdSize > self::MAX_CENTRAL) {
                throw new RuntimeException('The ZIP directory is larger than ' . self::MAX_CENTRAL . ' bytes.');
            }
            fseek($handle, (int) $eocd['cdoffset']);
            $central = (string) fread($handle, $cdSize);

            $cursor = 0;
            $count  = (int) $eocd['entries'];
            for ($index = 0; $index < $count; $index++) {
                if (substr($central, $cursor, 4) !== self::CENTRAL_SIGNATURE) {
                    throw new RuntimeException('The ZIP directory is damaged at entry ' . ($index + 1) . '.');
                }
                $header = unpack(
                    'vversionmade/vversionneeded/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vnamelen/vextralen/vcommentlen/vdiskstart/vinternal/Vexternal/Voffset',
                    substr($central, $cursor + 4, 42)
                );
                if ($header === false) {
                    throw new RuntimeException('The ZIP directory is damaged at entry ' . ($index + 1) . '.');
                }

                $name = substr($central, $cursor + 46, (int) $header['namelen']);
                $cursor += 46 + (int) $header['namelen'] + (int) $header['extralen'] + (int) $header['commentlen'];

                if ((int) $header['flags'] & 0x0001) {
                    throw new RuntimeException('The archive contains encrypted files, which a release must not.');
                }
                if ($header['packed'] === 0xFFFFFFFF || $header['size'] === 0xFFFFFFFF || $header['offset'] === 0xFFFFFFFF) {
                    throw new RuntimeException('ZIP64 entries are not supported. Re-export the release.');
                }

                $this->entries[] = [
                    'name'   => $name,
                    'dir'    => str_ends_with($name, '/'),
                    'size'   => (int) $header['size'],
                    'packed' => (int) $header['packed'],
                    'method' => (int) $header['method'],
                    'offset' => (int) $header['offset'],
                    'crc'    => (int) $header['crc'],
                    'flags'  => (int) $header['flags'],
                ];
            }

            if ($this->entries === []) {
                throw new RuntimeException('The archive is empty.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array{name:string,dir:bool,size:int,packed:int,method:int,offset:int,crc:int,flags:int} $entry */
    private function extract(array $entry): ?string
    {
        if ($entry['size'] > Updater::MAX_BYTES) {
            return null;
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            fseek($handle, $entry['offset']);
            $local = (string) fread($handle, 30);
            if (substr($local, 0, 4) !== self::LOCAL_SIGNATURE) {
                return null;
            }
            $header = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vnamelen/vextralen', substr($local, 4, 26));
            if ($header === false) {
                return null;
            }
            fseek($handle, $entry['offset'] + 30 + (int) $header['namelen'] + (int) $header['extralen']);
            $raw = (string) fread($handle, $entry['packed'] === 0 ? $entry['size'] : $entry['packed']);
        } finally {
            fclose($handle);
        }

        $data = match ($entry['method']) {
            0 => $raw,
            8 => $this->inflate($raw, $entry['size']),
            default => null,
        };
        if ($data === null) {
            return null;
        }

        // CRC is the cheapest proof that the bytes survived the trip intact.
        $crc = (int) sprintf('%u', crc32($data));
        if ($crc !== (int) sprintf('%u', $entry['crc'])) {
            throw new RuntimeException('The archive entry "' . $entry['name'] . '" is corrupt (CRC mismatch).');
        }

        return $data;
    }

    private function inflate(string $raw, int $expected): ?string
    {
        if (function_exists('gzinflate')) {
            $out = @gzinflate($raw, $expected > 0 ? $expected : 0);
            return is_string($out) ? $out : null;
        }
        if (function_exists('zlib_decode')) {
            $out = @zlib_decode($raw);
            return is_string($out) ? $out : null;
        }
        return null;
    }
}
