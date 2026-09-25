-- ═══════════════════════════════════════════════════════════════════
--  EINSTEIN CENTER FOR MODERN EDUCATION
--  MySQL Database Setup — Full Schema + Seed Data
--  Usage:  mysql -u root -p < einstein_mysql_setup.sql
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. CREATE DATABASE ──────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS einstein_center
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE einstein_center;

-- ── 2. USERS (AES-256 ENCRYPTED FIELDS AT REST) ────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id                 INT UNSIGNED    PRIMARY KEY AUTO_INCREMENT,
  email              VARCHAR(255)    NOT NULL,
  email_encrypted    TEXT            DEFAULT NULL,
  email_hash         VARCHAR(64)     DEFAULT NULL,
  username           TEXT            DEFAULT NULL,
  username_hash      VARCHAR(64)     DEFAULT NULL,
  password_hash      VARCHAR(255)    NOT NULL,
  password_encrypted TEXT            DEFAULT NULL,
  firstname          TEXT            DEFAULT NULL,
  lastname           TEXT            DEFAULT NULL,
  middlename         TEXT            DEFAULT NULL,
  birthdate          TEXT            DEFAULT NULL,
  address            TEXT            DEFAULT NULL,
  contact_number     TEXT            DEFAULT NULL,
  is_verified        TINYINT(1)      NOT NULL DEFAULT 0,
  role               VARCHAR(20)     NOT NULL DEFAULT 'user',
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email_hash (email_hash),
  INDEX idx_username_hash (username_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. OTP CODES ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS otp_codes (
  id         INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT,
  email      VARCHAR(255)  NOT NULL,
  otp_code   VARCHAR(10)   NOT NULL,
  expires_at TIMESTAMP     NOT NULL,
  attempts   TINYINT       NOT NULL DEFAULT 0,
  is_used    TINYINT(1)    NOT NULL DEFAULT 0,
  channel    VARCHAR(10)   NOT NULL DEFAULT 'email',
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_used (email, is_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- If otp_codes already exists without channel column, run this:
ALTER TABLE otp_codes ADD COLUMN IF NOT EXISTS channel VARCHAR(10) NOT NULL DEFAULT 'email';

-- Password reset codes are kept separate from signup verification OTPs.
CREATE TABLE IF NOT EXISTS password_reset_codes (
  id         INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT,
  user_id    INT UNSIGNED  NOT NULL,
  email_hash VARCHAR(64)   NOT NULL,
  otp_code   VARCHAR(10)   NOT NULL,
  expires_at TIMESTAMP     NOT NULL,
  attempts   TINYINT       NOT NULL DEFAULT 0,
  is_used    TINYINT(1)    NOT NULL DEFAULT 0,
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_user (user_id, is_used),
  INDEX idx_password_reset_lookup (email_hash, is_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. ENROLLMENTS (AES-256 ENCRYPTED PERSONAL DATA) ───────────────
CREATE TABLE IF NOT EXISTS enrollments (
  id                 INT UNSIGNED   PRIMARY KEY AUTO_INCREMENT,
  reference_no       VARCHAR(20)    NOT NULL UNIQUE,
  user_id            INT UNSIGNED   NULL,

  -- Program Details
  program            VARCHAR(100)   NOT NULL,
  package_selected   VARCHAR(255)   DEFAULT NULL,
  start_date         DATE           DEFAULT NULL,
  timeslot           VARCHAR(100)   DEFAULT NULL,

  -- Child Information (AES-256 Encrypted)
  child_name         TEXT           NOT NULL,
  child_age          TEXT           DEFAULT NULL,
  child_grade        TEXT           DEFAULT NULL,
  child_school       TEXT           DEFAULT NULL,

  -- Guardian / Parent Information (AES-256 Encrypted)
  guardian_name      TEXT           NOT NULL,
  address            TEXT           DEFAULT NULL,
  contact            TEXT           DEFAULT NULL,
  facebook_name      TEXT           DEFAULT NULL,

  -- Payment
  payment_screenshot VARCHAR(500)   DEFAULT NULL,
  payment_method     VARCHAR(50)    DEFAULT NULL,
  payment_status     ENUM('pending','confirmed','rejected')
                                    NOT NULL DEFAULT 'pending',

  -- Enrollment Status
  status             ENUM('pending','confirmed','cancelled')
                                    NOT NULL DEFAULT 'pending',
  admin_notes        TEXT           DEFAULT NULL,
  notes              TEXT           DEFAULT NULL,

  created_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_enrollment_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

  INDEX idx_reference  (reference_no),
  INDEX idx_user       (user_id),
  INDEX idx_status     (status),
  INDEX idx_created    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installations need this upgrade too: CREATE TABLE IF NOT EXISTS
-- does not add columns to an already-created table.
ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS admin_notes TEXT DEFAULT NULL;

-- ── 5. TUTORS ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tutors (
  id           INT UNSIGNED    PRIMARY KEY AUTO_INCREMENT,
  full_name    VARCHAR(255)    NOT NULL,
  email        VARCHAR(255)    DEFAULT NULL,
  phone        VARCHAR(50)     DEFAULT NULL,
  hourly_rate  DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  subjects     TEXT            DEFAULT NULL,
  schedule     VARCHAR(500)    DEFAULT NULL,
  is_active    TINYINT(1)      NOT NULL DEFAULT 1,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. PROGRAM PACKAGES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS program_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_name VARCHAR(100) NOT NULL,
  package_name VARCHAR(150) NOT NULL,
  care_duration VARCHAR(100) DEFAULT NULL,
  rate VARCHAR(100) NOT NULL,
  capacity_slots VARCHAR(100) DEFAULT NULL,
  package_type VARCHAR(50) DEFAULT 'general',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pp_program (program_name),
  INDEX idx_pp_type (package_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data for All 5 Programs (Academic Tutorial, Child Care, Workshop, PlaySchool, M.A.D. Studio)
INSERT IGNORE INTO program_packages (id, program_name, package_name, care_duration, rate, capacity_slots, package_type) VALUES
(1, 'Academic Tutorial', 'Regular Package', 'One Tutor for One Child · 15 sessions', '₱3,300', NULL, 'tutorial'),
(2, 'Academic Tutorial', 'Double Package', 'One Tutor for 1 or 2 Kids · 15 sessions', '₱5,500', NULL, 'tutorial'),
(3, 'Academic Tutorial', 'Daily Package', 'Per month · 1 hour/day', '₱5,500', NULL, 'tutorial'),
(4, 'Academic Tutorial', 'Daily Double Package', 'Per month · 2 hours/day', '₱9,900', NULL, 'tutorial'),
(5, 'Child Care', 'Daily Package', 'Full Day (8 hrs)', '₱800/day', 'Unlimited', 'childcare'),
(6, 'Child Care', 'Weekly Package', '5 Days (Full)', '₱3,500/wk', '8 slots', 'childcare'),
(7, 'Child Care', 'Monthly Package', 'Full Month (Care)', '₱12,000/mo', '3 slots available', 'childcare'),
(8, 'Workshop', 'Group Subscription', 'Saturdays Only (Aug–Jan)', '₱2,500/mo', 'Up to 10 students/class', 'workshop'),
(9, 'Workshop', 'Center Based', '10 sessions (1 hr each)', '₱4,800', '1 instructor to 1 student', 'workshop'),
(10, 'Workshop', 'Home Based', '10 sessions (1 hr each)', '₱4,500 + transpo', '1 instructor to 1 student', 'workshop'),
(12, 'PlaySchool', 'Caterpillar', '2-3 yrs', '₱4,875/mo', 'Morning session', 'playschool'),
(13, 'PlaySchool', 'Butterfly', '3-4 yrs', '₱4,875/mo', 'Full day', 'playschool'),
(14, 'M.A.D. Studio', 'Hiphop Aerobics', 'Fitness Training', '₱2,500 /mo', 'MWF · 6:45–7:45 PM', 'madstudio'),
(15, 'M.A.D. Studio', 'Kickboxing', 'Fitness Training', '₱2,500 /mo', 'TTHS · 6:45–7:45 PM', 'madstudio'),
(16, 'M.A.D. Studio', 'Cross Training', 'Fitness Training', '₱3,500 /mo', 'Both schedules', 'madstudio'),
(17, 'M.A.D. Studio', 'Gymnastics', 'After School Program', '₱2,500 /mo', 'Sat · 8:30–10:30 AM', 'madstudio'),
(18, 'M.A.D. Studio', 'Ballet Class', 'After School Program', '₱2,500 /mo', 'Sat · 10:30 AM–12:30 PM', 'madstudio'),
(19, 'M.A.D. Studio', 'Taekwondo', 'After School Program', '₱2,500 /mo', 'Sat 12:30–2:30 PM · TTHS 5:30–6:30 PM', 'madstudio'),
(20, 'M.A.D. Studio', 'Pop Dancing', 'After School Program', '₱2,500 /mo', 'MWF · 5:30–6:30 PM', 'madstudio'),
(21, 'M.A.D. Studio', 'Studio Rental', 'Studio Rental', '₱450 /hr', 'Available anytime · book in advance', 'madstudio');

-- ── 7. MISSING COLUMNS — enrollments & users (safe upgrades) ──────────
-- guardian_age: stored encrypted just like other guardian fields
ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS guardian_age TEXT DEFAULT NULL AFTER guardian_name;

-- ── 8. ADMIN ACCOUNTS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_accounts (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  display_name     VARCHAR(120)  NOT NULL,
  username         VARCHAR(191)  NOT NULL UNIQUE,
  password_hash    VARCHAR(255)  NOT NULL,
  role             ENUM('sub_admin','staff') NOT NULL DEFAULT 'sub_admin',
  is_active        TINYINT(1)    NOT NULL DEFAULT 1,
  can_edit_prices  TINYINT(1)    NOT NULL DEFAULT 0,
  can_modify_records TINYINT(1)  NOT NULL DEFAULT 1,
  nav_permissions  TEXT          DEFAULT NULL,
  email            VARCHAR(191)  DEFAULT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe column upgrades for existing installations
ALTER TABLE admin_accounts ADD COLUMN IF NOT EXISTS can_edit_prices TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE admin_accounts ADD COLUMN IF NOT EXISTS can_modify_records TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE admin_accounts ADD COLUMN IF NOT EXISTS nav_permissions TEXT DEFAULT NULL;
ALTER TABLE admin_accounts ADD COLUMN IF NOT EXISTS email VARCHAR(191) DEFAULT NULL;

-- ── 9. ADMIN ACTIVITY LOG ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_activity_log (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  actor_name   VARCHAR(120)  NOT NULL,
  actor_role   VARCHAR(30)   NOT NULL,
  action_type  VARCHAR(80)   NOT NULL,
  description  TEXT          NOT NULL,
  ip_address   VARCHAR(45)   DEFAULT NULL,
  logged_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aal_logged (logged_at),
  INDEX idx_aal_actor  (actor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. TUTOR ASSIGNMENTS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tutor_assignments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  enrollment_id INT NOT NULL,
  tutor_id      INT NOT NULL,
  assigned_by   VARCHAR(100) DEFAULT NULL,
  notes         TEXT         DEFAULT NULL,
  assigned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ta_enrollment (enrollment_id),
  INDEX idx_ta_tutor (tutor_id),
  FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. TUTOR SCHEDULES ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tutor_schedules (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tutor_id     INT UNSIGNED NOT NULL,
  day          VARCHAR(20)  NOT NULL,
  day_label    VARCHAR(50)  NOT NULL,
  time_slot    VARCHAR(100) NOT NULL,
  student_name VARCHAR(255) NOT NULL,
  student_age  VARCHAR(50)  DEFAULT NULL,
  program      VARCHAR(255) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  room         VARCHAR(100) NOT NULL,
  status       VARCHAR(50)  NOT NULL DEFAULT 'Scheduled',
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 12. TUTOR SESSIONS / LOGGED HOURS ────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enrollment_id   INT UNSIGNED DEFAULT NULL,
  student_name    VARCHAR(255) DEFAULT NULL,
  tutor_id        INT UNSIGNED NOT NULL,
  session_date    DATE         NOT NULL,
  duration_hours  DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  notes           TEXT         DEFAULT NULL,
  is_verified     TINYINT(1)   NOT NULL DEFAULT 0,
  verified_by     VARCHAR(100) DEFAULT NULL,
  verified_at     DATETIME     DEFAULT NULL,
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session_tutor    (tutor_id),
  INDEX idx_session_date     (session_date),
  INDEX idx_session_verified (is_verified),
  FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 13. ANNOUNCEMENTS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS announcements (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,
  body       TEXT         NOT NULL,
  category   VARCHAR(50)  NOT NULL DEFAULT 'General',
  audience   VARCHAR(50)  NOT NULL DEFAULT 'all',
  posted_by  VARCHAR(100) NOT NULL DEFAULT 'Admin',
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_audience (audience)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 14. SYSTEM SETTINGS & PAYMENT QRS ────────────────────────────────
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(100) PRIMARY KEY,
  setting_value LONGTEXT     NOT NULL,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 14A. EDITABLE HOMEPAGE CONTENT ───────────────────────────────────────
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

-- ── 15. VIP SETTINGS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vip_settings (
  id             INT PRIMARY KEY,
  duration_years INT NOT NULL DEFAULT 2
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default VIP duration (2 years)
INSERT IGNORE INTO vip_settings (id, duration_years) VALUES (1, 2);

-- ── 16. VIP UNSUBSCRIPTIONS ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vip_unsubscriptions (
  user_id         INT UNSIGNED PRIMARY KEY,
  unsubscribed_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 17. SLOT CAPACITIES ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS slot_capacities (
  program_key VARCHAR(50)  PRIMARY KEY,
  capacity    INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default capacities (matching slot_capacities.php defaults)
INSERT IGNORE INTO slot_capacities (program_key, capacity) VALUES
  ('tutoring',    20),
  ('playschool',  20),
  ('childcare',   20),
  ('workshop',    20),
  ('madstudio',   20),
  ('summerblast', 20),
  ('vip',         50);

-- ── 18. STUDENT ARCHIVES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS student_archives (
  enrollment_id INT UNSIGNED PRIMARY KEY,
  archived_at   DATETIME     NULL,
  updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 19. PROGRAM ENROLLMENT SPANS ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS program_enrollment_spans (
  program_key    VARCHAR(50)  PRIMARY KEY,
  program_name   VARCHAR(100) NOT NULL,
  duration_value INT UNSIGNED NOT NULL DEFAULT 1,
  duration_unit  ENUM('months','years','weeks','days') NOT NULL DEFAULT 'months',
  updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default program spans
INSERT IGNORE INTO program_enrollment_spans (program_key, program_name, duration_value, duration_unit) VALUES
  ('tutoring',    'Academic Tutorial',   1, 'months'),
  ('playschool',  'PlaySchool',          1, 'months'),
  ('childcare',   'Child Care',          1, 'months'),
  ('workshop',    'Weekend Workshop',    1, 'months'),
  ('madstudio',   'M.A.D. Studio',       1, 'months'),
  ('summerblast', 'Summer Blast',        2, 'months'),
  ('vip',         'VIP Club Membership', 1, 'years');

-- ── 20. CLIENT PORTAL VISITS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS client_portal_visits (
  user_id         INT UNSIGNED PRIMARY KEY,
  first_opened_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 21. BALANCE RECEIPT CLAIMS (duplicate receipt guard) ─────────────
-- Tracks file hashes of approved balance payment receipts to prevent
-- the same receipt image from being approved more than once.
CREATE TABLE IF NOT EXISTS balance_receipt_claims (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_hash       CHAR(64)      NOT NULL,
  transaction_ref VARCHAR(64)   DEFAULT NULL,
  enrollment_id   INT UNSIGNED  NOT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_file_hash       (file_hash),
  UNIQUE KEY uq_transaction_ref (transaction_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 22. VERIFY TABLES ────────────────────────────────────────────────
SHOW TABLES;
