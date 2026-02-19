<?php

/**
 * FdbHistory Plugin — PortTab hook
 *
 * Adds a "FDB History" tab to each port's detail page in LibreNMS,
 * showing all MAC addresses ever seen on that port with timestamps.
 *
 * NOTE: Requires PortTabHook to exist at:
 *   /opt/librenms/app/Plugins/Hooks/PortTabHook.php
 * Verify this file is present after deploying to your LibreNMS instance.
 * The data() signature Port $port must match the hook's expected interface.
 */

namespace App\Plugins\FdbHistory;

use App\Models\Port;
use App\Models\User;
use App\Plugins\FdbHistory\Support\FdbHelpers;
use App\Plugins\Hooks\PortTabHook;
use Illuminate\Support\Facades\DB;

class PortTab extends PortTabHook
{
    public string $view = 'port-tab';

    public function authorize(User $user, Port $port): bool
    {
        return $user->can('global-read');
    }

    public function data(Port $port, array $settings = []): array
    {
        // Check vendors table availability (may be absent on older installs)
        $hasVendors = false;
        try {
            $hasVendors = DB::connection()->getSchemaBuilder()->hasTable('vendors');
        } catch (\Exception $e) {}

        $query = DB::table('fdb_history as h')
            ->select([
                'h.id',
                'h.mac_address',
                'h.vlan_id',
                'h.first_seen',
                'h.last_seen',
                'v.vlan_vlan',
                'v.vlan_name',
            ])
            ->leftJoin('vlans as v', 'v.vlan_id', '=', 'h.vlan_id')
            ->where('h.port_id', '=', $port->port_id)
            ->orderByDesc('h.last_seen')
            ->limit(500);

        if ($hasVendors) {
            $query->addSelect('ven.vendor')
                ->leftJoin('vendors as ven', function ($join) {
                    $join->whereRaw('ven.oui = UPPER(LEFT(h.mac_address, 6))');
                });
        }

        $results = collect();
        try {
            $results = $query->get();
        } catch (\Exception $e) {
            // fdb_history table may not exist yet — view handles empty collection gracefully
        }

        return [
            'port'       => $port,
            'results'    => $results,
            'hasVendors' => $hasVendors,
        ];
    }
}
