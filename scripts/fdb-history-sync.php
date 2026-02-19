#!/usr/bin/env php
<?php
/**
 * FDB History Sync Script for LibreNMS
 *
 * Reads the current ports_fdb table and upserts into fdb_history, building a
 * Netdisco-style historical record of MAC address <-> port mappings over time.
 *
 * HOW IT WORKS:
 *   - Same MAC on same device+port+vlan  -> updates last_seen timestamp
 *   - MAC moves to a new port            -> inserts a new row; old row's last_seen stops updating
 *   - Entries removed from ports_fdb     -> fdb_history row is preserved (history kept)
 *
 * SETUP:
 *   1. Create fdb_history table:
 *        mysql -u librenms -p librenms < /opt/librenms/scripts/fdb_history_setup.sql
 *
 *   2. Copy this file:
 *        cp fdb-history-sync.php /opt/librenms/scripts/
 *        chown librenms:librenms /opt/librenms/scripts/fdb-history-sync.php
 *        chmod 750 /opt/librenms/scripts/fdb-history-sync.php
 *
 *   3. Add cron job (run as librenms user, every minute):
 *        echo "* * * * * librenms /usr/bin/php /opt/librenms/scripts/fdb-history-sync.php >> /var/log/librenms/fdb-history-sync.log 2>&1" \
 *          | sudo tee /etc/cron.d/librenms-fdb-history
 *
 *      Note: running every minute produces ~144 KB/day of log output.
 *      Add logrotate config if needed (/etc/logrotate.d/librenms-fdb-history).
 *
 * USAGE:
 *   php fdb-history-sync.php [/path/to/librenms]
 *   php fdb-history-sync.php /opt/librenms
 */

// ---- Configuration ----

// Delete history entries not seen in this many days (0 = keep forever)
define('RETENTION_DAYS', 365);

// ---- Bootstrap LibreNMS ----

$start_time    = microtime(true);
$librenms_path = rtrim($argv[1] ?? '/opt/librenms', '/');

log_msg("Starting FDB history sync (LibreNMS path: $librenms_path)");

// Use LibreNMS's own bootstrap so we inherit its DB connection config.
// $init_modules must be set before requiring init.php.
$init_modules = ['laravel'];
require $librenms_path . '/includes/init.php';

// Extract PDO from the bootstrapped Eloquent connection.
$pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ---- Main ----

// Verify the fdb_history table exists
verify_table($pdo);

// Run the upsert sync
$counts = sync_fdb($pdo);

// Optional cleanup of aged-out entries
if (RETENTION_DAYS > 0) {
    $deleted = cleanup_old_entries($pdo, RETENTION_DAYS);
    log_msg("Cleanup: removed $deleted entries older than " . RETENTION_DAYS . " days");
}

$elapsed = round(microtime(true) - $start_time, 2);
log_msg(sprintf(
    "Sync complete — ports_fdb: %d rows | fdb_history: %d rows | new: %d | updated: %d | elapsed: %ss",
    $counts['source'],
    $counts['history_total'],
    $counts['inserted'],
    $counts['updated'],
    $elapsed
));

exit(0);

// ---- Functions ----

/**
 * Verify the fdb_history table exists
 */
function verify_table(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'fdb_history'")->fetchColumn();
    if (!$exists) {
        fatal("Table 'fdb_history' does not exist. Run fdb_history_setup.sql first.\n"
            . "  mysql -u librenms -p librenms < /opt/librenms/scripts/fdb_history_setup.sql");
    }
}

/**
 * Core sync: upsert current ports_fdb into fdb_history
 *
 * The unique key (mac_address, device_id, port_id, vlan_id) drives the logic:
 *   - First time MAC seen on this port -> INSERT (first_seen = last_seen = now)
 *   - Same MAC still on same port      -> UPDATE last_seen = now (first_seen unchanged)
 *   - MAC moved to a new port          -> INSERT new row; old row's last_seen stops updating
 *
 * In MySQL, ON DUPLICATE KEY UPDATE returns:
 *   1 row affected per new INSERT
 *   2 rows affected per UPDATE (even if value unchanged, MySQL counts the attempt)
 *   0 rows affected if UPDATE would set identical values (MySQL optimization)
 *
 * Returns array with counts: source, history_total, inserted, updated
 */
function sync_fdb(PDO $pdo): array
{
    $now = date('Y-m-d H:i:s');

    // Count source rows before sync
    $source_count = (int) $pdo->query("SELECT COUNT(*) FROM ports_fdb")->fetchColumn();

    // Count history rows before sync to calculate inserts
    $before_count = (int) $pdo->query("SELECT COUNT(*) FROM fdb_history")->fetchColumn();

    // The upsert:
    //   COALESCE(port_id, 0) handles the Cisco quirk where port_id can be NULL in ports_fdb
    //   VALUES(last_seen) uses the value from the INSERT clause in the UPDATE
    $sql = "
        INSERT INTO fdb_history
            (mac_address, device_id, port_id, vlan_id, first_seen, last_seen)
        SELECT
            mac_address,
            device_id,
            COALESCE(port_id, 0)  AS port_id,
            COALESCE(vlan_id, 0)  AS vlan_id,
            :now_first             AS first_seen,
            :now_last              AS last_seen
        FROM ports_fdb
        ON DUPLICATE KEY UPDATE
            last_seen = VALUES(last_seen)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':now_first' => $now, ':now_last' => $now]);

    // Count history rows after sync
    $after_count = (int) $pdo->query("SELECT COUNT(*) FROM fdb_history")->fetchColumn();
    $inserted    = $after_count - $before_count;
    $updated     = max(0, $source_count - $inserted);

    return [
        'source'        => $source_count,
        'history_total' => $after_count,
        'inserted'      => $inserted,
        'updated'       => $updated,
    ];
}

/**
 * Remove history entries not seen since $days days ago
 */
function cleanup_old_entries(PDO $pdo, int $days): int
{
    $stmt = $pdo->prepare(
        "DELETE FROM fdb_history WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

/**
 * Log a timestamped message to stdout
 */
function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

/**
 * Log an error and exit.
 * Return type is void (not never) for PHP 8.0 compatibility;
 * never was added in PHP 8.1.
 */
function fatal(string $msg): void
{
    fwrite(STDERR, '[ERROR] ' . $msg . PHP_EOL);
    exit(1);
}
