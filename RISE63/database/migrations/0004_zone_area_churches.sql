-- 0004 — churches at zone, area and parish level
--
-- Reported: 26 areas and 16 zones imported, zero parishes, and a registration page that listed
-- nothing. The directory only ever counted a church as a row in `parishes`, so a zone or an area —
-- where people also worship — was invisible to every picker in the application. And a member could
-- not record "I worship at the zonal church" at all, because `users.parish_id` can only point at a
-- parish.
--
-- This widens the membership of a church to three levels without moving any existing data:
-- `users.parish_id` keeps meaning "a parish", and the two new columns name a zone or area church
-- only when that is what the member chose. A member therefore has exactly one of the three set.
--
-- Both are nullable and unset by default, so every existing account keeps its current meaning.

-- Deliberately no MySQL `AFTER <column>` clause, unlike 0001/0002. It only affects the physical
-- column order, and it makes the statement unparseable anywhere that is not MySQL — including the
-- test harness, where 0001 and 0002 have to be rewritten before they will run. A new migration has
-- no reason to inherit that.
ALTER TABLE users ADD COLUMN zone_id INT NULL;
ALTER TABLE users ADD COLUMN area_id INT NULL;
