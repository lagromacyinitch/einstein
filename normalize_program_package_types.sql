-- Normalize legacy labels without changing package names or rates.
UPDATE program_packages
SET package_type = 'playschool'
WHERE LOWER(REPLACE(TRIM(program_name), ' ', '')) IN ('playschool', 'playschoolprogram')
  AND (package_type IS NULL OR LOWER(TRIM(package_type)) IN ('', 'general'));

UPDATE program_packages
SET package_type = 'workshop'
WHERE LOWER(REPLACE(TRIM(program_name), ' ', '')) IN ('workshop', 'weekendworkshop')
  AND (package_type IS NULL OR LOWER(TRIM(package_type)) IN ('', 'general'));
