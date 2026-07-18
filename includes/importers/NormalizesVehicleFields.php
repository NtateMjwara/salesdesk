<?php
/**
 * SalesDesk — Shared vehicle-field normalization.
 *
 * WHY THIS EXISTS:
 *   CsvImporter already contains a full set of normalize*() helpers
 *   (condition, body type, transmission, fuel type, drivetrain, number
 *   parsing, date parsing, etc.) tuned to match the exact vocabulary
 *   app/dealer/car-upload.php's dropdowns write to the DB — see the
 *   "CORRECTED" comments throughout CsvImporter.php explaining why each
 *   one matches the wizard's dropdown values rather than some other
 *   whitelist elsewhere in the codebase.
 *
 *   WebsiteImporter needs the exact same vocabulary — a car imported from
 *   a dealer's website must be filterable/sortable identically to one
 *   imported from CSV or typed in by hand. Duplicating that logic instead
 *   of sharing it is how CsvImporter and c/index.php's whitelist drifted
 *   apart in the first place (see CsvImporter's normalizeFuelType
 *   docblock). This trait is the fix for that failure mode, applied
 *   proactively instead of after the fact.
 *
 * MIGRATION NOTE — one small change needed in CsvImporter.php:
 *   Add `use NormalizesVehicleFields;` inside the CsvImporter class body
 *   and delete its now-duplicate private methods:
 *     normalizeCondition, normalizeCommissionType, normalizeFuelType,
 *     normalizeTransmission, normalizeDrivetrain, normalizeBodyType,
 *     parseNumber, parseSmallUint, parseIntOrNull, parseDecimal,
 *     parseBool, parseDateSafe
 *   (normalizeServiceHistory / normalizeWarrantyType / parseEngineCapacityCc
 *   / normalizeInduction / parsePowerKw stay put — they're CSV-specific
 *   enough, and not currently needed by WebsiteImporter's Phase 1 scope,
 *   that duplicating them isn't worth the churn yet.)
 *   Nothing else in CsvImporter changes — every method below is a
 *   verbatim copy of CsvImporter's existing logic, not a rewrite.
 */

