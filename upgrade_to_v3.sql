-- Compatibility wrapper for legacy deployment instructions.
--
-- This project now keeps the portable, version-safe upgrade statements in `migration.sql`.
-- Running this file through the MySQL client will execute `migration.sql` from the same directory.
--
-- Example:
--   mysql -u <user> -p <database> < upgrade_to_v3.sql

SOURCE migration.sql;
