-- Super Admin role: run ONCE on an existing database (phpMyAdmin → SQL, or: mysql -u USER -p DBNAME < sql/migrate_super_admin.sql).
-- New installs already get this from schema.sql, and the application also does it automatically the first time it connects
-- (if the database user may alter tables). It only widens the role column so 'super_admin' fits; no rows are changed, so
-- every existing account keeps its current role. Nobody becomes a Super Admin by this script.
-- Then designate the Super Admin on the server:  php tools/create_super_admin.php <existing-username>
SET NAMES utf8mb4;

ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'viewer';
