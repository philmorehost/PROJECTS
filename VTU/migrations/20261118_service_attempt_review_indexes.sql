-- Apply once via a versioned operator migration outside application transactions.
-- Schedule/index-build capacity and backups must be reviewed for large existing tables.
ALTER TABLE sas_service_requests ADD KEY idx_vendor_request_review (vendor_id,id);
ALTER TABLE sas_service_attempt_events ADD KEY idx_attempt_review_flags (vendor_id,request_id,disposition);
ALTER TABLE sas_service_attempt_events ADD KEY idx_attempt_review_timeline (vendor_id,request_id,created_at,event_key);