trait NormalizesVehicleFields
{
    private function normalizeCondition(string $raw): string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            in_array($v, ['new'], true)                            => 'new',
            in_array($v, ['demo', 'demonstrator', 'pre-reg'], true) => 'demo',
            default                                                 => 'used',
        };
    }

    private function normalizeCommissionType(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        return match (true) {
            in_array($v, ['fixed', 'flat', 'flat fee', 'r', 'rand', 'amount'], true) => 'fixed',
            in_array($v, ['percentage', 'percent', '%'], true)                       => 'percentage',
            default => throw new InvalidArgumentException(
                "Unrecognized commission_type \"{$raw}\" — expected fixed/flat or percentage/percent."
            ),
        };
    }

    /** Matches app/dealer/car-upload.php's $fuelTypes dropdown exactly. */
    private function normalizeFuelType(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'electric')                               => 'Electric',
            str_contains($v, 'plug-in') || str_contains($v, 'phev')     => 'Plug-in Hybrid (PHEV)',
            str_contains($v, 'hybrid')                                  => 'Hybrid',
            str_contains($v, 'hydrogen')                                => 'Hydrogen',
            str_contains($v, 'cng') || str_contains($v, 'natural gas')  => 'CNG (Natural Gas)',
            str_contains($v, 'flex') || str_contains($v, 'e85') || str_contains($v, 'ethanol') => 'Flex Fuel (E85/Ethanol)',
            str_contains($v, 'diesel')                                  => 'Diesel',
            str_contains($v, 'lpg') || str_contains($v, 'autogas')      => 'LPG (Autogas)',
            str_contains($v, 'petrol') || str_contains($v, 'gasoline') || str_contains($v, 'unleaded') => 'Petrol',
            default                                                     => ucfirst($v),
        };
    }

    /** Matches app/dealer/car-upload.php's $transmissions dropdown exactly. */
    private function normalizeTransmission(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'dsg') || str_contains($v, 'dual-clutch') || str_contains($v, 'dual clutch') => 'DSG',
            str_contains($v, 'cvt')                                    => 'CVT',
            str_contains($v, 'semi')                                   => 'Semi-Automatic',
            str_contains($v, 'auto') || preg_match('/\ba\/t\b/', $v)   => 'Automatic',
            str_contains($v, 'manual') || preg_match('/\bm\/t\b/', $v) => 'Manual',
            default                                                    => ucfirst($v),
        };
    }

    /** Matches app/dealer/car-upload.php's $drivetrains dropdown exactly. */
    private function normalizeDrivetrain(string $raw): string
    {
        $v = strtoupper(trim($raw));
        $v = str_replace([' ', '-'], '', $v);
        return match (true) {
            in_array($v, ['FWD', 'FRONTWHEELDRIVE'], true)       => 'FWD',
            in_array($v, ['RWD', 'REARWHEELDRIVE'], true)        => 'RWD',
            in_array($v, ['AWD', 'ALLWHEELDRIVE'], true)         => 'AWD',
            in_array($v, ['4WD', '4X4', 'FOURWHEELDRIVE'], true) => '4WD',
            default                                               => $v ? ucfirst(strtolower($raw)) : '',
        };
    }

    /** Matches app/dealer/car-upload.php's $bodyTypes dropdown exactly. */
    private function normalizeBodyType(string $raw): string
    {
        $v = strtolower(trim($raw));
        if ($v === '') return '';
        return match (true) {
            str_contains($v, 'crossover')                                => 'Crossover',
            str_contains($v, 'suv') || str_contains($v, '4x4')           => 'SUV',
            str_contains($v, 'bakkie') || str_contains($v, 'pickup') || str_contains($v, 'pick-up') => 'Bakkie',
            str_contains($v, 'hatch')                                    => 'Hatchback',
            str_contains($v, 'sedan') || str_contains($v, 'saloon')      => 'Sedan',
            str_contains($v, 'coupe')                                    => 'Coupe',
            str_contains($v, 'convertible') || str_contains($v, 'cabrio')=> 'Convertible',
            str_contains($v, 'wagon') || str_contains($v, 'estate')      => 'Station Wagon',
            str_contains($v, 'minibus') || str_contains($v, 'taxi')      => 'Minibus',
            str_contains($v, 'van')                                      => 'Van',
            str_contains($v, 'truck')                                    => 'Truck',
            str_contains($v, 'mpv')                                      => 'MPV',
            default                                                      => ucfirst($v),
        };
    }

    /**
     * Extracts only the leading numeric token (see CsvImporter's original
     * docblock on this method for why: units like "l/100km" or "g/km"
     * break a naive strip-all-non-digits approach).
     */
    private function parseNumber(string $raw): ?float
    {
        $clean = trim($raw);
        if ($clean === '') {
            return null;
        }
        $clean = str_replace([',', ' ', 'R'], '', $clean);
        if (!preg_match('/^-?\d+(?:\.\d+)?/', $clean, $m)) {
            return null;
        }
        return (float) $m[0];
    }

    private function parseSmallUint(string $raw): ?int
    {
        $n = $this->parseNumber($raw);
        return ($n !== null && $n >= 0) ? (int) $n : null;
    }

    private function parseIntOrNull(string $raw): ?int
    {
        $n = $this->parseNumber($raw);
        return $n !== null ? (int) $n : null;
    }

    private function parseDecimal(string $raw): ?float
    {
        return $this->parseNumber($raw);
    }

    private function parseBool(string $raw, bool $default = false): int
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return $default ? 1 : 0;
        }
        return in_array($v, ['1', 'yes', 'true', 'y'], true) ? 1 : 0;
    }

    /** dd/mm/yyyy-safe date parsing — see CsvImporter's original docblock. */
    private function parseDateSafe(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') return null;

        if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $v, $m)) {
            [, $day, $month, $year] = $m;
            $dayI = (int) $day; $monthI = (int) $month; $yearI = (int) $year;
            if (!checkdate($monthI, $dayI, $yearI)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $yearI, $monthI, $dayI);
        }

        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
