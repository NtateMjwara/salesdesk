<?php
/**
 * SalesDesk — Shared filter whitelists
 *
 * Single source of truth for fuel_type / transmission / drivetrain
 * filter values. These must match exactly what app/dealer/car-upload.php's
 * wizard writes to cars.fuel_type / cars.transmission / cars.drivetrain.
 *
 * WHY THIS FILE EXISTS: cars-for-sale/index.php and broker/index.php each
 * used to keep their own hardcoded copies of these arrays. They drifted —
 * cars-for-sale/index.php got a whitelist fix (matching car-upload.php's
 * real values) that broker/index.php never received, so broker storefront
 * filters were silently broken (checking a box that could never match a
 * real row). One shared file means that class of bug can't happen again:
 * fix it here once, both pages pick it up.
 *
 * If car-upload.php's wizard options ever change, update ONLY this file.
 */

declare(strict_types=1);

function sdFuelTypeWhitelist(): array
{
    return [
        'Petrol', 'Diesel', 'Electric', 'Hybrid', 'Plug-in Hybrid (PHEV)',
        'Hydrogen', 'LPG (Autogas)', 'CNG (Natural Gas)', 'Flex Fuel (E85/Ethanol)',
    ];
}

function sdTransmissionWhitelist(): array
{
    return ['Automatic', 'Manual', 'Semi-Automatic', 'DSG', 'CVT'];
}

function sdDrivetrainWhitelist(): array
{
    return ['FWD', 'RWD', 'AWD', '4WD'];
}
