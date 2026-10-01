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

/**
 * Car makes a desk organisation can sell (0013) — same list the dealer
 * upload wizard offers (app/dealer/car-upload.php), so an org's brands
 * always match real cars.make values. Matching against cars.make is
 * case-insensitive (utf8mb4_unicode_ci), so importer values such as
 * "VOLKSWAGEN" still match "Volkswagen".
 */
function sdCarMakes(): array
{
    return [
        'Acura','Alfa Romeo','Aston Martin','Audi','BAIC','Bentley','BMW','BYD','Cadillac','Changan',
        'Chery','Chevrolet','Chrysler','Citroën','Daihatsu','Datsun','Dodge','Ferrari','Fiat','Ford',
        'Foton','Geely','Genesis','GWM','Haval','Honda','Hyundai','Infiniti','Isuzu','JAC','Jaecoo',
        'Jaguar','Jeep','Jetour','Kia','Lamborghini','Land Rover','LDV','Lexus','Lincoln','Mahindra',
        'Maserati','Mazda','McLaren','Mercedes-Benz','MG','Mini','Mitsubishi','NIO','Nissan','OMODA',
        'Opel','Peugeot','Polestar','Porsche','RAM','Range Rover','Renault','Rivian','Rolls-Royce',
        'SEAT','Skoda','Smart','Ssangyong','Subaru','Suzuki','Tata','Tesla','Toyota','Volkswagen',
        'Volvo','Xpeng','Zeekr',
    ];
}

/**
 * Other spellings of a make seen in cars.make (dealer typing, importers),
 * so an org selling "Volkswagen" also matches cars saved as "VW".  (0013)
 * Keys are sdCarMakes() values. Matching is case-insensitive.
 */
function sdCarMakeAliases(): array
{
    return [
        'Volkswagen'    => ['VW', 'Volkswagon'],
        'Mercedes-Benz' => ['Mercedes', 'Mercedes Benz', 'Merc', 'MB'],
        'Land Rover'    => ['Landrover', 'Land-Rover'],
        'Range Rover'   => ['Range-Rover'],
        'Citroën'       => ['Citroen'],
        'GWM'           => ['Great Wall', 'Great Wall Motors'],
        'Alfa Romeo'    => ['Alfa'],
        'Rolls-Royce'   => ['Rolls Royce'],
        'Ssangyong'     => ['SsangYong', 'KGM'],
        'Mini'          => ['MINI Cooper'],
        'Chevrolet'     => ['Chev', 'Chevy'],
        'Mahindra'      => ['Mahindra & Mahindra'],
        'OMODA'         => ['Omoda'],
    ];
}

/** A brand plus all its aliases. */
function sdMakeVariants(string $make): array
{
    return array_values(array_unique(array_merge([$make], sdCarMakeAliases()[$make] ?? [])));
}
