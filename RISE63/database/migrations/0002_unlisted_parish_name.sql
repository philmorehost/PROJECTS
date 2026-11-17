-- 0002 — a member's church when it is not in the directory yet.
--
-- Registration used to be all-or-nothing: pick a parish from the directory, or be left with no
-- parish at all. The directory is still being assembled, so most churches a member belongs to are
-- not in it yet, and "I am not sure yet" was the only honest answer available — which means the
-- office could not tell the difference between a member who skipped the question and a member whose
-- church simply needs adding.
--
-- NULL means "they picked from the list, or said nothing". A value means "they typed this church
-- name because it was not listed", already upper-cased by the application. The name is deliberately
-- free text and deliberately *not* a foreign key: it is a request to add a parish, not a parish.
--
-- Safe on a live database: nullable, no default, added at the end of the table (instant on MySQL 8),
-- and no existing row changes meaning — a NULL here reads exactly like today's behaviour.

ALTER TABLE users ADD COLUMN parish_name_raw VARCHAR(150) NULL AFTER parish_id;
