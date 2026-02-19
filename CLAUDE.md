# LibreNMS FDB History — Project Context

## Project Goal
Recreate Netdisco-style historical FDB (MAC address forwarding table) tracking inside LibreNMS.
Users can search a MAC address and see every switch port it has ever been seen on, with
`first_seen` and `last_seen` timestamps per location.

## Problem Being Solved
LibreNMS's built-in `ports_fdb` table only stores **current state**. When a device moves
ports, the old mapping is silently overwritten. When a device goes offline, `daily.sh`
purges its entries entirely. There is no history.

Netdisco solved this with a historical MAC table. This project replicates that for LibreNMS.

---

## LibreNMS Architecture Notes

### Relevant existing tables
| Table | Purpose |
|---|---|
| `ports_fdb` | Current MAC→port mappings. Columns: `ports_fdb_id`, `mac_address`, `device_id`, `port_id`, `vlan_id`, `created_at`, `updated_at` |
| `devices` | Network devices. Key columns: `device_id`, `hostname`, `sysName` |
| `ports` | Switch ports. Key columns: `port_id`, `device_id`, `ifName`, `ifDescr`, `ifAlias` |
| `vlans` | VLANs. Key columns: `vlan_id`, `device_id`, `vlan_vlan` (actual VLAN number), `vlan_name` |
| `vendors` | OUI vendor names. Columns: `oui` (6-char uppercase hex, e.g. `AABBCC`), `vendor`. May be absent on older installs — always guard with `hasTable('vendors')`. |

### MAC address format
LibreNMS stores MAC addresses as 12 hex chars with no separators: `aabbccddeeff`.
Search must normalize user input (strip `:` `-` spaces) before comparing.

### `ports_fdb.vlan_id`
Stores the **database `vlans.vlan_id`** (not the actual VLAN number). Join to `vlans`
on `vlans.vlan_id = ports_fdb.vlan_id` to get `vlan_vlan` (the real VLAN number).

### `port_id` NULL quirk
Cisco IOS (and some others) can return a NULL `port_id`. LibreNMS's discovery handles
this by storing an empty string. The sync script uses `COALESCE(port_id, 0)` to store
`0` as the sentinel for unknown port in `fdb_history`.

### FDB discovery source
`includes/discovery/fdb-table.inc.php` — runs during device discovery/polling.
It does an upsert into `ports_fdb` (updates port if changed, else just bumps `updated_at`).
Old entries are deleted by `daily.sh`, not by this file.

### Plugin system
Local plugins live in `app/Plugins/PluginName/`. Structure:
```
app/Plugins/FdbHistory/
├── FdbHelpers.php                    # static helpers: fmtMac(), ouiPrefix()
├── Menu.php                          # extends MenuEntryHook
├── Page.php                          # extends PageHook (search UI + JSON API)
├── PortTab.php                       # extends PortTabHook (port detail tab)
└── resources/views/
    ├── menu.blade.php                # link shown in Plugins menu
    ├── page.blade.php                # full search UI
    └── port-tab.blade.php            # MAC history on port detail page
```
- Plugin page is accessible at `/plugin/FdbHistory`
- `Page::data()` — no parameters; use `request()` helper inside the method
- `authorize()` **must** use `Authenticatable $user` (not `User $user`) and call
  `auth()->user()?->can('global-read')` — see Plugin System Gotchas below
- Enable/disable via LibreNMS Admin → Plugins UI
- **PortTabHook**: verify `/opt/librenms/app/Plugins/Hooks/PortTabHook.php` exists after deploy

---

## Solution Components

### 1. `sql/fdb_history_setup.sql`
Creates the `fdb_history` table. Run once:
```bash
mysql -u librenms -p librenms < fdb_history_setup.sql
```

