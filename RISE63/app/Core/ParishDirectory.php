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
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $sql .= ' AND (p.parish_name LIKE ? OR a.area_name LIKE ? OR z.zone_name LIKE ? OR p.parish_code LIKE ?)';
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
        Database::run('INSERT INTO zones (zone_name, zone_code) VALUES (?,?)', [$name, $code]);
        $id = Database::id();
        Audit::log($actorId, 'zone.create', 'zone', (string) $id, $name);
        return $id;
    }

    public static function createArea(int $zoneId, string $name, ?string $code, ?int $actorId): int
    {
        if (Database::value('SELECT id FROM zones WHERE id = ?', [$zoneId]) === null) {
            throw new InvalidArgumentException('Choose an existing zone.');
        }
        $name = self::cleanName($name, 'area');
        $code = self::cleanCode($code);
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
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $sql .= ' AND (p.parish_name LIKE ? OR a.area_name LIKE ? OR z.zone_name LIKE ?)';
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

    private static function cleanName(string $name, string $what): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
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
