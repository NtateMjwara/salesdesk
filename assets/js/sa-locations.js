/**
 * sa-locations.js
 * South African provinces → municipalities → cities → suburbs
 * Dependent-filter data for adopt-a-school.php
 *
 * Structure:
 *   SA_LOCATIONS.municipalities[province]         → string[]
 *   SA_LOCATIONS.cities[municipality]             → string[]
 *   SA_LOCATIONS.suburbs[city]                    → string[]
 *
 * NOTE: This is a starter set. Fill in the remaining entries
 *       by following the same pattern for every province / municipality.
 */
const SA_LOCATIONS = {

  /* ─────────────────────────────────────────────
   * PROVINCES  (matches the ENUM in school_filter)
   * ───────────────────────────────────────────── */
  provinces: [
    "Eastern Cape",
    "Free State",
    "Gauteng",
    "KwaZulu-Natal",
    "Limpopo",
    "Mpumalanga",
    "North West",
    "Northern Cape",
    "Western Cape"
  ],

  /* ─────────────────────────────────────────────
   * MUNICIPALITIES  keyed by province
   * ───────────────────────────────────────────── */
  municipalities: {
    "Eastern Cape": [
      "Buffalo City",
      "Nelson Mandela Bay",
      "Amathole",
      "Chris Hani",
      "Joe Gqabi",
      "OR Tambo",
      "Alfred Nzo",
      "Sarah Baartman",
      "Enoch Mgijima"
    ],
    "Free State": [
      "Mangaung",
      "Lejweleputswa",
      "Thabo Mofutsanyane",
      "Fezile Dabi",
      "Xhariep"
    ],
    "Gauteng": [
      "City of Johannesburg",
      "City of Tshwane",
      "Ekurhuleni",
      "Sedibeng",
      "West Rand"
    ],
    "KwaZulu-Natal": [
      "eThekwini",
      "uMgungundlovu",
      "King Cetshwayo",
      "iLembe",
      "Ugu",
      "uThukela",
      "Amajuba",
      "Zululand",
      "uMkhanyakude",
      "Harry Gwala",
      "uMzinyathi"
    ],
    "Limpopo": [
      "Polokwane",
      "Mopani",
      "Vhembe",
      "Capricorn",
      "Sekhukhune",
      "Waterberg"
    ],
    "Mpumalanga": [
      "Ehlanzeni",
      "Gert Sibande",
      "Nkangala"
    ],
    "North West": [
      "Bojanala Platinum",
      "Dr Kenneth Kaunda",
      "Ngaka Modiri Molema",
      "Dr Ruth Segomotsi Mompati"
    ],
    "Northern Cape": [
      "Frances Baard",
      "ZF Mgcawu",
      "John Taolo Gaetsewe",
      "Pixley ka Seme",
      "Namakwa"
    ],
    "Western Cape": [
      "City of Cape Town",
      "Drakenstein",
      "Stellenbosch",
      "George",
      "Knysna-Bitou",
      "Overberg",
      "West Coast",
      "Cape Winelands",
      "Oudtshoorn",
      "Mossel Bay"
    ]
  },

  /* ─────────────────────────────────────────────
   * CITIES  keyed by municipality
   * ───────────────────────────────────────────── */
  cities: {
    /* — GAUTENG — */
    "City of Johannesburg": [
      "Johannesburg",
      "Soweto",
      "Randburg",
      "Roodepoort",
      "Sandton",
      "Alexandra",
      "Lenasia",
      "Midrand",
      "Diepsloot"
    ],
    "City of Tshwane": [
      "Pretoria",
      "Centurion",
      "Soshanguve",
      "Mamelodi",
      "Atteridgeville",
      "Bronkhorstspruit"
    ],
    "Ekurhuleni": [
      "Boksburg",
      "Benoni",
      "Germiston",
      "Kempton Park",
      "Edenvale",
      "Brakpan",
      "Springs",
      "Alberton",
      "Tembisa"
    ],
    "Sedibeng": [
      "Vereeniging",
      "Vanderbijlpark",
      "Meyerton",
      "Evaton"
    ],
    "West Rand": [
      "Krugersdorp",
      "Randfontein",
      "Westonaria",
      "Carletonville"
    ],

    /* — WESTERN CAPE — */
    "City of Cape Town": [
      "Cape Town",
      "Bellville",
      "Mitchell's Plain",
      "Khayelitsha",
      "Parow",
      "Goodwood",
      "Strand",
      "Somerset West",
      "Paarl"
    ],
    "Drakenstein": [
      "Paarl",
      "Wellington",
      "Franschhoek"
    ],
    "Stellenbosch": [
      "Stellenbosch",
      "Franschhoek",
      "Somerset West"
    ],
    "George": [
      "George",
      "Wilderness",
      "Uniondale"
    ],

    /* — KWAZULU-NATAL — */
    "eThekwini": [
      "Durban",
      "Pinetown",
      "Umlazi",
      "Phoenix",
      "Chatsworth",
      "KwaMashu",
      "Tongaat",
      "Amanzimtoti"
    ],
    "uMgungundlovu": [
      "Pietermaritzburg",
      "Howick",
      "Mpophomeni"
    ],
    "King Cetshwayo": [
      "Richards Bay",
      "Empangeni",
      "Eshowe"
    ],

    /* — EASTERN CAPE — */
    "Buffalo City": [
      "East London",
      "Bhisho",
      "King William's Town"
    ],
    "Nelson Mandela Bay": [
      "Port Elizabeth (Gqeberha)",
      "Uitenhage",
      "Despatch"
    ],

    /* — FREE STATE — */
    "Mangaung": [
      "Bloemfontein",
      "Botshabelo",
      "Thaba Nchu"
    ],
    "Lejweleputswa": [
      "Welkom",
      "Odendaalsrus",
      "Virginia"
    ],

    /* — LIMPOPO — */
    "Polokwane": [
      "Polokwane",
      "Mankweng",
      "Seshego"
    ],
    "Vhembe": [
      "Thohoyandou",
      "Louis Trichardt",
      "Musina"
    ],
    "Mopani": [
      "Tzaneen",
      "Giyani",
      "Phalaborwa"
    ],

    /* — MPUMALANGA — */
    "Ehlanzeni": [
      "Mbombela (Nelspruit)",
      "White River",
      "Hazyview",
      "Bushbuckridge"
    ],
    "Gert Sibande": [
      "Ermelo",
      "Secunda",
      "Standerton",
      "Bethal"
    ],
    "Nkangala": [
      "Middelburg",
      "Witbank (eMalahleni)",
      "Delmas",
      "Hendrina"
    ],

    /* — NORTH WEST — */
    "Bojanala Platinum": [
      "Rustenburg",
      "Brits",
      "Swartruggens",
      "Mooinooi"
    ],
    "Dr Kenneth Kaunda": [
      "Klerksdorp",
      "Potchefstroom",
      "Stilfontein"
    ],
    "Ngaka Modiri Molema": [
      "Mahikeng",
      "Lichtenburg",
      "Delareyville"
    ],

    /* — NORTHERN CAPE — */
    "Frances Baard": [
      "Kimberley",
      "Barkly West",
      "Hartswater"
    ],
    "ZF Mgcawu": [
      "Upington",
      "Kakamas",
      "Keimoes"
    ]
  },

  /* ─────────────────────────────────────────────
   * SUBURBS  keyed by city
   * ───────────────────────────────────────────── */
  suburbs: {
    /* — JOHANNESBURG — */
    "Johannesburg": [
      "Sandton",
      "Rosebank",
      "Braamfontein",
      "Parktown",
      "Melville",
      "Newtown",
      "Hillbrow",
      "Yeoville",
      "Fordsburg",
      "Auckland Park"
    ],
    "Soweto": [
      "Orlando East",
      "Orlando West",
      "Diepkloof",
      "Meadowlands",
      "Dobsonville",
      "Dube",
      "Naledi",
      "Zola",
      "Kliptown"
    ],
    "Randburg": [
      "Ferndale",
      "Blackheath",
      "Linden",
      "Northcliff",
      "Robindale"
    ],
    "Sandton": [
      "Morningside",
      "Rivonia",
      "Hyde Park",
      "Bryanston",
      "Fourways",
      "Sunninghill"
    ],
    "Midrand": [
      "Halfway House",
      "Vorna Valley",
      "Kyalami",
      "Waterfall"
    ],

    /* — PRETORIA — */
    "Pretoria": [
      "Arcadia",
      "Brooklyn",
      "Hatfield",
      "Menlyn",
      "Muckleneuk",
      "Lynnwood",
      "Sunnyside",
      "Centurion North",
      "Garsfontein"
    ],
    "Centurion": [
      "Irene",
      "Lyttelton",
      "Highveld",
      "Zwartkop"
    ],
    "Soshanguve": [
      "Block A",
      "Block B",
      "Block GG",
      "Block HH"
    ],
    "Mamelodi": [
      "Mamelodi East",
      "Mamelodi West",
      "Denneboom"
    ],

    /* — CAPE TOWN — */
    "Cape Town": [
      "City Bowl",
      "Green Point",
      "Sea Point",
      "Camps Bay",
      "Claremont",
      "Newlands",
      "Rondebosch",
      "Observatory",
      "Woodstock",
      "De Waterkant"
    ],
    "Khayelitsha": [
      "Site C",
      "Site B",
      "Harare",
      "Makhaza",
      "Town 2"
    ],
    "Mitchell's Plain": [
      "Tafelsig",
      "Lentegeur",
      "Rocklands",
      "Portland",
      "Eastridge"
    ],
    "Bellville": [
      "Bellville CBD",
      "Oakdale",
      "Welgemoed",
      "Kenridge"
    ],

    /* — DURBAN — */
    "Durban": [
      "Berea",
      "Glenwood",
      "Musgrave",
      "Umbilo",
      "Overport",
      "Greyville",
      "Morningside Durban",
      "Florida Road",
      "Point",
      "CBD"
    ],
    "Pinetown": [
      "New Germany",
      "Westmead",
      "Marianhill",
      "Pinecrest"
    ],

    /* — PORT ELIZABETH — */
    "Port Elizabeth (Gqeberha)": [
      "Summerstrand",
      "Walmer",
      "Newton Park",
      "Kabega",
      "Uitenhage Road"
    ],

    /* — EAST LONDON — */
    "East London": [
      "Quigney",
      "Vincent",
      "Selborne",
      "Cambridge",
      "Nahoon"
    ],

    /* — BLOEMFONTEIN — */
    "Bloemfontein": [
      "Westdene",
      "Waverley",
      "Universitas",
      "Fichardt Park",
      "Brandwag",
      "Mangaung Township"
    ],

    /* — EKURHULENI — */
    "Boksburg": [
      "Boksburg North",
      "Parkrand",
      "Vosloorus",
      "Dawn Park"
    ],
    "Benoni": [
      "Actonville",
      "Daveyton",
      "Farrarmere",
      "Crystal Park"
    ],
    "Tembisa": [
      "Ivory Park",
      "Rabie Ridge",
      "Umthambeka"
    ],

    /* — RUSTENBURG — */
    "Rustenburg": [
      "Boitekong",
      "Tlhabane",
      "Waterkloof Rustenburg",
      "Cashan",
      "Safari Gardens"
    ],

    /* — POLOKWANE — */
    "Polokwane": [
      "Bendor",
      "Ivy Park",
      "Fauna Park",
      "Superbia",
      "Seshego Township"
    ],

    /* — MBOMBELA — */
    "Mbombela (Nelspruit)": [
      "Nelspruit CBD",
      "Riverside Park",
      "Sonheuwel",
      "Mataffin",
      "Kanyamazane"
    ],

    /* — KIMBERLEY — */
    "Kimberley": [
      "Galeshewe",
      "Phuthanang",
      "Greenpoint Kimberley",
      "Royldene"
    ]
  }
};

/* Make available globally */
window.SA_LOCATIONS = SA_LOCATIONS;
