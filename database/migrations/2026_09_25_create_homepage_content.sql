-- Editable homepage copy, grouped by page section.
-- The application also runs this CREATE TABLE safely when the content API is used.
CREATE TABLE IF NOT EXISTS homepage_content (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  content_key    VARCHAR(191) NOT NULL UNIQUE,
  section_name   VARCHAR(120) NOT NULL,
  field_label    VARCHAR(255) NOT NULL,
  content_type   VARCHAR(20) NOT NULL DEFAULT 'text',
  content_value  LONGTEXT NOT NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_homepage_section (section_name, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
