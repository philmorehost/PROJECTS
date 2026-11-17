<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Pure-PHP PKZIP (.zip) Archive Builder.
 * Requires ZERO external PHP extensions (does not require `ext-zip`),
 * and uses byte-exact `hash('crc32b')` so it is 100% identical on 32-bit and 64-bit PHP.
 */
final class ZipBuilder
{
    /** @var list<array{name:string,content:string}> */
    private array $files = [];

    public function addFile(string $pathInZip, string $content): self
    {
        $cleanPath = ltrim(str_replace('\\', '/', $pathInZip), '/');
        $this->files[] = [
            'name'    => $cleanPath,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Build binary `.zip` archive string.
     */
    public function build(): string
    {
        $localData = '';
        $centralDir = '';
        $offset = 0;
        $count = count($this->files);
        $canDeflate = function_exists('gzdeflate');

        // Standard DOS timestamp (2026-01-01 00:00:00)
        $dosTime = 0x0000;
        $dosDate = ((2026 - 1980) << 9) | (1 << 5) | 1;

        foreach ($this->files as $file) {
            $name = $file['name'];
            $raw = $file['content'];
            $uncompLen = strlen($raw);

            // Exact 4-byte little-endian IEEE CRC-32 (immune to 32-bit signed int overflow)
            $crcLe = strrev(hash('crc32b', $raw, true));

            if ($canDeflate && $uncompLen > 64) {
                $compressed = (string) gzdeflate($raw, 6);
                $method = 8; // Deflate
            } else {
                $compressed = $raw;
                $method = 0; // Store
            }
            $compLen = strlen($compressed);
            $nameLen = strlen($name);

            // Local file header (30 bytes + filename + compressed payload)
            $localHeader = "\x50\x4b\x03\x04"
                . pack('v', 20)          // version needed to extract (2.0)
                . pack('v', 0)           // general purpose bit flag
                . pack('v', $method)     // compression method
                . pack('v', $dosTime)    // last mod file time
                . pack('v', $dosDate)    // last mod file date
                . $crcLe                 // crc-32 (4 bytes LE)
                . pack('V', $compLen)    // compressed size
                . pack('V', $uncompLen)  // uncompressed size
                . pack('v', $nameLen)    // file name length
                . pack('v', 0)           // extra field length
                . $name;

            $localEntry = $localHeader . $compressed;
            $localData .= $localEntry;

            // Central directory file header (46 bytes + filename)
            $centralDir .= "\x50\x4b\x01\x02"
                . pack('v', 20)          // version made by
                . pack('v', 20)          // version needed to extract
                . pack('v', 0)           // general purpose bit flag
                . pack('v', $method)     // compression method
                . pack('v', $dosTime)    // last mod time
                . pack('v', $dosDate)    // last mod date
                . $crcLe                 // crc-32 (4 bytes LE)
                . pack('V', $compLen)    // compressed size
                . pack('V', $uncompLen)  // uncompressed size
                . pack('v', $nameLen)    // file name length
                . pack('v', 0)           // extra field length
                . pack('v', 0)           // file comment length
                . pack('v', 0)           // disk number start
                . pack('v', 0)           // internal file attributes
                . pack('V', 32)          // external file attributes (archive)
                . pack('V', $offset)     // relative offset of local header
                . $name;

            $offset += strlen($localEntry);
        }

        // End of central directory record (22 bytes)
        $eocd = "\x50\x4b\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', strlen($centralDir))
            . pack('V', strlen($localData))
            . pack('v', 0);

        return $localData . $centralDir . $eocd;
    }
}
