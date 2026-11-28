# sa-locator.js — South African location dataset

A JavaScript data file built around a `SA_LOCATIONS` object, used for province / municipality / city / suburb
pickers and filters.

## Structure
- `SA_LOCATIONS.municipalities`, `.cities`, `.suburbs`, organised by province and district.
- District-level municipality keys contain their local municipalities. Suburb arrays sit under city keys.

## State
- All nine provinces have been processed (Limpopo, Mpumalanga, Gauteng, Free State, KwaZulu-Natal, North West,
  Western Cape, Northern Cape, Eastern Cape), with missing municipalities, cities and suburbs added.

## Pitfalls
- Merge into existing keys. Never duplicate them.
- Place names repeat across provinces. Check across provinces before inserting. Known cases: **Richmond** (KZN and
  Northern Cape), **Heidelberg** (several districts), **Panorama** (several provinces).

## How Sfiso likes changes to it
- Minimal changes only. Don't rewrite the file.
- Show additions in three labelled sections (municipalities, cities, suburbs) with inline comments explaining each
  merge decision.
