SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE student_blacklist;
TRUNCATE TABLE attendance_records;
TRUNCATE TABLE officer_events;
TRUNCATE TABLE officers;
TRUNCATE TABLE students;
TRUNCATE TABLE printan_records;
TRUNCATE TABLE iskolend_borrow;
TRUNCATE TABLE import_log;
TRUNCATE TABLE users;
TRUNCATE TABLE ecopuntos_records;
TRUNCATE TABLE ecopuntos_balance;

SET FOREIGN_KEY_CHECKS = 1;

-- Keep the item catalog and default settings intact.
-- The CLI tool provides the full reset flow.
