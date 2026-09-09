-- ═══════════════════════════════════════════════════════════════════
--  EINSTEIN CENTER FOR MODERN EDUCATION
--  MySQL Database Setup — Full Schema + Seed Data
--  Usage:  mysql -u root -p < einstein_mysql_setup.sql
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. CREATE DATABASE ──────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS einstein_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE einstein_db;

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

-- ── 7. ADMIN ACCOUNTS (auto-created by manage_admins.php, but ensure column) ──
ALTER TABLE admin_accounts ADD COLUMN IF NOT EXISTS can_edit_prices TINYINT(1) NOT NULL DEFAULT 0;

-- ── 8. TUTOR SCHEDULES ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tutor_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tutor_id INT UNSIGNED NOT NULL,
  day VARCHAR(20) NOT NULL,
  day_label VARCHAR(50) NOT NULL,
  time_slot VARCHAR(100) NOT NULL,
  student_name VARCHAR(255) NOT NULL,
  student_age VARCHAR(50) DEFAULT NULL,
  program VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  room VARCHAR(100) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'Scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. VERIFY TABLES ────────────────────────────────────────────────
SHOW TABLES;
