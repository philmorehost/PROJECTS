-- One-time operator migration before deploying the new DVA forwarding/verification code.
-- Check SHOW COLUMNS first; skip if receiving_account_number already exists.
-- Do NOT populate this column from merchant metadata, email patterns or historical guesses.
ALTER TABLE transactions ADD COLUMN receiving_account_number VARCHAR(10) DEFAULT NULL;
