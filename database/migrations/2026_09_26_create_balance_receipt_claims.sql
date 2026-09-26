-- admin_review_balance_receipt.php records approved receipt hashes here so one
-- receipt cannot be approved twice; nothing creates the table at runtime.
CREATE TABLE IF NOT EXISTS balance_receipt_claims (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_hash       CHAR(64)      NOT NULL,
  transaction_ref VARCHAR(64)   DEFAULT NULL,
  enrollment_id   INT UNSIGNED  NOT NULL,
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_file_hash       (file_hash),
  UNIQUE KEY uq_transaction_ref (transaction_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