**Schema:**
```
fdb_history
├── id            BIGINT UNSIGNED AUTO_INCREMENT PK
├── mac_address   VARCHAR(17)   NOT NULL
├── device_id     INT UNSIGNED  NOT NULL
├── port_id       INT UNSIGNED  NOT NULL DEFAULT 0   (0 = unknown)
├── vlan_id       INT UNSIGNED  NOT NULL DEFAULT 0   (0 = untagged/unknown)
├── first_seen    TIMESTAMP
└── last_seen     TIMESTAMP

UNIQUE KEY: (mac_address, device_id, port_id, vlan_id)
```

The unique constraint is the heart of the design:
- Same MAC on same port → UPDATE `last_seen` only
- MAC moves to new port → INSERT new row; old row's `last_seen` freezes
- MAC disappears → row preserved forever

### 2. `scripts/fdb-history-sync.php`
PHP cron script. Uses LibreNMS bootstrap (`$init_modules = ['laravel']` + `includes/init.php`)
so it inherits LibreNMS's DB connection config without parsing credentials manually.
Retention: 365 days (hardcoded, adjust `RETENTION_DAYS`).

**Core sync — a single SQL statement:**
```sql
INSERT INTO fdb_history (mac_address, device_id, port_id, vlan_id, first_seen, last_seen)
SELECT mac_address, device_id, COALESCE(port_id, 0), COALESCE(vlan_id, 0), NOW(), NOW()
FROM ports_fdb
ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)
```

**Deploy:**
```bash
cp scripts/fdb-history-sync.php /opt/librenms/scripts/
chown librenms:librenms /opt/librenms/scripts/fdb-history-sync.php
chmod 750 /opt/librenms/scripts/fdb-history-sync.php
```

**Cron (`/etc/cron.d/librenms-fdb-history`) — runs every minute:**
```
* * * * * librenms /usr/bin/php /opt/librenms/scripts/fdb-history-sync.php >> /var/log/librenms/fdb-history-sync.log 2>&1
```
Note: ~144 KB/day of log output at 1-minute intervals. Add logrotate if needed.

### 3. `plugin/FdbHistory/` — LibreNMS Plugin

**Deploy:**
```bash
cp -r plugin/FdbHistory /opt/librenms/app/Plugins/
chown -R librenms:librenms /opt/librenms/app/Plugins/FdbHistory
```
Then enable in LibreNMS Admin → Plugins.

#### `FdbHelpers.php` — Static helpers
- `FdbHelpers::fmtMac($mac)` — formats raw MAC as `aa:bb:cc:dd:ee:ff`
- `FdbHelpers::ouiPrefix($mac)` — returns 6-char uppercase OUI prefix
- Extracted to a class to prevent "Cannot redeclare" fatals if blade renders twice

#### `Page.php` — Search UI + JSON API
Search triggers when **any** of these GET params is non-empty:
| Param | Filter |
|-------|--------|
| `mac` | Partial hex LIKE match on `h.mac_address` |
| `device` | Exact `h.device_id` match |
| `port` | Exact `h.port_id` match |
| `vlan` | Exact `v.vlan_vlan` match (real VLAN number) |
| `hide_trunks` | `'1'` (default) — exclude ports with >20 distinct MACs |

Add `&format=json` to get JSON instead of HTML. Uses `response()->json(...)->send(); exit(0)` pattern.

**JSON sub-endpoints** (handled at top of `data()`, early exit before main query):
- `?format=json&action=ports&device=X` — ports for device X in fdb_history
- `?format=json&action=vlans&device=X` — VLANs for device X in fdb_history

OUI vendor join: `LEFT JOIN vendors as ven ON ven.oui = UPPER(LEFT(h.mac_address, 6))` — only added when `$hasVendors === true`.

**Trunk filter** (`hide_trunks`): When on and no specific port is selected, adds:
```php
->whereNotIn('h.port_id', function ($q) {
    $q->from('fdb_history')->select('port_id')
      ->where('port_id', '>', 0)->groupBy('port_id')
      ->havingRaw('COUNT(DISTINCT mac_address) > 20');
})
```
Form always submits `hide_trunks=0|1` via a hidden input; checkbox toggles it via JS `onchange`.

