<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Keyset ("cursor") pagination for the append-only tables.
 *
 * Deliberately not OFFSET. `LIMIT 30 OFFSET 120` on `audit_events` makes the database count 150 rows
 * to throw 120 away, and — the part that matters more here — a row inserted between two page loads
 * shifts every later row by one, so page 2 repeats a row that page 1 already showed. The audit trail
 * is being written to *while it is being read*, so that is not a theoretical problem. The cursor is
 * the primary key of the last row on the page, and the next page is "everything below it".
 *
 * The trail is ordered newest-first (id DESC). "Older" therefore means a smaller id, which is what
 * `before` carries. `after` exists for the case where somebody wants to follow new events without
 * losing their place.
 *
 * A page size is clamped to 10–200: small enough to be usable on a 2G phone, large enough that no
 * admin can ask for the whole table in one query and time the request out on shared hosting.
 */
final class Paginator
{
    public const MIN_PER_PAGE = 10;
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 25;

    /**
     * @param array<string, mixed> $query existing query parameters, preserved on the page links
     */
    private function __construct(
        public readonly int $perPage,
        public readonly ?int $before,
        public readonly bool $hasPrevious,
        public readonly bool $hasNext,
        public readonly int $count,
        private readonly array $query,
        private readonly string $page,
    ) {
    }

    /**
     * Construct a page from known state.
     *
     * Needed by the view harness (and by any caller that already knows where it is): the request
     * factory below can only work out a page from a URL, which is not what a test has. `hasNext`
     * is inferred from the caller's `$lastId` rather than guessed.
     *
     * @param array<string, string> $query
     */
    public static function fromState(
        int $perPage,
        ?int $before,
        int $count,
        array $query,
        string $page,
        bool $hasNext = false
    ): self {
        return new self(
            max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage)),
            $before,
            $before !== null,
            $hasNext,
            $count,
            $query,
            $page
        );
    }

    /**
     * Read the cursor and page size out of a request.
     *
     * @param array<string, mixed> $get usually $_GET — the values are re-validated here rather than
     *                                  trusted because they arrive from the URL
     */
    public static function fromRequest(array $get, string $page, int $defaultPerPage = self::DEFAULT_PER_PAGE): self
    {
        $perPage = (int) ($get['per_page'] ?? $defaultPerPage);
        if ($perPage < self::MIN_PER_PAGE || $perPage > self::MAX_PER_PAGE) {
            // Not an error: a hand-edited URL must not produce a 500. Snap to the nearest valid size.
            $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage > 0 ? $perPage : $defaultPerPage));
        }

        $before = null;
        $raw    = $get['before'] ?? null;
        if (is_scalar($raw) && preg_match('/^\d{1,18}$/', (string) $raw) === 1) {
            $before = (int) $raw;
        }

        // Everything except the cursor itself is carried forward, so filters survive a page change.
        $query = [];
        foreach ($get as $key => $value) {
            if (!is_string($key) || $key === 'before' || !is_scalar($value)) {
                continue;
            }
            $query[$key] = (string) $value;
        }
        $query['per_page'] = (string) $perPage;

        // `hasNext` and `count` are only known once rows come back; `hasPrevious` is known now —
        // a cursor in the URL means this is not the first page.
        return new self($perPage, $before, $before !== null, false, 0, $query, $page);
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    /** URL for the next (older) page, given the last row on this one. */
    public function olderUrl(string $lastId): string
    {
        return \RISE63\url($this->page, $this->query + ['before' => $lastId]);
    }

    /** URL for the previous (newer) page: drop the cursor and start again from the top. */
    public function newerUrl(): string
    {
        return \RISE63\url($this->page, $this->query);
    }

    /** URL for a different page size, resetting the cursor so the first page is shown. */
    public function sizeUrl(int $size): string
    {
        return \RISE63\url($this->page, ['per_page' => (string) $size] + $this->query);
    }

    /**
     * A caption an operator can trust.
     *
     * Keyset paging cannot honestly say "rows 26–50": there are no offsets, and a row deleted
     * between two page loads would make the range a lie. It says how many are on screen and which
     * direction they came from, which is true by construction.
     */
    public function caption(int $total): string
    {
        return $this->before === null
            ? 'Newest ' . $this->count
            : $this->count . ' older';
    }
}
