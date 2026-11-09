<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * Zone → Area → Parish directory with a single safe write path.
 *
 * Because the hierarchy is three tables rather than one denormalised row, a parish cannot
 * drift into two different zones, and renaming an area never needs a bulk UPDATE.
 */
final class ParishDirectory
{
    /** @return list<array<string, mixed>> */
    public static function zones(): array
    {
        return Database::all(
            'SELECT z.*, (SELECT COUNT(*) FROM areas a WHERE a.zone_id = z.id) AS area_count,
                    (SELECT COUNT(*) FROM parishes p JOIN areas a2 ON a2.id = p.area_id WHERE a2.zone_id = z.id) AS parish_count
             FROM zones z ORDER BY z.zone_name'
        );
    }

    /** @return list<array<string, mixed>> */
    public static function areas(?int $zoneId = null): array
    {
        if ($zoneId !== null) {
            return Database::all(
                'SELECT a.*, z.zone_name, (SELECT COUNT(*) FROM parishes p WHERE p.area_id = a.id) AS parish_count
                 FROM areas a JOIN zones z ON z.id = a.zone_id WHERE a.zone_id = ? ORDER BY a.area_name',
                [$zoneId]
            );
        }
        return Database::all(
            'SELECT a.*, z.zone_name, (SELECT COUNT(*) FROM parishes p WHERE p.area_id = a.id) AS parish_count
             FROM areas a JOIN zones z ON z.id = a.zone_id ORDER BY z.zone_name, a.area_name'
        );
    }

    /**
     * Flat list used by the live-search combobox. Capped so a 2G phone never downloads
     * the whole directory by accident; filtering happens server-side.
     *
     * @return list<array{id:int, label:string, parish:string, area:string, zone:string, code:?string}>
     */
    public static function search(string $term = '', int $limit = 60): array
    {
        $term  = trim($term);
        $limit = max(1, min(200, $limit));
        $sql   = 'SELECT p.id, p.parish_name, p.parish_code, a.area_name, z.zone_name
                  FROM parishes p JOIN areas a ON a.id = p.area_id JOIN zones z ON z.id = a.zone_id
                  WHERE p.is_active = 1';
        $params = [];
        if ($term !== '') {
            // UPPER() on both sides so the search works whatever the column collation is, and
            // whatever case the member types. Names are stored in capitals; people do not type
            // in capitals.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], \RISE63\caps($term)) . '%';
            $sql .= ' AND (UPPER(p.parish_name) LIKE ? OR UPPER(a.area_name) LIKE ? OR UPPER(z.zone_name) LIKE ? OR UPPER(COALESCE(p.parish_code, \'\')) LIKE ?)';
            $params = [$like, $like, $like, $like];
        }
        $sql .= " ORDER BY z.zone_name, a.area_name, p.parish_name LIMIT {$limit}";
        $rows = Database::all($sql, $params);

        return array_map(static fn (array $row): array => [
            'id'     => (int) $row['id'],
            'parish' => (string) $row['parish_name'],
            'area'   => (string) $row['area_name'],
            'zone'   => (string) $row['zone_name'],
            'code'   => $row['parish_code'] === null ? null : (string) $row['parish_code'],
            'label'  => $row['parish_name'] . ' · ' . $row['area_name'] . ' · ' . $row['zone_name'],
        ], $rows);
    }

    public static function parish(int $id): ?array
    {
        return Database::one(
            'SELECT p.*, a.area_name, a.id AS area_id, z.zone_name, z.id AS zone_id
             FROM parishes p JOIN areas a ON a.id = p.area_id JOIN zones z ON z.id = a.zone_id
             WHERE p.id = ?',
            [$id]
        );
    }

    public static function createZone(string $name, ?string $code, ?int $actorId): int
    {
        $name = self::cleanName($name, 'zone');
        $code = self::cleanCode($code);
        if (Database::value('SELECT id FROM zones WHERE UPPER(zone_name) = ? LIMIT 1', [$name]) !== null) {
            throw new InvalidArgumentException('The zone “' . $name . '” already exists.');
        }
        Database::run('INSERT INTO zones (zone_name, zone_code) VALUES (?,?)', [$name, $code]);
        $id = Database::id();
        Audit::log($actorId, 'zone.create', 'zone', (string) $id, $name);
        return $id;
    }

