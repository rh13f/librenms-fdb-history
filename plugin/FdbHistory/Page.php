<?php

/**
 * FdbHistory Plugin — Page hook
 *
 * Provides the search UI at /plugin/FdbHistory.
 * Accepts ?mac=, ?device=, ?port=, ?vlan= query parameters and returns
 * historical port mappings from fdb_history joined with devices/ports/vlans.
 * Add ?format=json to get a JSON response instead of the HTML view.
 */

namespace App\Plugins\FdbHistory;

use App\Plugins\Hooks\PageHook;
use App\Plugins\FdbHistory\Support\FdbHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class Page extends PageHook
{
    public function authorize(Authenticatable $user): bool
    {
        return auth()->user()?->can('global-read') ?? false;
    }

    /**
     * PageHook::data() takes no parameters. Use the request() helper to access
     * the current HTTP request rather than injecting it as a parameter.
     */
    public function data(): array
    {
        $request = request();

        // --- Parse request params ---
        $raw_mac   = trim($request->get('mac', ''));
        $device_id = (int) $request->get('device', 0);
        $port_id   = (int) $request->get('port', 0);
        $vlan_num  = (int) $request->get('vlan', 0);

        $has_search = $raw_mac !== '' || $device_id > 0 || $port_id > 0 || $vlan_num > 0;

        // --- Check vendors table availability (may be absent on older installs) ---
        $hasVendors = false;
        try {
            $hasVendors = DB::connection()->getSchemaBuilder()->hasTable('vendors');
        } catch (\Exception $e) {}

        $results = collect();
        $error   = null;
        $stats   = null;

        if ($has_search) {
            // Validate and normalise MAC input if provided
            $search_mac = '';
            if ($raw_mac !== '') {
                $search_mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $raw_mac));
                if (strlen($search_mac) === 0) {
                    $error = 'Invalid MAC address — please enter at least a few hex characters.';
                } elseif (strlen($search_mac) > 12) {
                    $error = 'MAC address too long. A full MAC is 12 hex characters (e.g. aabbccddeeff).';
                }
            }

            if (!$error) {
                try {
                    $query = DB::table('fdb_history as h')
                        ->select([
                            'h.id',
                            'h.mac_address',
                            'h.device_id',
                            'h.port_id',
                            'h.vlan_id',
                            'h.first_seen',
                            'h.last_seen',
                            'd.hostname',
                            'd.sysName',
                            'p.ifName',
                            'p.ifDescr',
                            'p.ifAlias',
                            'v.vlan_vlan',
                            'v.vlan_name',
                        ])
                        ->leftJoin('devices as d', 'd.device_id', '=', 'h.device_id')
                        ->leftJoin('ports as p', 'p.port_id', '=', 'h.port_id')
                        ->leftJoin('vlans as v', 'v.vlan_id', '=', 'h.vlan_id')
                        ->orderByDesc('h.last_seen')
                        ->limit(1000);

                    if ($hasVendors) {
                        $query->addSelect('ven.vendor')
                            ->leftJoin('vendors as ven', function ($join) {
                                $join->whereRaw('ven.oui = UPPER(LEFT(h.mac_address, 6))');
                            });
                    }

                    if ($search_mac !== '') {
                        $query->whereRaw(
                            "REPLACE(REPLACE(h.mac_address, ':', ''), '-', '') LIKE ?",
                            ['%' . $search_mac . '%']
                        );
                    }

                    if ($device_id > 0) {
                        $query->where('h.device_id', '=', $device_id);
                    }

                    if ($port_id > 0) {
                        $query->where('h.port_id', '=', $port_id);
                    }

                    if ($vlan_num > 0) {
                        $query->where('v.vlan_vlan', '=', $vlan_num);
                    }

                    $results = $query->get();

                    if ($results->isNotEmpty()) {
                        $stats = [
                            'total'          => $results->count(),
                            'unique_macs'    => $results->pluck('mac_address')->unique()->count(),
                            'unique_devices' => $results->pluck('device_id')->filter()->unique()->count(),
                        ];
                    }
                } catch (\Exception $e) {
                    $error = 'Query failed: ' . $e->getMessage()
                        . ' — Is the fdb_history table created? (run fdb_history_setup.sql)';
                }
            }
        }

        // --- JSON API: short-circuit before returning HTML view data ---
        if ($request->get('format') === 'json') {  // $request still in scope from above
            response()->json([
                'query' => [
                    'mac'    => $raw_mac ?: null,
                    'device' => $device_id ?: null,
                    'port'   => $port_id ?: null,
                    'vlan'   => $vlan_num ?: null,
                ],
                'count'   => $results->count(),
                'results' => $results->map(fn ($r) => [
                    'mac_address'   => $r->mac_address,
                    'mac_formatted' => FdbHelpers::fmtMac($r->mac_address),
                    'vendor'        => $hasVendors ? ($r->vendor ?? null) : null,
                    'hostname'      => $r->hostname,
                    'sysName'       => $r->sysName,
                    'device_id'     => $r->device_id,
                    'port_id'       => $r->port_id,
                    'ifName'        => $r->ifName,
                    'ifDescr'       => $r->ifDescr,
                    'ifAlias'       => $r->ifAlias,
                    'vlan_vlan'     => $r->vlan_vlan,
                    'vlan_name'     => $r->vlan_name,
                    'first_seen'    => $r->first_seen,
                    'last_seen'     => $r->last_seen,
                ])->values()->all(),
            ])->send();
            exit(0);
        }

        // --- History table overview stats (shown on landing page) ---
        $overview = null;
        if (!$has_search) {
            try {
                $overview = DB::table('fdb_history')
                    ->selectRaw('COUNT(*) as total, COUNT(DISTINCT mac_address) as unique_macs, MIN(first_seen) as oldest, MAX(last_seen) as newest')
                    ->first();
            } catch (\Exception $e) {
                // Table might not exist yet — handled gracefully in the view
            }
        }

        // --- Device dropdown (distinct devices that appear in fdb_history) ---
        $devices = collect();
        try {
            $devices = DB::table('devices as d')
                ->join('fdb_history as h', 'h.device_id', '=', 'd.device_id')
                ->select(['d.device_id', 'd.hostname', 'd.sysName'])
                ->distinct()
                ->orderBy('d.hostname')
                ->get();
        } catch (\Exception $e) {}

        return [
            'raw_mac'    => $raw_mac,
            'device_id'  => $device_id,
            'port_id'    => $port_id,
            'vlan_num'   => $vlan_num,
            'has_search' => $has_search,
            'results'    => $results,
            'error'      => $error,
            'stats'      => $stats,
            'overview'   => $overview,
            'hasVendors' => $hasVendors,
            'devices'    => $devices,
        ];
    }
}
