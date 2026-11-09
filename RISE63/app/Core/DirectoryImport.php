<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Parses the province's hierarchy out of a pasted list.
 *
 * The office maintains the directory as a sheet: one line per church, three columns —
 * zone, area, parish. Retyping that into three separate forms, one row at a time, is the reason
 * the directory was never finished, so the admin forms accept the list as it already exists:
 *
 *     GRACE ZONE, HOPE AREA, LIVING WATER PARISH
 *     GRACE ZONE, HOPE AREA
 *     GRACE ZONE, HOPE AREA, GOOD SHEPHERD PARISH
 *
 * The shape is deliberately forgiving, because it is pasted from a spreadsheet and typed by
 * people who are not thinking about parsers:
 *
 *   - a comma, a tab, a semicolon or a pipe separates the columns (a paste from Excel is tabs);
 *   - a line with only two columns creates the area and leaves the parish for later;
 *   - one column is a zone and nothing else;
 *   - blank lines, and lines starting with #, are ignored;
 *   - everything is upper-cased and single-spaced, the directory's canonical form.
 *
 * Repeating a zone or an area is the normal case, not an error: two areas under one zone, or two
 * parishes under one area, are written as two lines. Repetition is what makes the hierarchy
 * legible in a plain-text box, and the writer treats a repeat as "this already exists".
 */
final class DirectoryImport
{
    /** A hard ceiling, because this arrives from a textarea. 1000 parishes is ~3× the province. */
    public const MAX_LINES = 1000;
    public const MAX_LINE_LENGTH = 300;

    private const SEPARATORS = '/\s*[,;\t|]\s*/';

    /**
     * @return array{
     *   lines: list<array{number:int, raw:string, zone:string, area:string, parish:string}>,
     *   skipped: list<array{number:int, raw:string, reason:string}>
     * }
     */
    public static function parse(string $text): array
    {
        $lines   = [];
        $skipped = [];
        $number  = 0;

        // Split on any newline convention: a paste from Windows or an old Mac is common.
        foreach (preg_split('/\R/u', $text) ?: [] as $raw) {
            $number++;
            $line = trim($raw);

            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if ($number > self::MAX_LINES) {
                $skipped[] = ['number' => $number, 'raw' => self::clamp($raw), 'reason' => 'Too many lines. Split the list into batches.'];
                continue;
            }
            if (mb_strlen($line) > self::MAX_LINE_LENGTH) {
                $skipped[] = ['number' => $number, 'raw' => self::clamp($raw), 'reason' => 'Line is longer than ' . self::MAX_LINE_LENGTH . ' characters.'];
                continue;
            }

            $columns = array_map(
                static fn (string $part): string => \RISE63\caps($part),
                preg_split(self::SEPARATORS, $line) ?: []
            );

            // A trailing separator ("ZONE, AREA,") yields an empty third column; drop empties so
            // it means the same thing as "ZONE, AREA".
            $columns = array_values(array_filter($columns, static fn (string $part): bool => $part !== ''));

            if ($columns === []) {
                continue;
            }
            if (count($columns) > 3) {
                $skipped[] = [
                    'number' => $number,
                    'raw'    => self::clamp($raw),
                    'reason' => 'More than three columns. A church name containing a comma must not be split — remove the comma or use a tab.',
                ];
                continue;
            }

            $lines[] = [
                'number' => $number,
                'raw'    => self::clamp($raw),
                'zone'   => $columns[0],
                'area'   => $columns[1] ?? '',
                'parish' => $columns[2] ?? '',
            ];
        }

        return ['lines' => $lines, 'skipped' => $skipped];
    }

    /**
     * What the paste would create, without writing anything.
     *
     * Run before the import so the operator sees the actual counts — "3 new areas" is checkable
     * against the sheet; "imported" is not. It also catches a mistyped zone name that would
     * otherwise become a fourth zone nobody meant to create.
     *
     * @param list<array{zone:string, area:string, parish:string}> $lines
     * @param list<string> $existingZones zone names already in the directory
     * @param list<array{zone_name:string, area_name:string}> $existingAreas areas already in it
     * @param list<array{zone_name:string, area_name:string, parish_name:string}> $existingParishes
     * @return array{
     *   zones: list<string>, areas: list<array{zone:string, name:string}>,
     *   parishes: list<array{zone:string, area:string, name:string}>,
     *   new_zones:int, new_areas:int, new_parishes:int,
     *   existing_zones:int, existing_areas:int, existing_parishes:int, duplicates:int
     * }
     */
    public static function summarise(
        array $lines,
        array $existingZones = [],
        array $existingAreas = [],
        array $existingParishes = []
    ): array {
        $zones    = [];
        $areas    = [];
        $parishes = [];
        $duplicates = 0;
        $seenParish = [];

        $knownZones = array_fill_keys(array_map('strval', $existingZones), true);
        $knownAreas = [];
        foreach ($existingAreas as $area) {
            $knownAreas[(string) $area['zone_name'] . "\x1f" . (string) $area['area_name']] = true;
        }
        $knownParishes = [];
        foreach ($existingParishes as $parish) {
            $knownParishes[
                (string) $parish['zone_name'] . "\x1f" . (string) $parish['area_name'] . "\x1f" . (string) $parish['parish_name']
            ] = true;
        }

        foreach ($lines as $line) {
            $zone   = (string) ($line['zone'] ?? '');
            $area   = (string) ($line['area'] ?? '');
            $parish = (string) ($line['parish'] ?? '');

            if ($zone === '') {
                continue;
            }
            $zones[$zone] = true;

            if ($area !== '') {
                $areas[$zone . "\x1f" . $area] = ['zone' => $zone, 'name' => $area];
            }
            if ($area !== '' && $parish !== '') {
                $key = $zone . "\x1f" . $area . "\x1f" . $parish;
                if (isset($seenParish[$key])) {
                    $duplicates++;
                    continue;
                }
                $seenParish[$key] = true;
                $parishes[$key] = ['zone' => $zone, 'area' => $area, 'name' => $parish];
            }
        }

        $newZones = array_values(array_diff(array_keys($zones), array_keys($knownZones)));
        $newAreas = [];
        $newParishes = [];
        foreach ($areas as $key => $area) {
            if (!isset($knownAreas[$key])) {
                $newAreas[] = $area;
            }
        }
        foreach ($parishes as $key => $parish) {
            if (!isset($knownParishes[$key])) {
                $newParishes[] = $parish;
            }
        }

        return [
            'zones'            => array_keys($zones),
            'areas'            => array_values($areas),
            'parishes'         => array_values($parishes),
            'new_zones'        => count($newZones),
            'new_areas'        => count($newAreas),
            'new_parishes'     => count($newParishes),
            'existing_zones'   => count($zones) - count($newZones),
            'existing_areas'   => count($areas) - count($newAreas),
            'existing_parishes' => count($parishes) - count($newParishes),
            'duplicates'       => $duplicates,
        ];
    }

    private static function clamp(string $raw): string
    {
        return mb_substr(trim($raw), 0, 120);
    }
}
