-- ============================================================
-- SalesDesk — 0021: Business network invitations (plans Phase 2c)
--
-- SAFE TO RE-RUN. Spec: project doc claude/plans-and-billing.md.
-- Needs 0013 (organization_members.status) and 0020 (business orgs).
--
-- Businesses on a plan with can_invite_brokers (Pro, Enterprise) can
-- invite brokers into their network from the dealer portal (Network →
-- Find brokers). An invitation is a membership row with status
-- 'invited' (invited_by = who sent it, joined_at = when). The broker
-- accepts (→ verified) or declines (row deleted) on My networks.
--
-- 'invited' rows don't count towards the broker's max_networks until
-- accepted, and don't count towards the network's size either.
--
--   1. organization_members.status gains 'invited'
-- ============================================================

-- ── 1. 'invited' status (MODIFY keeps existing values; re-running is a no-op) ──
SET @sd_sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organization_members'
        AND COLUMN_NAME = 'status' AND COLUMN_TYPE NOT LIKE '%''invited''%') > 0,
    'ALTER TABLE `organization_members` MODIFY `status` ENUM(''pending'',''verified'',''rejected'',''suspended'',''invited'') NOT NULL DEFAULT ''pending'' COMMENT ''Network lifecycle: pending application, invited by the business, verified member, rejected, suspended''',
    'DO 0'));
PREPARE sd_stmt FROM @sd_sql; EXECUTE sd_stmt; DEALLOCATE PREPARE sd_stmt;

INSERT IGNORE INTO schema_migrations (name) VALUES ('0021_network_invites');
SELECT '0021 applied — brokers can now be invited into business networks' AS result;
