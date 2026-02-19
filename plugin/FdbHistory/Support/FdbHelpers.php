<?php

/**
 * FdbHistory Plugin — Static helpers
 *
 * Extracted to a static class to avoid "Cannot redeclare" PHP fatals
 * when a blade view is rendered more than once in a single request.
 */

namespace App\Plugins\FdbHistory\Support;

class FdbHelpers
{
    /**
     * Format a raw 12-char MAC string as colon-separated octets.
     * Returns the original value (HTML-escaped) if it cannot be normalised.
     */
    public static function fmtMac(?string $mac): string
    {
        $clean = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $mac));
        if (strlen($clean) !== 12) {
            return htmlspecialchars((string) $mac);
        }
        return implode(':', str_split($clean, 2));
    }

    /**
     * Return the 6-char uppercase OUI prefix for a MAC string, or null.
     * Used for vendors table lookups: UPPER(LEFT(mac_address, 6)).
     */
    public static function ouiPrefix(?string $mac): ?string
    {
        $clean = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $mac));
        return strlen($clean) >= 6 ? strtoupper(substr($clean, 0, 6)) : null;
    }
}
