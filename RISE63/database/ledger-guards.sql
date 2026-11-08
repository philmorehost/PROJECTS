-- RISE 63 — ledger guards.
-- remittance_ledger is append-only: the application never issues UPDATE or DELETE,
-- and these triggers make that guarantee structural rather than conventional.
-- Apply after schema.sql. MariaDB 10.6 and MySQL 8 both accept this form.

CREATE TRIGGER trg_ledger_no_update
BEFORE UPDATE ON remittance_ledger
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'remittance_ledger is append-only';

CREATE TRIGGER trg_ledger_no_delete
BEFORE DELETE ON remittance_ledger
FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'remittance_ledger is append-only';
