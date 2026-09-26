-- save_enrollment.php inserts guardian_age, but only einstein_mysql_setup.sql
-- ever added the column; databases created before it reject new enrollments.
ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_age TEXT DEFAULT NULL AFTER guardian_name;
