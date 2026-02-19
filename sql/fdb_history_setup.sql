-- ============================================================
-- FDB History Table for LibreNMS
-- Provides Netdisco-style historical MAC address tracking
-- ============================================================
--
-- Run once on your LibreNMS database:
--   mysql -u librenms -p librenms < fdb_history_setup.sql
--
-- Or interactively:
--   mysql -u librenms -p librenms
--   source /path/to/fdb_history_setup.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `fdb_history` (

    -- Primary key
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The MAC address as stored in ports_fdb (e.g. "aabbccddeeff", 12 hex chars, no separators)
    `mac_address` VARCHAR(17)     NOT NULL
        COMMENT 'MAC address matching ports_fdb.mac_address format',

    -- Foreign keys (not enforced with FK constraints to survive device/port deletions gracefully)
    `device_id`   INT UNSIGNED    NOT NULL
        COMMENT 'References devices.device_id',
    `port_id`     INT UNSIGNED    NOT NULL DEFAULT 0
        COMMENT 'References ports.port_id; 0 = unknown (Cisco quirk)',
    `vlan_id`     INT UNSIGNED    NOT NULL DEFAULT 0
        COMMENT 'References vlans.vlan_id (DB id, not VLAN number); 0 = untagged or unknown',

    -- History timestamps
    `first_seen`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When this MAC was first observed on this device/port/vlan',
    `last_seen`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When this MAC was most recently observed on this device/port/vlan',

    PRIMARY KEY (`id`),

    -- Unique constraint drives the upsert logic:
    --   Same MAC on same device+port+vlan  -> UPDATE last_seen only
    --   MAC moves to a new port            -> INSERT new row (old row last_seen freezes)
    --   MAC seen on multiple devices       -> one row per device
    UNIQUE KEY `uniq_mac_device_port_vlan` (`mac_address`, `device_id`, `port_id`, `vlan_id`),

    -- Lookup indexes
    INDEX `idx_mac`       (`mac_address`),
    INDEX `idx_device_id` (`device_id`),
    INDEX `idx_port_id`   (`port_id`),
    INDEX `idx_last_seen` (`last_seen`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical FDB (MAC forwarding table) data — Netdisco-style port history';

-- Verify creation
SELECT 'fdb_history table created successfully.' AS status;
SHOW CREATE TABLE fdb_history\G
