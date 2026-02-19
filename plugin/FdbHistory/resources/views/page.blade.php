{{--
    FDB History — page.blade.php
    LibreNMS plugin providing Netdisco-style historical MAC address tracking.
    Variables injected by Page.php::data():
        $raw_mac     — original MAC search input string
        $device_id   — device filter (0 = all)
        $port_id     — port filter (0 = all)
        $vlan_num    — VLAN number filter (0 = all)
        $hide_trunks — bool: filter out ports with >20 unique MACs (trunk heuristic)
        $has_search  — bool: at least one filter is active
        $results     — Illuminate\Support\Collection of result rows
        $error       — error message string or null
        $stats       — ['total', 'unique_macs', 'unique_devices'] or null
        $overview    — stdObject with total/unique_macs/oldest/newest or null
        $hasVendors  — bool: vendors table available for OUI lookups
        $devices     — Collection of devices present in fdb_history
--}}

<div class="container-fluid" style="padding-top: 15px;">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Page Header                                                          --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="row">
        <div class="col-md-12">
            <h2 style="margin-top: 0;">
                <i class="fa fa-history" aria-hidden="true"></i>
                FDB History
                <small>Netdisco-style MAC address port history</small>
            </h2>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Search / Filter Form                                                --}}
    {{-- ------------------------------------------------------------------ --}}
    <div class="row">
        <div class="col-md-10">
            <form method="GET" action="{{ url('plugin/FdbHistory') }}" role="search">

                {{-- Row 1: MAC search input --}}
                <div class="row">
                    <div class="col-sm-8">
                        <div class="input-group input-group-lg">
                            <input
                                type="text"
                                name="mac"
                                class="form-control"
                                placeholder="MAC address or partial — e.g. aa:bb:cc:dd:ee:ff or aa:bb:cc"
                                value="{{ htmlspecialchars($raw_mac) }}"
                                autocomplete="off"
                                autofocus
                                spellcheck="false"
                            >
                            <span class="input-group-btn">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fa fa-search" aria-hidden="true"></i> Search
                                </button>
                                @if($has_search)
                                <a href="{{ url('plugin/FdbHistory') }}" class="btn btn-default btn-lg" title="Clear all filters">
                                    <i class="fa fa-times" aria-hidden="true"></i>
                                </a>
                                @endif
                            </span>
                        </div>
                        <p class="help-block" style="margin-bottom: 0;">
                            Accepts any format: <code>aa:bb:cc:dd:ee:ff</code> &nbsp;
                            <code>aa-bb-cc-dd-ee-ff</code> &nbsp; <code>aabbccddeeff</code> &nbsp;
                            or a partial OUI prefix like <code>aa:bb:cc</code>
                        </p>
                    </div>
                </div>

                {{-- Row 2: Additional filters --}}
                <div class="row" style="margin-top: 10px;">
                    <div class="col-sm-4">
                        <select name="device" id="fdbh-device" class="form-control" title="Filter by device">
                            <option value="">— All Devices —</option>
                            @foreach($devices as $dev)
                            <option value="{{ $dev->device_id }}" {{ $device_id == $dev->device_id ? 'selected' : '' }}>
                                {{ $dev->hostname ?: $dev->sysName ?: 'Device #' . $dev->device_id }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <select name="port" id="fdbh-port" class="form-control" title="Filter by port — select a device first">
                            <option value="">— All Ports —</option>
                            @if($port_id > 0)
                            <option value="{{ $port_id }}" selected>port #{{ $port_id }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <select name="vlan" id="fdbh-vlan" class="form-control" title="Filter by VLAN — select a device first">
                            <option value="">— All VLANs —</option>
                            @if($vlan_num > 0)
                            <option value="{{ $vlan_num }}" selected>{{ $vlan_num }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-sm-3" style="padding-top: 6px;">
                        {{-- hidden input ensures hide_trunks is always submitted; checkbox toggles it --}}
                        <input type="hidden" name="hide_trunks" id="fdbh-hide-trunks-val" value="{{ $hide_trunks ? '1' : '0' }}">
                        <label class="checkbox-inline" style="font-weight: normal; margin-right: 12px;" title="Exclude ports that have seen more than 20 unique MACs (trunk/uplink heuristic)">
                            <input type="checkbox" id="fdbh-hide-trunks-cb" {{ $hide_trunks ? 'checked' : '' }}
                                   onchange="document.getElementById('fdbh-hide-trunks-val').value = this.checked ? '1' : '0'">
                            Hide trunk ports
                        </label>
                        <button type="submit" class="btn btn-default btn-sm">Apply</button>
                    </div>
                </div>

            </form>
        </div>

        {{-- Live overview stats (shown on landing page only) --}}
        @if(!$has_search && $overview && $overview->total > 0)
        <div class="col-md-2 text-right" style="padding-top: 10px;">
            <span class="text-muted small">
                <i class="fa fa-database" aria-hidden="true"></i>
                {{ number_format($overview->total) }} records<br>
                {{ number_format($overview->unique_macs) }} unique MACs<br>
                Since {{ $overview->oldest ? date('Y-m-d', strtotime($overview->oldest)) : '—' }}
            </span>
        </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Error                                                               --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($error)
    <div class="row" style="margin-top: 15px;">
        <div class="col-md-10">
            <div class="alert alert-danger">
                <i class="fa fa-exclamation-circle" aria-hidden="true"></i>
                {{ $error }}
            </div>
        </div>
    </div>
    @endif

    {{-- ------------------------------------------------------------------ --}}
    {{-- Results                                                             --}}
    {{-- ------------------------------------------------------------------ --}}
    @if($has_search && !$error)
    <div class="row" style="margin-top: 20px;">
        <div class="col-md-12">

            @if($results->isEmpty())

                <div class="alert alert-warning">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    No history found for the given filters.
                    <br>
                    <small>
                        Check that the sync cron job is running
                        (<code>fdb-history-sync.php</code>) and that LibreNMS is polling FDB tables
                        from your switches.
                    </small>
                </div>

            @else

                {{-- Result summary bar --}}
                <div style="margin-bottom: 10px;">
                    <strong>{{ $stats['total'] }}</strong> result(s)
                    @if($raw_mac !== '')
                        for MAC <code>{{ htmlspecialchars($raw_mac) }}</code>
                    @endif
                    @if($device_id > 0)
                        &mdash; device #{{ $device_id }}
                    @endif
                    @if($port_id > 0)
                        &mdash; port #{{ $port_id }}
                    @endif
                    @if($vlan_num > 0)
                        &mdash; VLAN {{ $vlan_num }}
                    @endif
                    @if($stats['unique_macs'] > 1)
                        &mdash; {{ $stats['unique_macs'] }} distinct MACs
                    @endif
                    @if($stats['unique_devices'] > 0)
                        across {{ $stats['unique_devices'] }} device(s)
                    @endif
                    @if($stats['total'] >= 1000)
                        <span class="text-warning">
                            &mdash; <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                            showing first 1,000 results; refine your search for more precise results
                        </span>
                    @endif
                    <span class="pull-right">
                        <a href="{{ url('plugin/FdbHistory') }}?{{ http_build_query(array_filter(['mac' => $raw_mac, 'device' => $device_id ?: '', 'port' => $port_id ?: '', 'vlan' => $vlan_num ?: '', 'format' => 'json'])) }}" class="btn btn-xs btn-default" target="_blank">
                            <i class="fa fa-code" aria-hidden="true"></i> JSON
                        </a>
                    </span>
                </div>

                <div class="panel panel-default">
                    <div class="table-responsive">
                        <table
                            class="table table-striped table-hover table-condensed"
                            id="fdb-history-table"
                        >
                            <thead>
                                <tr>
                                    <th>MAC Address</th>
                                    @if($hasVendors)<th>Vendor</th>@endif
                                    <th>Device</th>
                                    <th>Interface</th>
                                    <th>VLAN</th>
                                    <th>First Seen</th>
                                    <th>Last Seen</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($results as $row)
                                @php
                                    $mac_fmt  = \App\Plugins\FdbHistory\Support\FdbHelpers::fmtMac($row->mac_address);
                                    $ifLabel  = $row->ifName ?: $row->ifDescr ?: null;
                                    $hostname = $row->hostname ?: $row->sysName ?: null;

                                    $last_seen_ts = $row->last_seen ? strtotime($row->last_seen) : 0;
                                    $age_minutes  = $last_seen_ts ? (time() - $last_seen_ts) / 60 : PHP_INT_MAX;
                                    $is_active    = $age_minutes < 20;
                                    $is_recent    = $age_minutes < 120;
                                @endphp
                                <tr>
                                    {{-- MAC Address --}}
                                    <td>
                                        <code>{{ $mac_fmt }}</code>
                                        @if($row->mac_address !== $raw_mac)
                                        <a
                                            href="{{ url('plugin/FdbHistory') }}?mac={{ urlencode($row->mac_address) }}"
                                            title="Search this MAC exactly"
                                            style="margin-left: 4px; font-size: 11px;"
                                        ><i class="fa fa-search" aria-hidden="true"></i></a>
                                        @endif
                                    </td>

                                    {{-- Vendor (OUI lookup) --}}
                                    @if($hasVendors)
                                    <td>
                                        <span class="text-muted small">{{ $row->vendor ?? '—' }}</span>
                                    </td>
                                    @endif

                                    {{-- Device --}}
                                    <td>
                                        @if($hostname && $row->device_id)
                                            <a href="{{ url('device/device=' . $row->device_id) }}">
                                                {{ $hostname }}
                                            </a>
                                        @elseif($row->device_id)
                                            <span class="text-muted">device #{{ $row->device_id }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>

                                    {{-- Interface --}}
                                    <td>
                                        @if($row->port_id && $row->port_id > 0)
                                            <a href="{{ url('device/device=' . $row->device_id . '/tab=port/port=' . $row->port_id) }}">
                                                {{ $ifLabel ?? 'port #' . $row->port_id }}
                                            </a>
                                            @if($row->ifAlias)
                                                <br><small class="text-muted">{{ $row->ifAlias }}</small>
                                            @endif
                                        @elseif($ifLabel)
                                            {{ $ifLabel }}
                                        @else
                                            <span class="text-muted">unknown</span>
                                        @endif
                                    </td>

                                    {{-- VLAN --}}
                                    <td>
                                        @if($row->vlan_vlan)
                                            <span title="{{ $row->vlan_name ?? '' }}">
                                                {{ $row->vlan_vlan }}
                                                @if($row->vlan_name)
                                                    <small class="text-muted">({{ $row->vlan_name }})</small>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>

                                    {{-- First Seen --}}
                                    <td>
                                        <span title="{{ $row->first_seen }}">
                                            {{ $row->first_seen }}
                                        </span>
                                    </td>

                                    {{-- Last Seen --}}
                                    <td>
                                        <span title="{{ $row->last_seen }}">
                                            {{ $row->last_seen }}
                                        </span>
                                    </td>

                                    {{-- Active / Historical badge --}}
                                    <td>
                                        @if($is_active)
                                            <span class="label label-success">Active</span>
                                        @elseif($is_recent)
                                            <span class="label label-warning">Recent</span>
                                        @else
                                            <span class="label label-default">Historical</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>{{-- .table-responsive --}}
                </div>{{-- .panel --}}

            @endif {{-- results empty --}}
        </div>
    </div>
    @endif {{-- search active --}}

    {{-- ------------------------------------------------------------------ --}}
    {{-- Help panel (shown only on landing page)                            --}}
    {{-- ------------------------------------------------------------------ --}}
    @if(!$has_search)
    <div class="row" style="margin-top: 25px;">
        <div class="col-md-6">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-info-circle" aria-hidden="true"></i>
                        About FDB History
                    </h3>
                </div>
                <div class="panel-body">
                    <p>
                        This plugin provides <strong>Netdisco-style</strong> historical tracking
                        of MAC address &harr; switch port mappings. Unlike LibreNMS's built-in
                        FDB view (which only shows current state), this records every port a MAC
                        has ever been seen on with timestamps.
                    </p>
                    <dl class="dl-horizontal" style="margin-bottom: 0;">
                        <dt>Active</dt>
                        <dd>Seen within the last 20 minutes (current poll cycle)</dd>
                        <dt>Recent</dt>
                        <dd>Seen within the last 2 hours</dd>
                        <dt>Historical</dt>
                        <dd>Seen in the past but not recently — device may have moved or been decommissioned</dd>
                    </dl>
                    <hr>
                    <p class="text-muted" style="margin-bottom: 0; font-size: 12px;">
                        Data is updated by <code>fdb-history-sync.php</code> every minute.
                        The sync script must be running as a cron job — see the plugin README for setup.
                        @if($hasVendors)
                        OUI vendor names are enabled.
                        @else
                        OUI vendor column requires the <code>vendors</code> table (added in newer LibreNMS versions).
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        <i class="fa fa-keyboard-o" aria-hidden="true"></i>
                        Search Examples
                    </h3>
                </div>
                <div class="panel-body">
                    <table class="table table-condensed" style="margin-bottom: 0;">
                        <tbody>
                            <tr>
                                <td><code>aa:bb:cc:dd:ee:ff</code></td>
                                <td class="text-muted">exact MAC</td>
                            </tr>
                            <tr>
                                <td><code>aabbccddeeff</code></td>
                                <td class="text-muted">no separators</td>
                            </tr>
                            <tr>
                                <td><code>aa-bb-cc-dd-ee-ff</code></td>
                                <td class="text-muted">dashes</td>
                            </tr>
                            <tr>
                                <td><code>aa:bb:cc</code></td>
                                <td class="text-muted">OUI prefix (partial)</td>
                            </tr>
                            <tr>
                                <td><code>aabb</code></td>
                                <td class="text-muted">any partial hex</td>
                            </tr>
                        </tbody>
                    </table>
                    <hr style="margin: 8px 0;">
                    <p class="text-muted" style="font-size: 12px; margin-bottom: 0;">
                        Combine MAC search with Device, Port, or VLAN filters for precise results.
                        JSON API: append <code>&amp;format=json</code> to any search URL.
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if($overview && $overview->total == 0)
    <div class="row">
        <div class="col-md-10">
            <div class="alert alert-info">
                <i class="fa fa-info-circle" aria-hidden="true"></i>
                <strong>No history records yet.</strong>
                The <code>fdb_history</code> table exists but is empty.
                Make sure the cron job is configured and has run at least once.
            </div>
        </div>
    </div>
    @endif
    @endif {{-- landing page --}}

</div>{{-- .container-fluid --}}

<script>
(function () {
    var baseUrl = '{{ url("plugin/FdbHistory") }}';
    var initPort = {{ $port_id }};
    var initVlan = {{ $vlan_num }};

    function loadPorts(deviceId, selectPortId) {
        var sel = document.getElementById('fdbh-port');
        sel.innerHTML = '<option value="">Loading\u2026</option>';
        if (!deviceId) {
            sel.innerHTML = '<option value="">— All Ports —</option>';
            loadVlans(deviceId, 0);
            return;
        }
        fetch(baseUrl + '?format=json&action=ports&device=' + deviceId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                sel.innerHTML = '<option value="">— All Ports —</option>';
                data.forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = p.port_id;
                    o.textContent = p.label;
                    if (p.port_id == selectPortId) { o.selected = true; }
                    sel.appendChild(o);
                });
            })
            .catch(function () {
                sel.innerHTML = '<option value="">— error loading ports —</option>';
            });
    }

    function loadVlans(deviceId, selectVlan) {
        var sel = document.getElementById('fdbh-vlan');
        sel.innerHTML = '<option value="">Loading\u2026</option>';
        if (!deviceId) {
            sel.innerHTML = '<option value="">— All VLANs —</option>';
            return;
        }
        fetch(baseUrl + '?format=json&action=vlans&device=' + deviceId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                sel.innerHTML = '<option value="">— All VLANs —</option>';
                data.forEach(function (v) {
                    var o = document.createElement('option');
                    o.value = v.vlan_vlan;
                    o.textContent = v.label;
                    if (v.vlan_vlan == selectVlan) { o.selected = true; }
                    sel.appendChild(o);
                });
            })
            .catch(function () {
                sel.innerHTML = '<option value="">— error loading VLANs —</option>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var deviceSel = document.getElementById('fdbh-device');

        deviceSel.addEventListener('change', function () {
            loadPorts(this.value, 0);
            loadVlans(this.value, 0);
        });

        // On page load, populate port/vlan if a device is already selected
        if (deviceSel.value) {
            loadPorts(deviceSel.value, initPort);
            loadVlans(deviceSel.value, initVlan);
        }
    });
}());
</script>
