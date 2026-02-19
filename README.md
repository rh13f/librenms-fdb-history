# LibreNMS FDB History

Netdisco-style historical MAC address port tracking for LibreNMS.

**What it does:** Every time LibreNMS polls FDB/CAM tables from your switches, this
records where each MAC was seen. You can then search any MAC address and see its full
port history — which switch, which interface, which VLAN, and when it was first and last
observed there.

## How It Works

LibreNMS's built-in `ports_fdb` table only stores **current** state. When a device moves
ports or disappears, the old mapping is lost. This project adds:

- **`fdb_history` table** — stores one row per unique `(mac, device, port, vlan)` combination,
  with `first_seen` and `last_seen` timestamps.
- **`fdb-history-sync.php`** — cron script that upserts `ports_fdb` → `fdb_history` after
  each poll. Uses a single `INSERT ... ON DUPLICATE KEY UPDATE` for efficiency.
- **FdbHistory plugin** — LibreNMS UI plugin with a search page at `/plugin/FdbHistory`.

The unique constraint `(mac_address, device_id, port_id, vlan_id)` drives all the logic:
- Same MAC still on same port → `last_seen` updated, `first_seen` unchanged
- MAC moves to new port → new row inserted; old row's `last_seen` freezes
- MAC disappears entirely → row preserved forever (full history retained)

---

## Installation

### 1. Create the `fdb_history` table

Run the SQL migration on your LibreNMS database:

```bash
mysql -u librenms -p librenms < /path/to/fdb_history_setup.sql
```

Or interactively:
```sql
mysql -u librenms -p librenms
source /path/to/fdb_history_setup.sql
```

### 2. Deploy the sync script

```bash
# Copy to LibreNMS scripts directory
sudo cp scripts/fdb-history-sync.php /opt/librenms/scripts/
sudo chown librenms:librenms /opt/librenms/scripts/fdb-history-sync.php
sudo chmod 750 /opt/librenms/scripts/fdb-history-sync.php
```

Test it manually first:
```bash
sudo -u librenms php /opt/librenms/scripts/fdb-history-sync.php
```

Expected output:
```
[2025-01-15 14:30:00] Starting FDB history sync (LibreNMS path: /opt/librenms)
[2025-01-15 14:30:01] Sync complete — ports_fdb: 4821 rows | fdb_history: 4821 rows | new: 4821 | updated: 0 | elapsed: 0.42s
```

### 3. Set up the cron job

Create `/etc/cron.d/librenms-fdb-history`:

```
# FDB History Sync — runs every 5 minutes (matches LibreNMS poll interval)
*/5 * * * * librenms /usr/bin/php /opt/librenms/scripts/fdb-history-sync.php >> /var/log/librenms/fdb-history-sync.log 2>&1
```

```bash
sudo bash -c 'cat > /etc/cron.d/librenms-fdb-history << EOF
*/5 * * * * librenms /usr/bin/php /opt/librenms/scripts/fdb-history-sync.php >> /var/log/librenms/fdb-history-sync.log 2>&1
EOF'
```

### 4. Install the UI plugin

```bash
# Copy plugin to LibreNMS
sudo cp -r plugin/FdbHistory /opt/librenms/app/Plugins/
sudo chown -R librenms:librenms /opt/librenms/app/Plugins/FdbHistory
```

### 5. Enable the plugin in LibreNMS

1. Log in to LibreNMS as an admin
2. Go to **Settings → Plugins** (or **Admin → Plugins**)
3. Find **FdbHistory** and click **Enable**
4. The "FDB History" link will appear in the **Plugins** menu

---

## File Structure

```
.
├── README.md
├── sql/
│   └── fdb_history_setup.sql        # Run once to create the table
├── scripts/
│   └── fdb-history-sync.php         # Cron sync script
└── plugin/
    └── FdbHistory/
        ├── Menu.php                 # Adds "FDB History" to plugins menu
        ├── Page.php                 # Search logic (queries fdb_history)
        └── resources/views/
            ├── menu.blade.php       # Menu link template
            └── page.blade.php       # Search UI template
```

---

## Database Schema

```sql
fdb_history
├── id            BIGINT   — auto-increment primary key
├── mac_address   VARCHAR  — MAC as stored in ports_fdb (e.g. aabbccddeeff)
├── device_id     INT      — references devices.device_id
├── port_id       INT      — references ports.port_id (0 = unknown)
├── vlan_id       INT      — references vlans.vlan_id by DB id (0 = untagged)
├── first_seen    TIMESTAMP — when MAC was first seen on this device/port/vlan
└── last_seen     TIMESTAMP — when MAC was last seen on this device/port/vlan

UNIQUE KEY: (mac_address, device_id, port_id, vlan_id)
```

---

## Optional: History Retention

By default, history is kept forever. To automatically purge old entries, edit
`fdb-history-sync.php` and change:

```php
define('RETENTION_DAYS', 0);   // 0 = keep forever
```
to e.g.:
```php
define('RETENTION_DAYS', 365); // delete entries not seen in 1 year
```

---

## Searching via CLI / API

You can query the history directly in MySQL:

```sql
-- Full history for a specific MAC
SELECT
    h.mac_address,
    d.hostname,
    p.ifName,
    p.ifDescr,
    v.vlan_vlan AS vlan,
    h.first_seen,
    h.last_seen
FROM fdb_history h
LEFT JOIN devices d ON d.device_id = h.device_id
LEFT JOIN ports   p ON p.port_id   = h.port_id
LEFT JOIN vlans   v ON v.vlan_id   = h.vlan_id
WHERE h.mac_address = 'aabbccddeeff'
ORDER BY h.last_seen DESC;

-- Find all MACs seen on a specific port in the last 30 days
SELECT h.mac_address, h.first_seen, h.last_seen
FROM fdb_history h
JOIN ports p ON p.port_id = h.port_id
WHERE p.ifName = 'GigabitEthernet0/1'
  AND h.last_seen > DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY h.last_seen DESC;

-- Table size overview
SELECT
    COUNT(*) AS total_entries,
    COUNT(DISTINCT mac_address) AS unique_macs,
    COUNT(DISTINCT device_id) AS devices,
    MIN(first_seen) AS oldest_entry,
    MAX(last_seen) AS newest_entry,
    ROUND(data_length / 1024 / 1024, 1) AS size_mb
FROM fdb_history
JOIN information_schema.TABLES
    ON table_schema = DATABASE() AND table_name = 'fdb_history';
```

---

## Requirements

- LibreNMS (any recent version with the Laravel-based plugin system)
- PHP 8.0+ (for `never` return type in sync script — downgrade to `void` + `exit(1)` for PHP 7.x)
- FDB polling enabled on your switches in LibreNMS (Settings → Poller → Modules → fdb-table)

## Troubleshooting

**Sync script fails with "Cannot find .env"**
Pass the LibreNMS path explicitly:
```bash
php /opt/librenms/scripts/fdb-history-sync.php /opt/librenms
```

**Plugin not appearing in LibreNMS**
- Check file permissions: `ls -la /opt/librenms/app/Plugins/FdbHistory/`
- Check LibreNMS logs: `tail -f /opt/librenms/logs/librenms.log`
- Ensure the plugin is enabled in Admin → Plugins

**No FDB data in `ports_fdb`**
LibreNMS must be polling FDB tables. Verify:
```bash
sudo -u librenms php /opt/librenms/lnms device:poll <device-id> --modules=fdb-table -v
```

**fdb_history table grows too large**
Enable the retention setting in `fdb-history-sync.php`:
```php
define('RETENTION_DAYS', 365);
```