**UI features:**
- MAC input + Device dropdown + Port select (AJAX-populated) + VLAN select (AJAX-populated)
- "Hide trunk ports" checkbox (default on, threshold >20 MACs)
- Vendor column (when `vendors` table present)
- Results: device link, interface link, VLAN number + name, first_seen, last_seen
- Status badges: Active (< 20 min), Recent (< 2 hr), Historical
- JSON button in results bar
- Landing page: table stats + device dropdown pre-populated
- JS: device change → AJAX-load ports/vlans; page-load with device set → auto-populate

#### `PortTab.php` — Port detail tab
Adds FDB History tab to each port's detail page. Shows MACs seen on that port
(max 500, ordered by `last_seen DESC`), with link to full search filtered by port.

---

## Plugin System Gotchas (hard-won lessons)

### `authorize()` must use `Authenticatable`, not `User`
`PluginManager::hooksFor()` calls `authorize()` via `app()->call()` with only
`['pluginName', 'settings']` in the named args. When typed `User $user`, the Laravel
container instantiates a **fresh empty `App\Models\User()`** (it's concrete, so the
container builds it). That empty model fails `->can('global-read')` → `authorize()` returns
`false` → hook filtered out → controller falls back to `plugins.missing` → "Missing View".

```php
// CORRECT — matches ExamplePlugin convention
public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user): bool
{
    return auth()->user()?->can('global-read') ?? false;
}
```

### View namespace root is `app/Plugins/PluginName/`
`PluginProvider` calls `loadViewsFrom($pluginDir, $pluginName)`. The default
`$view = 'resources.views.page'` (inherited from `PageHook`) resolves dot-notation to
`app/Plugins/FdbHistory/resources/views/page.blade.php` ✓. Do not override `$view`
with `'page'` — that resolves to `app/Plugins/FdbHistory/page.blade.php` ✗.

### `PortTabHook.$view` is untyped — child class cannot add a type annotation
PHP 8 forbids adding a type to an inherited untyped property. `PageHook` has
`public string $view` (typed); `PortTabHook` has `public $view` (untyped). Use
`public $view = 'override';` in PortTab (no `string` keyword).

### `PluginManager::call()` silently disables the plugin on exception
If `handle()` (or `data()`) throws, the plugin is set `plugin_active = 0` in the DB
and next load shows "Missing View" — indistinguishable from a missing blade file.
Wrap all DB queries in try/catch inside `data()`.

---

## Installation Order
1. Run SQL to create table
2. Deploy and test sync script manually: `sudo -u librenms php /opt/librenms/scripts/fdb-history-sync.php`
3. Add cron job
4. Deploy plugin files
5. Enable plugin in LibreNMS UI
6. Verify PortTabHook exists: `ls /opt/librenms/app/Plugins/Hooks/PortTabHook.php`

---

## Update Safety
All files live outside the LibreNMS git tree:
- `app/Plugins/FdbHistory/` — excluded by `.gitignore` in `app/Plugins/`
- `scripts/fdb-history-sync.php` — unique filename, not in LibreNMS git
- `sql/fdb_history_setup.sql` — external, run-once file
- No modifications to any file tracked by LibreNMS git ✅

## Verification / Testing
1. **Table check:** `mysql -u librenms -p librenms -e "DESCRIBE fdb_history;"`
2. **Manual sync:** `sudo -u librenms php /opt/librenms/scripts/fdb-history-sync.php`
3. **Plugin UI:** `/plugin/FdbHistory` — verify device dropdown appears
4. **MAC search:** search a known MAC — verify Vendor column (if vendors table exists)
5. **Filter test:** filter by device + VLAN — verify row count changes
6. **JSON API:** `curl 'https://your-librenms/plugin/FdbHistory?mac=aabbcc&format=json'`
7. **Port tab:** device → port detail → Plugins/FDB History tab
8. **Cron:** `tail /var/log/librenms/fdb-history-sync.log` after 1 minute
9. **Update test:** `git pull` in `/opt/librenms` — verify plugin files still present