    public static function createArea(int $zoneId, string $name, ?string $code, ?int $actorId): int
    {
        $zone = Database::one('SELECT zone_name FROM zones WHERE id = ?', [$zoneId]);
        if ($zone === null) {
            throw new InvalidArgumentException('Choose an existing zone.');
        }
        $name = self::cleanName($name, 'area');
        $code = self::cleanCode($code);
        if (Database::value('SELECT id FROM areas WHERE zone_id = ? AND UPPER(area_name) = ? LIMIT 1', [$zoneId, $name]) !== null) {
            throw new InvalidArgumentException('“' . $name . '” already exists under ' . (string) $zone['zone_name'] . '.');
        }
        Database::run('INSERT INTO areas (zone_id, area_name, area_code) VALUES (?,?,?)', [$zoneId, $name, $code]);
        $id = Database::id();
        Audit::log($actorId, 'area.create', 'area', (string) $id, $name);
        return $id;
    }

    /**
     * @param array{parish_name:string, area_id:int, parish_code?:?string, worship_address?:string,
     *              yaya_rep_name?:string, yaya_rep_phone?:string, youth_count?:int|string, needs_summary?:string} $data
     */
    public static function createParish(array $data, ?int $actorId): int
    {
        $name   = self::cleanName((string) ($data['parish_name'] ?? ''), 'parish');
        $areaId = (int) ($data['area_id'] ?? 0);
        if (Database::value('SELECT id FROM areas WHERE id = ?', [$areaId]) === null) {
            throw new InvalidArgumentException('Choose an existing area for this parish.');
        }
        $code  = self::cleanCode($data['parish_code'] ?? null);
        $youth = (int) ($data['youth_count'] ?? 0);
        if ($youth < 0 || $youth > 100000) {
            throw new InvalidArgumentException('Enter a realistic youth population.');
        }
        if (Database::value('SELECT id FROM parishes WHERE area_id = ? AND UPPER(parish_name) = ? LIMIT 1', [$areaId, $name]) !== null) {
            throw new InvalidArgumentException('“' . $name . '” is already listed under that area. Open it to update its details.');
        }
        $phone = trim((string) ($data['yaya_rep_phone'] ?? ''));
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Enter a valid representative phone number.');
        }
        Database::run(
            'INSERT INTO parishes (area_id, parish_name, parish_code, worship_address, yaya_rep_name, yaya_rep_phone, youth_count, needs_summary)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $areaId,
                $name,
                $code,
                self::cleanText($data['worship_address'] ?? '', 255),
                self::cleanText($data['yaya_rep_name'] ?? '', 150),
                $phone === '' ? null : $phone,
                $youth,
                self::cleanText($data['needs_summary'] ?? '', 2000),
            ]
        );
        $id = Database::id();
        Audit::log($actorId, 'parish.create', 'parish', (string) $id, $name);
        return $id;
    }

    public static function updateParish(int $id, array $data, ?int $actorId): void
    {
        $existing = self::parish($id);
        if ($existing === null) {
            throw new NotFound('That parish no longer exists.');
        }
        $name   = self::cleanName((string) ($data['parish_name'] ?? $existing['parish_name']), 'parish');
        $areaId = (int) ($data['area_id'] ?? $existing['area_id']);
        if (Database::value('SELECT id FROM areas WHERE id = ?', [$areaId]) === null) {
            throw new InvalidArgumentException('Choose an existing area for this parish.');
        }
        $youth = (int) ($data['youth_count'] ?? $existing['youth_count']);
        if ($youth < 0 || $youth > 100000) {
            throw new InvalidArgumentException('Enter a realistic youth population.');
        }
        Database::run(
            'UPDATE parishes SET area_id = ?, parish_name = ?, parish_code = ?, worship_address = ?, yaya_rep_name = ?,
                    yaya_rep_phone = ?, youth_count = ?, needs_summary = ?, is_active = ? WHERE id = ?',
            [
                $areaId,
                $name,
                self::cleanCode($data['parish_code'] ?? $existing['parish_code']),
                self::cleanText($data['worship_address'] ?? $existing['worship_address'], 255),
                self::cleanText($data['yaya_rep_name'] ?? $existing['yaya_rep_name'], 150),
                self::cleanText($data['yaya_rep_phone'] ?? $existing['yaya_rep_phone'], 30) ?: null,
                $youth,
                self::cleanText($data['needs_summary'] ?? $existing['needs_summary'], 2000),
                !empty($data['is_active']) ? 1 : (isset($data['is_active']) ? 0 : (int) $existing['is_active']),
                $id,
            ]
        );
        Audit::log($actorId, 'parish.update', 'parish', (string) $id, $name);
    }

    /** Directory rows for the Parish Connect grid, scoped for a CSR representative. */
    public static function directory(array $user, string $term = ''): array
    {
        $sql = 'SELECT p.*, a.area_name, z.zone_name,
                       (SELECT COUNT(*) FROM users u WHERE u.parish_id = p.id) AS account_count
                FROM parishes p JOIN areas a ON a.id = p.area_id JOIN zones z ON z.id = a.zone_id WHERE 1=1';
        $params = [];
        if ((string) $user['role'] === 'csr_rep' && $user['parish_id'] !== null) {
            $sql .= ' AND p.id = ?';
            $params[] = (int) $user['parish_id'];
        }
        $term = trim($term);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], \RISE63\caps($term)) . '%';
            $sql .= ' AND (UPPER(p.parish_name) LIKE ? OR UPPER(a.area_name) LIKE ? OR UPPER(z.zone_name) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sql .= ' ORDER BY z.zone_name, a.area_name, p.parish_name LIMIT 300';
        return Database::all($sql, $params);
    }

    public static function totals(): array
    {
        return [
            'zones'    => (int) Database::value('SELECT COUNT(*) FROM zones'),
            'areas'    => (int) Database::value('SELECT COUNT(*) FROM areas'),
            'parishes' => (int) Database::value('SELECT COUNT(*) FROM parishes WHERE is_active = 1'),
            'youth'    => (int) Database::value('SELECT COALESCE(SUM(youth_count),0) FROM parishes WHERE is_active = 1'),
        ];
    }

    /**
     * How many parishes the public can actually choose, and — when that is none — why.
     *
     * Reported: the registration page listed no churches. The page was rendering correctly; the
     * directory behind it was empty, and nothing anywhere said so. The member saw a picker that
     * answered "No parish matches" to every word they typed, which reads as a broken search, and
     * the administrator had no screen that said "you have not added any churches yet".
     *
     * This is the one place that answers the question precisely, because "empty" and "populated but
     * invisible" are different problems with different cures:
     *
     *   - **empty**      nothing has been added. Cure: paste the list in Parish Connect.
     *   - **inactive**   churches exist and every one is switched off. Cure: re-activate them.
     *   - **orphaned**   parishes exist but their area or zone row is gone. Unreachable, and the
     *                    only real corruption of the three; the cure is a re-import.
     *   - **ok**         the picker has something to show.
     *
     * Queried defensively: this is used to explain a fault, so it must never be able to become one.
     *
     * @return array{state:string, active:int, total:int, inactive:int, orphaned:int, message:string}
     */
    public static function pickerState(): array
    {
        $out = ['state' => 'ok', 'active' => 0, 'total' => 0, 'inactive' => 0, 'orphaned' => 0, 'message' => ''];

        try {
            $total = (int) Database::value('SELECT COUNT(*) FROM parishes');
            $active = (int) Database::value('SELECT COUNT(*) FROM parishes p WHERE p.is_active = 1');
            // Parishes the picker cannot show because a join drops them.
            $orphaned = (int) Database::value(
                'SELECT COUNT(*) FROM parishes p
                  WHERE NOT EXISTS (SELECT 1 FROM areas a WHERE a.id = p.area_id)
                     OR EXISTS (SELECT 1 FROM areas a WHERE a.id = p.area_id
                                AND NOT EXISTS (SELECT 1 FROM zones z WHERE z.id = a.zone_id))'
            );
            $reachable = (int) Database::value(
                'SELECT COUNT(*) FROM parishes p
                   JOIN areas a ON a.id = p.area_id
                   JOIN zones z ON z.id = a.zone_id
                  WHERE p.is_active = 1'
            );
        } catch (\Throwable $error) {
            // A directory that cannot be read must not stop a page rendering; say nothing rather
            // than claim the directory is empty when it may simply be unavailable.
            $out['state'] = 'unknown';
            return $out;
        }

        $out['total']    = $total;
        $out['active']   = $active;
        $out['inactive'] = max(0, $total - $active);
        $out['orphaned'] = $orphaned;

        if ($reachable > 0) {
            return $out;                       // a populated picker needs no explanation
        }

        if ($total === 0) {
            $out['state']   = 'empty';
            $out['message'] = 'No churches have been added to the directory yet.';
        } elseif ($active === 0) {
            $out['state']   = 'inactive';
            $out['message'] = 'All ' . $total . ' church(es) in the directory are switched off.';
        } elseif ($orphaned > 0) {
            $out['state']   = 'orphaned';
            $out['message'] = $orphaned . ' church(es) belong to an area or zone that no longer exists.';
        } else {
            $out['state']   = 'empty';
            $out['message'] = 'No church in the directory is reachable by the picker.';
        }

        return $out;
    }

    /** True when the public registration picker has nothing to offer. */
    public static function pickerIsEmpty(): bool
    {
        return self::pickerState()['state'] !== 'ok';
    }

    /**
     * Write a parsed list, creating only what is missing.
     *
     * A repeat is not an error here, and that is the whole point of the format: an area with four
     * parishes is four lines that all start with the same zone and area. So every step looks the
     * zone up before creating it, then the area, then the parish, matching on the canonical
     * upper-case name — "Hope Area" pasted today and "HOPE AREA" typed last month are one row.
     *
     * The whole run is one transaction. A paste that fails halfway would otherwise leave three
     * orphan areas and no way to tell which lines were done, and the operator would be re-pasting
     * into a half-applied hierarchy.
     *
     * @param list<array{zone:string, area:string, parish:string}> $lines
     * @return array{created:int, existing:int, zones:int, areas:int, parishes:int}
     */
    public static function importLines(array $lines, ?int $actorId): array
    {
        $tally = ['created' => 0, 'existing' => 0, 'zones' => 0, 'areas' => 0, 'parishes' => 0];

        Database::transaction(function () use ($lines, $actorId, &$tally): void {
            foreach ($lines as $line) {
                $zoneName   = \RISE63\caps((string) ($line['zone'] ?? ''));
                $areaName   = \RISE63\caps((string) ($line['area'] ?? ''));
                $parishName = \RISE63\caps((string) ($line['parish'] ?? ''));

                if ($zoneName === '') {
                    continue;
                }

                [$zoneId, $zoneNew] = self::ensureZone($zoneName, $actorId);
                $tally['zones']++;
                $zoneNew ? $tally['created']++ : $tally['existing']++;

                if ($areaName === '') {
                    continue;
                }
                [$areaId, $areaNew] = self::ensureArea($zoneId, $areaName, $actorId);
                $tally['areas']++;
                $areaNew ? $tally['created']++ : $tally['existing']++;

                if ($parishName === '') {
                    // "ZONE, AREA" is a legitimate line: the area exists, its parishes are added
                    // later, and the admin should not have to come back to a different form.
                    continue;
                }
                [, $parishNew] = self::ensureParish($areaId, $parishName, $actorId);
                $tally['parishes']++;
                $parishNew ? $tally['created']++ : $tally['existing']++;
            }
        });

        Audit::log(
            $actorId,
            'directory.import',
            'directory',
            null,
            sprintf(
                '%d line(s): %d created, %d already present (%d zones, %d areas, %d parishes touched).',
                count($lines),
                $tally['created'],
                $tally['existing'],
                $tally['zones'],
                $tally['areas'],
                $tally['parishes']
            )
        );

        return $tally;
    }

    /**
     * The zone's id, creating it when missing.
     *
     * The comparison is done in SQL with UPPER() rather than relying on the collation: the column
     * is utf8mb4_*_ci on a stock install, but not on every host, and a duplicate zone is a
     * constraint violation the operator would see as "the import failed".
     *
     * @return array{0:int, 1:bool} id, created-now
     */
    private static function ensureZone(string $name, ?int $actorId): array
    {
        $id = Database::value('SELECT id FROM zones WHERE UPPER(zone_name) = ? LIMIT 1', [$name]);
        if ($id !== null) {
            return [(int) $id, false];
        }
        Database::run('INSERT INTO zones (zone_name, zone_code) VALUES (?, NULL)', [$name]);
        $id = Database::id();
        Audit::log($actorId, 'zone.create', 'zone', (string) $id, $name);
        return [$id, true];
    }

    /** @return array{0:int, 1:bool} */
    private static function ensureArea(int $zoneId, string $name, ?int $actorId): array
    {
        $id = Database::value(
            'SELECT id FROM areas WHERE zone_id = ? AND UPPER(area_name) = ? LIMIT 1',
            [$zoneId, $name]
        );
        if ($id !== null) {
            return [(int) $id, false];
        }
        Database::run('INSERT INTO areas (zone_id, area_name, area_code) VALUES (?,?,NULL)', [$zoneId, $name]);
        $id = Database::id();
        Audit::log($actorId, 'area.create', 'area', (string) $id, $name);
        return [$id, true];
    }

    /** @return array{0:int, 1:bool} */
    private static function ensureParish(int $areaId, string $name, ?int $actorId): array
    {
        $id = Database::value(
            'SELECT id FROM parishes WHERE area_id = ? AND UPPER(parish_name) = ? LIMIT 1',
            [$areaId, $name]
        );
        if ($id !== null) {
            return [(int) $id, false];
        }
        Database::run(
            'INSERT INTO parishes (area_id, parish_name, youth_count) VALUES (?,?,0)',
            [$areaId, $name]
        );
        $id = Database::id();
        Audit::log($actorId, 'parish.create', 'parish', (string) $id, $name);
        return [$id, true];
    }

    /**
     * Existing names, for the import preview.
     *
     * @return array{zones:list<string>, areas:list<array{zone_name:string, area_name:string}>,
     *               parishes:list<array{zone_name:string, area_name:string, parish_name:string}>}
     */
    public static function existingNames(): array
    {
        return [
            'zones' => array_map(
                static fn (array $row): string => (string) $row['zone_name'],
                Database::all('SELECT zone_name FROM zones')
            ),
            'areas' => Database::all(
                'SELECT z.zone_name, a.area_name FROM areas a JOIN zones z ON z.id = a.zone_id'
            ),
            'parishes' => Database::all(
                'SELECT z.zone_name, a.area_name, p.parish_name
                 FROM parishes p JOIN areas a ON a.id = p.area_id JOIN zones z ON z.id = a.zone_id'
            ),
        ];
    }

    /**
     * The parish whose name matches, ignoring case, or null.
     *
     * Used when a member types a church name instead of picking one: if the directory already has
     * it, the account is attached to the real row rather than left dangling as an unlisted name.
     * The match is exact on the canonical form — not a LIKE — so "GRACE" never silently resolves
     * to "GRACE CHAPEL".
     *
     * @return array<string, mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        $name = \RISE63\caps($name);
        if ($name === '') {
            return null;
        }
        $id = Database::value('SELECT id FROM parishes WHERE UPPER(parish_name) = ? LIMIT 1', [$name]);
        return $id === null ? null : self::parish((int) $id);
    }

    private static function cleanName(string $name, string $what): string
    {
        // Every church name is stored in the canonical form, whatever the caller typed and
        // whatever the form did. One rule, one place: the directory reads as one list.
        $name = \RISE63\caps($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Enter a ' . $what . ' name of 150 characters or fewer.');
        }
        if (preg_match('/[\x00-\x1F<>]/', $name) === 1) {
            throw new InvalidArgumentException('That name contains characters that are not allowed.');
        }
        return $name;
    }

    private static function cleanCode(mixed $code): ?string
    {
        $code = strtoupper(trim((string) ($code ?? '')));
        if ($code === '') {
            return null;
        }
        if (preg_match('/^[A-Z0-9-]{2,20}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Codes use 2–20 letters, digits or hyphens.');
        }
        return $code;
    }

    private static function cleanText(mixed $value, int $max): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }
        return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '', 0, $max);
    }
}
