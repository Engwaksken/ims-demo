-- ============================================================================
-- IMS: payment voucher signatures and approval (/payment-voucher)
-- ============================================================================
-- The preparer (Admin/Finance) signs when saving; their profile signature
-- (employee_directory.signature_path) is attached by default and can be
-- replaced. Submitting sends the voucher to an approver (Senior Finance /
-- Head of Department), who is notified and signs to approve or returns it.
-- Signatures are stored with the voucher so prints/PDFs never change if a
-- profile signature is later replaced.
-- legacy/includes/voucher-approval.php adds these columns on first use too.
-- Idempotent (MariaDB): safe to run more than once.
-- ============================================================================

ALTER TABLE payment_vouchers
  ADD COLUMN IF NOT EXISTS approval_status VARCHAR(20) NOT NULL DEFAULT 'Draft',
  ADD COLUMN IF NOT EXISTS prepared_by INT NULL,
  ADD COLUMN IF NOT EXISTS prepared_signature MEDIUMBLOB NULL,
  ADD COLUMN IF NOT EXISTS prepared_signature_mime VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS prepared_signed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS approver_id INT NULL,
  ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS approved_signature MEDIUMBLOB NULL,
  ADD COLUMN IF NOT EXISTS approved_signature_mime VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS approval_comments TEXT NULL;

ALTER TABLE payment_vouchers
  ADD INDEX IF NOT EXISTS idx_pv_approval (approval_status, approver_id);
