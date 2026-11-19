-- ============================================================================
-- IMS: hub internet control, pull mode (pfSense checks in with the IMS)
-- ============================================================================
-- For an IMS on shared hosting, which cannot reach the firewall: the agent on
-- pfSense (tools/pfsense/ims-cp-agent.php, run every minute by pfSense's Cron
-- package) fetches the desired device states from /hub-network-agent and
-- reports what it applied. hub_agent_nonces blocks replayed requests.
-- legacy/includes/hub-network.php also adds these on first use.
-- Idempotent (MariaDB): safe to run more than once.
-- ============================================================================

ALTER TABLE hub_network_settings
  ADD COLUMN IF NOT EXISTS mode VARCHAR(10) NOT NULL DEFAULT 'pull',
  ADD COLUMN IF NOT EXISTS last_checkin_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS last_checkin_info TEXT NULL;

CREATE TABLE IF NOT EXISTS hub_agent_nonces (
    nonce CHAR(24) NOT NULL,
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nonce),
    KEY idx_hub_agent_nonce_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
