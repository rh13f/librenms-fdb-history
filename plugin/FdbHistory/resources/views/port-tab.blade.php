{{--
    FDB History — port-tab.blade.php
    Shows MAC address history for a single port, rendered inside the port
    detail tab panel in LibreNMS.
    Variables injected by PortTab.php::data():
        $port       — App\Models\Port instance
        $results    — Collection of history rows (max 500, ordered last_seen DESC)
        $hasVendors — bool: vendors table available for OUI lookups
--}}

<div style="padding: 10px 0;">

    <div style="margin-bottom: 12px;">
        <h4 style="margin-top: 0; display: inline;">
            <i class="fa fa-history" aria-hidden="true"></i>
            MAC Address History
        </h4>
        <a
            href="{{ url('plugin/FdbHistory') }}?port={{ $port->port_id }}"
            class="btn btn-xs btn-default pull-right"
            title="Open full FDB History search filtered to this port"
        >
            <i class="fa fa-external-link" aria-hidden="true"></i>
            View in FDB History
        </a>
    </div>

    @if($results->isEmpty())

        <p class="text-muted">
            <i class="fa fa-info-circle" aria-hidden="true"></i>
            No MAC address history recorded for this port.
            @php $fdbUrl = url('plugin/FdbHistory'); @endphp
            Check that <code>fdb-history-sync.php</code> is running and that LibreNMS
            is polling FDB tables from this device.
        </p>

    @else

        @if($results->count() >= 500)
        <div class="alert alert-warning" style="padding: 6px 12px; margin-bottom: 10px;">
            <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            Showing first 500 results.
            <a href="{{ url('plugin/FdbHistory') }}?port={{ $port->port_id }}">View all in FDB History &rarr;</a>
        </div>
        @endif

        <div class="table-responsive">
            <table class="table table-condensed table-striped table-hover" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>MAC Address</th>
                        @if($hasVendors)<th>Vendor</th>@endif
                        <th>VLAN</th>
                        <th>First Seen</th>
                        <th>Last Seen</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $row)
                    @php
                        $mac_fmt      = \App\Plugins\FdbHistory\Support\FdbHelpers::fmtMac($row->mac_address);
                        $last_seen_ts = $row->last_seen ? strtotime($row->last_seen) : 0;
                        $age_minutes  = $last_seen_ts ? (time() - $last_seen_ts) / 60 : PHP_INT_MAX;
                        $is_active    = $age_minutes < 20;
                        $is_recent    = $age_minutes < 120;
                    @endphp
                    <tr>
                        {{-- MAC Address (links to full history search) --}}
                        <td>
                            <code>
                                <a
                                    href="{{ url('plugin/FdbHistory') }}?mac={{ urlencode($row->mac_address) }}"
                                    title="Search full history for this MAC"
                                >{{ $mac_fmt }}</a>
                            </code>
                        </td>

                        {{-- Vendor (OUI lookup) --}}
                        @if($hasVendors)
                        <td>
                            <span class="text-muted small">{{ $row->vendor ?? '—' }}</span>
                        </td>
                        @endif

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
                            <span title="{{ $row->first_seen }}">{{ $row->first_seen }}</span>
                        </td>

                        {{-- Last Seen --}}
                        <td>
                            <span title="{{ $row->last_seen }}">{{ $row->last_seen }}</span>
                        </td>

                        {{-- Status badge --}}
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
        </div>

        <p class="text-muted small" style="margin-top: 6px; margin-bottom: 0;">
            {{ $results->count() }} record(s).
            Active = seen &lt; 20 min ago &nbsp;|&nbsp;
            Recent = seen &lt; 2 hr ago.
            <a href="{{ url('plugin/FdbHistory') }}?port={{ $port->port_id }}&format=json" target="_blank">
                <i class="fa fa-code" aria-hidden="true"></i> JSON
            </a>
        </p>

    @endif

</div>
