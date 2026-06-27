/**
 * sa-locations.js
 * South African provinces → municipalities → cities → suburbs
 * Dependent-filter data for adopt-a-school.php
 *
 * Structure:
 *   SA_LOCATIONS.municipalities[province]         → string[]
 *   SA_LOCATIONS.cities[municipality]             → string[]
 *   SA_LOCATIONS.suburbs[city]                    → string[]
 */
const SA_LOCATIONS = {

  /* ─────────────────────────────────────────────
   * PROVINCES
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
      "Enoch Mgijima",
      "Amahlathi",
      "Great Kei",
      "Mbhashe",
      "Mnquma",
      "Ngqushwa",
      "Raymond Mhlaba",
      "Emalahleni",
      "Engcobo",
      "Intsika Yethu",
      "Inkwanca",
      "Lukanji",
      "Sakhisizwe",
      "Tsolwana",
      "Elundini",
      "Maletswai",
      "Senqu",
      "King Sabata Dalindyebo",
      "Mhlontlo",
      "Nyandeni",
      "Port St Johns",
      "Qaukeni",
      "Mbizana",
      "Matatiele",
      "Ntabankulu",
      "Umzimvubu",
      "Blue Crane Route",
      "Camdeboo",
      "Dr Beyers Naudé",
      "Ikwezi",
      "Kouga",
      "Kou-Kamma",
      "Makana",
      "Ndlambe",
      "Sunday's River Valley"
    ],
    "Free State": [
      "Mangaung",
      "Lejweleputswa",
      "Thabo Mofutsanyane",
      "Fezile Dabi",
      "Xhariep",
      "Masilonyana",
      "Matjhabeng",
      "Nala",
      "Tokologo",
      "Tswelopele",
      "Dihlabeng",
      "Maluti-a-Phofung",
      "Mantsopa",
      "Nketoana",
      "Phumelela",
      "Setsoto",
      "Mafube",
      "Metsimaholo",
      "Moqhaka",
      "Ngwathe",
      "Kopanong",
      "Letsemeng",
      "Mohokare"
    ],
    "Gauteng": [
      "City of Johannesburg",
      "City of Tshwane",
      "Ekurhuleni",
      "Sedibeng",
      "West Rand",
      "Emfuleni",
      "Midvaal",
      "Lesedi",
      "Mogale City",
      "Merafong City",
      "Rand West City"
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
      "uMzinyathi",
      "Msunduzi",
      "uMshwathi",
      "uMngeni",
      "Mkhambathini",
      "Richmond",
      "Impendle",
      "Mpofana",
      "uMhlathuze",
      "uMlalazi",
      "Mthonjaneni",
      "Nkandla",
      "Ntambanana",
      "KwaDukuza",
      "Mandeni",
      "Maphumulo",
      "Ndwedwe",
      "Ray Nkonyeni",
      "uMuziwabantu",
      "Ezinqoleni",
      "Hibiscus Coast",
      "Inkosi Langalibalele",
      "Imbabazane",
      "Newcastle",
      "Utrecht",
      "Dannhauser",
      "AbaQulusi",
      "eDumbe",
      "Nongoma",
      "Ulundi",
      "uPhongolo",
      "Big Five Hlabisa",
      "Jozini",
      "Mtubatuba",
      "uMhlabuyalingana",
      "Greater Kokstad",
      "Ingwe",
      "KwaSani",
      "uBuhlebezwe",
      "uMzimkhulu",
      "Endumeni",
      "Msinga",
      "Nquthu"
    ],
    "Limpopo": [
      "Polokwane",
      "Mopani",
      "Vhembe",
      "Capricorn",
      "Sekhukhune",
      "Waterberg",
      "Blouberg",
      "Lepelle-Nkumpi",
      "Molemole",
      "Ba-Phalaborwa",
      "Greater Giyani",
      "Greater Letaba",
      "Greater Tzaneen",
      "Maruleng",
      "Elias Motsoaledi",
      "Ephraim Mogale",
      "Fetakgomo Tubatse",
      "Makhuduthamaga",
      "Collins Chabane",
      "Makhado",
      "Musina",
      "Thulamela",
      "Bela-Bela",
      "Lephalale",
      "Modimolle-Mookgophong",
      "Mogalakwena",
      "Thabazimbi"
    ],
    "Mpumalanga": [
      "Ehlanzeni",
      "Gert Sibande",
      "Nkangala",
      "City of Mbombela",
      "Bushbuckridge",
      "Nkomazi",
      "Thaba Chweu",
      "Msukaligwa",
      "Mkhondo",
      "Chief Albert Luthuli",
      "Dipaleseng",
      "Govan Mbeki",
      "Lekwa",
      "Pixley ka Seme",
      "Victor Khanye",
      "Dr JS Moroka",
      "eMalahleni",
      "Emakhazeni",
      "Steve Tshwete",
      "Thembisile Hani"
    ],
    "North West": [
      "Bojanala Platinum",
      "Dr Kenneth Kaunda",
      "Ngaka Modiri Molema",
      "Dr Ruth Segomotsi Mompati",
      "Kgetlengrivier",
      "Madibeng",
      "Moretele",
      "Moses Kotane",
      "Rustenburg",
      "City of Matlosana",
      "JB Marks",
      "Maquassi Hills",
      "Ditsobotla",
      "Mahikeng",
      "Ramotshere Moiloa",
      "Ratlou",
      "Tswaing",
      "Kagisano-Molopo",
      "Lekwa-Teemane",
      "Mamusa",
      "Naledi",
      "Thanjaneng"
    ],
    "Northern Cape": [
      "Frances Baard",
      "ZF Mgcawu",
      "John Taolo Gaetsewe",
      "Pixley ka Seme",
      "Namakwa",
      "Dikgatlong",
      "Magareng",
      "Phokwane",
      "Sol Plaatje",
      "Dawid Kruiper",
      "Gamagara",
      "Kai !Garib",
      "Kgatelopele",
      "//Khara Hais",
      "Ga-Segonyana",
      "Joe Morolong",
      "Emthanjeni",
      "Kareeberg",
      "Renosterberg",
      "Siyancuma",
      "Siyathemba",
      "Thembelihle",
      "Ubuntu",
      "Umsobomvu",
      "Hantam",
      "Kamiesberg",
      "Karoo Hoogland",
      "Khai-Ma",
      "Nama Khoi",
      "Richtersveld"
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
      "Mossel Bay",
      "Breede Valley",
      "Langeberg",
      "Witzenberg",
      "Garden Route",
      "Bitou",
      "Hessequa",
      "Kannaland",
      "Knysna",
      "Cape Agulhas",
      "Overstrand",
      "Swellendam",
      "Theewaterskloof",
      "Bergrivier",
      "Cederberg",
      "Matzikama",
      "Saldanha Bay",
      "Swartland"
    ]
  },

  /* ─────────────────────────────────────────────
   * CITIES  keyed by municipality
   * ───────────────────────────────────────────── */
  cities: {
    "City of Johannesburg": [
      "Johannesburg",
      "Soweto",
      "Randburg",
      "Roodepoort",
      "Sandton",
      "Alexandra",
      "Lenasia",
      "Midrand",
      "Diepsloot",
      "Johannesburg CBD",
      "Orange Farm",
      "Ennerdale",
      "Ivory Park",
      "Lawley",
      "Eldorado Park",
      "Naturena",
      "Johannesburg South",
      "Marlboro"
    ],
    "City of Tshwane": [
      "Pretoria",
      "Centurion",
      "Soshanguve",
      "Mamelodi",
      "Atteridgeville",
      "Bronkhorstspruit",
      "Pretoria CBD",
      "Hammanskraal",
      "Temba",
      "Ga-Rankuwa",
      "Mabopane",
      "Winterveld",
      "Cullinan",
      "Rayton",
      "Akasia"
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
      "Tembisa",
      "Nigel",
      "Heidelberg",
      "Katlehong",
      "Vosloorus",
      "Thokoza",
      "Daveyton",
      "KwaThema",
      "Tsakane",
      "Duduza",
      "Actonville"
    ],
    "Sedibeng": [
      "Vereeniging",
      "Vanderbijlpark",
      "Meyerton",
      "Evaton",
      "Sebokeng",
      "Sharpeville",
      "Boipatong",
      "Bophelong",
      "Three Rivers",
      "Duncanville"
    ],
    "West Rand": [
      "Krugersdorp",
      "Randfontein",
      "Westonaria",
      "Carletonville",
      "Kagiso",
      "Magaliesburg",
      "Muldersdrift",
      "Munsieville",
      "Tarlton",
      "Hekpoort",
      "Khutsong",
      "Fochville",
      "Wedela",
      "Bekkersdal",
      "Mohlakeng",
      "Randgate",
      "Toekomsrus"
    ],
    "City of Cape Town": [
      "Cape Town",
      "Bellville",
      "Mitchell's Plain",
      "Khayelitsha",
      "Parow",
      "Goodwood",
      "Strand",
      "Somerset West",
      "Paarl",
      "Cape Town CBD",
      "Atlantis",
      "Fish Hoek",
      "Muizenberg",
      "Simon's Town",
      "Hout Bay",
      "Milnerton",
      "Durbanville",
      "Kraaifontein"
    ],
    "Drakenstein": [
      "Paarl",
      "Wellington",
      "Franschhoek",
      "Gouda",
      "Saron",
      "Hermon"
    ],
    "Stellenbosch": [
      "Stellenbosch",
      "Franschhoek",
      "Somerset West",
      "Klapmuts",
      "Pniel",
      "Kylemore",
      "Jamestown"
    ],
    "George": [
      "George",
      "Wilderness",
      "Uniondale",
      "George CBD",
      "Pacaltsdorp",
      "Herolds Bay"
    ],
    "eThekwini": [
      "Durban",
      "Pinetown",
      "Umlazi",
      "Phoenix",
      "Chatsworth",
      "KwaMashu",
      "Tongaat",
      "Amanzimtoti",
      "Durban CBD",
      "Umhlanga",
      "Verulam",
      "Hillcrest",
      "Kloof",
      "Isipingo",
      "Umkomaas",
      "Umdloti",
      "La Mercy",
      "Inanda",
      "Ntuzuma"
    ],
    "uMgungundlovu": [
      "Pietermaritzburg",
      "Howick",
      "Mpophomeni",
      "Hilton",
      "Edendale",
      "Wartburg",
      "Richmond",
      "Mooi River"
    ],
    "King Cetshwayo": [
      "Richards Bay",
      "Empangeni",
      "Eshowe",
      "Mtunzini",
      "KwaMbonambi",
      "Gingindlovu",
      "Melmoth",
      "Nkandla"
    ],
    "Buffalo City": [
      "East London",
      "Bhisho",
      "King William's Town",
      "Mdantsane",
      "Zwelitsha",
      "Dimbaza"
    ],
    "Nelson Mandela Bay": [
      "Port Elizabeth (Gqeberha)",
      "Uitenhage",
      "Despatch",
      "Kariega",
      "Motherwell",
      "Bethelsdorp"
    ],
    "Mangaung": [
      "Bloemfontein",
      "Botshabelo",
      "Thaba Nchu",
      "Dewetsdorp",
      "Wepener",
      "Vanstadensrus",
      "Soutpan"
    ],
    "Lejweleputswa": [
      "Welkom",
      "Odendaalsrus",
      "Virginia",
      "Allanridge",
      "Hennenman",
      "Kutloanong",
      "Thabong",
      "Bronville"
    ],
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
    "Bojanala Platinum": [
      "Rustenburg",
      "Brits",
      "Swartruggens",
      "Mooinooi",
      "Hartbeespoort",
      "Sun City",
      "Mogwase",
      "Tlhabane",
      "Phokeng",
      "Marikana",
      "Koster",
      "Groot Marico"
    ],
    "Dr Kenneth Kaunda": [
      "Klerksdorp",
      "Potchefstroom",
      "Stilfontein",
      "Orkney",
      "Hartbeesfontein",
      "Jouberton",
      "Khuma",
      "Leeudoringstad"
    ],
    "Ngaka Modiri Molema": [
      "Mahikeng",
      "Lichtenburg",
      "Delareyville",
      "Zeerust",
      "Coligny",
      "Sannieshof",
      "Groot Marico",
      "Ottosdal",
      "Itsoseng"
    ],
    "Frances Baard": [
      "Kimberley",
      "Barkly West",
      "Hartswater",
      "Jan Kempdorp",
      "Warrenton",
      "Douglas",
      "Pampierstad"
    ],
    "ZF Mgcawu": [
      "Upington",
      "Kakamas",
      "Keimoes",
      "Kuruman",
      "Kathu",
      "Olifantshoek",
      "Groblershoop",
      "Augrabies",
      "Kenhardt"
    ],
    "Blouberg": [
      "Senwabarwana",
      "Dendron"
    ],
    "Lepelle-Nkumpi": [
      "Lebowakgomo",
      "Zebediela"
    ],
    "Molemole": [
      "Dendron",
      "Mogwadi"
    ],
    "Ba-Phalaborwa": [
      "Phalaborwa",
      "Namakgale",
      "Gravelotte"
    ],
    "Greater Giyani": [
      "Giyani",
      "Malamulele"
    ],
    "Greater Letaba": [
      "Tzaneen",
      "Letsitele",
      "Nkowankowa"
    ],
    "Greater Tzaneen": [
      "Tzaneen",
      "Letsitele",
      "Nkowankowa",
      "Hoedspruit"
    ],
    "Maruleng": [
      "Hoedspruit",
      "Letsitele"
    ],
    "Elias Motsoaledi": [
      "Groblersdal",
      "Marble Hall"
    ],
    "Ephraim Mogale": [
      "Marble Hall",
      "Groblersdal"
    ],
    "Fetakgomo Tubatse": [
      "Burgersfort",
      "Steelpoort"
    ],
    "Makhuduthamaga": [
      "Jane Furse",
      "Zebediela"
    ],
    "Collins Chabane": [
      "Malamulele",
      "Elim"
    ],
    "Makhado": [
      "Makhado",
      "Elim",
      "Mutale"
    ],
    "Musina": [
      "Musina"
    ],
    "Thulamela": [
      "Thohoyandou",
      "Sibasa"
    ],
    "Bela-Bela": [
      "Bela-Bela"
    ],
    "Lephalale": [
      "Lephalale"
    ],
    "Modimolle-Mookgophong": [
      "Modimolle",
      "Mookgophong"
    ],
    "Mogalakwena": [
      "Mokopane",
      "Mahwelereng"
    ],
    "Thabazimbi": [
      "Thabazimbi"
    ],
    "Waterberg": [
      "Bela-Bela",
      "Lephalale",
      "Modimolle",
      "Thabazimbi",
      "Mokopane"
    ],
    "City of Mbombela": [
      "Mbombela (Nelspruit)",
      "White River",
      "Hazyview"
    ],
    "Nkomazi": [
      "Malelane",
      "Komatipoort",
      "Tonga",
      "Kaapmuiden"
    ],
    "Thaba Chweu": [
      "Lydenburg (Mashishing)",
      "Sabie",
      "Graskop",
      "Pilgrim's Rest",
      "Dullstroom",
      "Waterval Boven (Emgwenya)"
    ],
    "Msukaligwa": [
      "Ermelo",
      "Wesselton"
    ],
    "Mkhondo": [
      "Piet Retief",
      "Amsterdam"
    ],
    "Chief Albert Luthuli": [
      "Carolina",
      "Badplaas"
    ],
    "Dipaleseng": [
      "Greylingstad",
      "Balfour"
    ],
    "Govan Mbeki": [
      "Secunda",
      "Evander",
      "Kinross",
      "Trichardt",
      "Embalenhle"
    ],
    "Lekwa": [
      "Standerton",
      "Morgenzon"
    ],
    "Pixley ka Seme": [
      "De Aar",
      "Colesberg",
      "Hanover",
      "Richmond",
      "Philipstown",
      "Petrusville",
      "Hopetown",
      "Vanderkloof"
    ],
    "Victor Khanye": [
      "Delmas",
      "Ogies"
    ],
    "Dr JS Moroka": [
      "Siyabuswa"
    ],
    "eMalahleni": [
      "Witbank (eMalahleni)",
      "Kriel",
      "Ogies"
    ],
    "Emakhazeni": [
      "Belfast (eMakhazeni)",
      "Dullstroom",
      "Waterval Boven (Emgwenya)"
    ],
    "Steve Tshwete": [
      "Middelburg",
      "Hendrina"
    ],
    "Thembisile Hani": [
      "KwaMhlanga",
      "Siyabuswa"
    ],
    "Emfuleni": [
      "Vereeniging",
      "Vanderbijlpark",
      "Sebokeng",
      "Sharpeville",
      "Boipatong",
      "Bophelong",
      "Three Rivers",
      "Duncanville"
    ],
    "Midvaal": [
      "Meyerton",
      "Henley-on-Klip",
      "Walkerville",
      "De Deur"
    ],
    "Lesedi": [
      "Heidelberg",
      "Ratanda",
      "Devon",
      "Nigel"
    ],
    "Mogale City": [
      "Krugersdorp",
      "Kagiso",
      "Magaliesburg",
      "Muldersdrift",
      "Munsieville",
      "Tarlton",
      "Hekpoort"
    ],
    "Merafong City": [
      "Carletonville",
      "Khutsong",
      "Fochville",
      "Wedela"
    ],
    "Rand West City": [
      "Randfontein",
      "Westonaria",
      "Bekkersdal",
      "Mohlakeng",
      "Randgate",
      "Toekomsrus"
    ],
    "Matjhabeng": [
      "Welkom",
      "Virginia",
      "Odendaalsrus",
      "Allanridge",
      "Thabong",
      "Kutloanong",
      "Bronville",
      "Hennenman"
    ],
    "Thabo Mofutsanyane": [
      "Bethlehem",
      "Harrismith",
      "Phuthaditjhaba",
      "Kestell",
      "Clarens",
      "Fouriesburg",
      "Senekal",
      "Rosendal",
      "Paul Roux",
      "Reitz",
      "Ficksburg",
      "Marquard"
    ],
    "Dihlabeng": [
      "Bethlehem",
      "Clarens",
      "Fouriesburg",
      "Rosendal",
      "Paul Roux"
    ],
    "Maluti-a-Phofung": [
      "Phuthaditjhaba",
      "Harrismith",
      "Kestell"
    ],
    "Nketoana": [
      "Reitz",
      "Petrus Steyn",
      "Arlington"
    ],
    "Phumelela": [
      "Vrede",
      "Memel",
      "Charlestown"
    ],
    "Setsoto": [
      "Ficksburg",
      "Senekal",
      "Marquard",
      "Clocolan"
    ],
    "Mantsopa": [
      "Ladybrand",
      "Excelsior",
      "Hobhouse"
    ],
    "Fezile Dabi": [
      "Sasolburg",
      "Parys",
      "Kroonstad",
      "Heilbron",
      "Deneysville",
      "Viljoenskroon",
      "Vredefort",
      "Frankfort",
      "Tweeling",
      "Villiers",
      "Cornelia"
    ],
    "Metsimaholo": [
      "Sasolburg",
      "Deneysville",
      "Oranjeville"
    ],
    "Ngwathe": [
      "Parys",
      "Vredefort",
      "Heilbron",
      "Frankfort",
      "Tweeling",
      "Villiers",
      "Cornelia"
    ],
    "Moqhaka": [
      "Kroonstad",
      "Viljoenskroon",
      "Steynsrus"
    ],
    "Mafube": [
      "Frankfort",
      "Cornelia",
      "Tweeling",
      "Villiers"
    ],
    "Xhariep": [
      "Trompsburg",
      "Springfontein",
      "Philippolis",
      "Smithfield",
      "Fauresmith",
      "Zastron",
      "Rouxville",
      "Bethulie",
      "Gariepdam"
    ],
    "Kopanong": [
      "Trompsburg",
      "Springfontein",
      "Philippolis",
      "Bethulie",
      "Gariepdam"
    ],
    "Letsemeng": [
      "Fauresmith",
      "Jagersfontein",
      "Koffiefontein"
    ],
    "Mohokare": [
      "Zastron",
      "Smithfield",
      "Rouxville"
    ],
    "Msunduzi": [
      "Pietermaritzburg",
      "Edendale",
      "Hilton",
      "Mpophomeni"
    ],
    "uMngeni": [
      "Howick",
      "Hilton",
      "Mooi River",
      "Nottingham Road"
    ],
    "Mpofana": [
      "Mooi River",
      "Rosetta"
    ],
    "Richmond": [
      "Richmond",
      "Byrne"
    ],
    "uMhlathuze": [
      "Richards Bay",
      "Empangeni",
      "KwaMbonambi",
      "Felixton"
    ],
    "uMlalazi": [
      "Eshowe",
      "Mtunzini",
      "Gingindlovu"
    ],
    "Mthonjaneni": [
      "Melmoth",
      "Ntambanana"
    ],
    "iLembe": [
      "Ballito",
      "KwaDukuza (Stanger)",
      "Mandeni",
      "Shakaskraal",
      "Salt Rock",
      "Tinley Manor"
    ],
    "KwaDukuza": [
      "Stanger",
      "Ballito",
      "Salt Rock",
      "Shakaskraal",
      "Tinley Manor"
    ],
    "Mandeni": [
      "Mandeni",
      "Sundumbili",
      "Isithebe"
    ],
    "Ugu": [
      "Port Shepstone",
      "Margate",
      "Shelly Beach",
      "Hibberdene",
      "Scottburgh",
      "Umzinto",
      "Harding",
      "Gamalakhe",
      "Ramsgate"
    ],
    "Ray Nkonyeni": [
      "Margate",
      "Ramsgate",
      "Port Shepstone",
      "Gamalakhe"
    ],
    "Hibiscus Coast": [
      "Margate",
      "Ramsgate",
      "Shelly Beach",
      "Port Edward"
    ],
    "uThukela": [
      "Ladysmith",
      "Estcourt",
      "Bergville",
      "Winterton",
      "Colenso",
      "Weenen"
    ],
    "Inkosi Langalibalele": [
      "Estcourt",
      "Weenen",
      "Winterton"
    ],
    "Imbabazane": [
      "Bergville",
      "Winterton",
      "Colenso"
    ],
    "Amajuba": [
      "Newcastle",
      "Utrecht",
      "Dannhauser",
      "Madadeni",
      "Osizweni"
    ],
    "Newcastle": [
      "Newcastle CBD",
      "Madadeni",
      "Osizweni",
      "Aviary Hill",
      "Lennoxton"
    ],
    "Utrecht": [
      "Utrecht",
      "Amersfoort"
    ],
    "Dannhauser": [
      "Dannhauser",
      "Hattingspruit"
    ],
    "Zululand": [
      "Ulundi",
      "Vryheid",
      "Pongola",
      "Babanango",
      "Paulpietersburg",
      "eDumbe",
      "Nongoma"
    ],
    "AbaQulusi": [
      "Vryheid",
      "Paulpietersburg",
      "Hlobane"
    ],
    "uPhongolo": [
      "Pongola",
      "Golela"
    ],
    "eDumbe": [
      "Paulpietersburg",
      "eDumbe"
    ],
    "Ulundi": [
      "Ulundi",
      "Babanango"
    ],
    "uMkhanyakude": [
      "Jozini",
      "Manguzi",
      "Hluhluwe",
      "Ingwavuma",
      "Mtubatuba",
      "Kwangwanase",
      "Sodwana Bay"
    ],
    "Jozini": [
      "Jozini",
      "Ingwavuma",
      "Mkuze"
    ],
    "uMhlabuyalingana": [
      "Manguzi",
      "Kwangwanase",
      "Sodwana Bay"
    ],
    "Mtubatuba": [
      "Mtubatuba",
      "Hluhluwe",
      "False Bay"
    ],
    "Big Five Hlabisa": [
      "Hluhluwe",
      "Hlabisa"
    ],
    "Harry Gwala": [
      "Kokstad",
      "Underberg",
      "Himeville",
      "Ixopo",
      "Bulwer",
      "Creighton"
    ],
    "Greater Kokstad": [
      "Kokstad",
      "Mount Frere"
    ],
    "KwaSani": [
      "Underberg",
      "Himeville",
      "Sani Pass"
    ],
    "uBuhlebezwe": [
      "Ixopo",
      "Creighton"
    ],
    "Ingwe": [
      "Bulwer",
      "Donnybrook"
    ],
    "uMzinyathi": [
      "Dundee",
      "Glencoe",
      "Nquthu",
      "Greytown",
      "Msinga",
      "Pomeroy"
    ],
    "Endumeni": [
      "Dundee",
      "Glencoe"
    ],
    "Nquthu": [
      "Nquthu",
      "Pomeroy"
    ],
    "Msinga": [
      "Msinga",
      "Tugela Ferry"
    ],
    "uMshwathi": [
      "Greytown",
      "Dalton"
    ],
    "Rustenburg": [
      "Rustenburg CBD",
      "Tlhabane",
      "Boitekong",
      "Phokeng",
      "Marikana",
      "Waterkloof Rustenburg"
    ],
    "Madibeng": [
      "Brits",
      "Hartbeespoort",
      "Letlhabile",
      "Mooinooi"
    ],
    "Moses Kotane": [
      "Mogwase",
      "Sun City",
      "Moruleng",
      "Phokeng"
    ],
    "Kgetlengrivier": [
      "Koster",
      "Swartruggens"
    ],
    "Moretele": [
      "Temba",
      "Hammanskraal North"
    ],
    "City of Matlosana": [
      "Klerksdorp",
      "Orkney",
      "Stilfontein",
      "Hartbeesfontein",
      "Jouberton",
      "Khuma"
    ],
    "JB Marks": [
      "Potchefstroom",
      "Ventersdorp"
    ],
    "Maquassi Hills": [
      "Leeudoringstad",
      "Wolmaransstad",
      "Kingswood"
    ],
    "Mahikeng": [
      "Mahikeng CBD",
      "Mmabatho",
      "Montshiwa",
      "Danville",
      "Riviera Park"
    ],
    "Ramotshere Moiloa": [
      "Zeerust",
      "Groot Marico",
      "Rooigrond"
    ],
    "Ditsobotla": [
      "Lichtenburg",
      "Coligny",
      "Itsoseng"
    ],
    "Tswaing": [
      "Sannieshof",
      "Delareyville",
      "Ottosdal"
    ],
    "Ratlou": [
      "Delareyville",
      "Setlagole"
    ],
    "Dr Ruth Segomotsi Mompati": [
      "Vryburg",
      "Taung",
      "Schweizer-Reneke",
      "Ganyesa",
      "Christiana",
      "Delareyville",
      "Bloemhof",
      "Reivilo"
    ],
    "Kagisano-Molopo": [
      "Ganyesa",
      "Reivilo",
      "Bray"
    ],
    "Naledi": [
      "Vryburg",
      "Huhudi"
    ],
    "Mamusa": [
      "Schweizer-Reneke",
      "Amalia"
    ],
    "Lekwa-Teemane": [
      "Christiana",
      "Bloemhof"
    ],
    "Thanjaneng": [
      "Taung",
      "Pudimoe"
    ],
    "Knysna-Bitou": [
      "Knysna",
      "Plettenberg Bay",
      "Sedgefield",
      "Brackenridge"
    ],
    "Knysna": [
      "Knysna CBD",
      "Sedgefield",
      "Rheenendal"
    ],
    "Bitou": [
      "Plettenberg Bay",
      "Kranshoek",
      "Wittedrift"
    ],
    "Overberg": [
      "Hermanus",
      "Gansbaai",
      "Kleinmond",
      "Bredasdorp",
      "Caledon",
      "Stanford",
      "Struisbaai",
      "Arniston"
    ],
    "Overstrand": [
      "Hermanus",
      "Gansbaai",
      "Kleinmond",
      "Stanford",
      "Pringle Bay",
      "Betty's Bay"
    ],
    "Theewaterskloof": [
      "Caledon",
      "Grabouw",
      "Villiersdorp",
      "Botrivier"
    ],
    "Cape Agulhas": [
      "Bredasdorp",
      "Struisbaai",
      "Arniston",
      "Napier",
      "L'Agulhas"
    ],
    "Swellendam": [
      "Swellendam",
      "Barrydale",
      "Suurbraak"
    ],
    "West Coast": [
      "Vredenburg",
      "Saldanha",
      "Langebaan",
      "Hopefield",
      "Darling",
      "Velddrif",
      "Paternoster",
      "St Helena Bay"
    ],
    "Saldanha Bay": [
      "Saldanha",
      "Vredenburg",
      "Langebaan",
      "Hopefield",
      "St Helena Bay",
      "Paternoster"
    ],
    "Swartland": [
      "Malmesbury",
      "Darling",
      "Moorreesburg",
      "Riebeeck-Kasteel",
      "Riebeeck-Wes"
    ],
    "Bergrivier": [
      "Velddrif",
      "Piketberg",
      "Porterville",
      "Eendekuil"
    ],
    "Cederberg": [
      "Citrusdal",
      "Clanwilliam",
      "Wupperthal"
    ],
    "Matzikama": [
      "Vredendal",
      "Vanrhynsdorp",
      "Lutzville",
      "Klawer"
    ],
    "Cape Winelands": [
      "Worcester",
      "Robertson",
      "Ceres",
      "Tulbagh",
      "Rawsonville",
      "Montagu",
      "Ashton",
      "Bonnievale",
      "De Doorns"
    ],
    "Breede Valley": [
      "Worcester",
      "De Doorns",
      "Rawsonville",
      "Touwsrivier"
    ],
    "Langeberg": [
      "Robertson",
      "Montagu",
      "Ashton",
      "Bonnievale",
      "McGregor"
    ],
    "Witzenberg": [
      "Ceres",
      "Tulbagh",
      "Prince Alfred Hamlet",
      "Op-die-Berg"
    ],
    "Oudtshoorn": [
      "Oudtshoorn CBD",
      "De Rust",
      "Dysselsdorp"
    ],
    "Kannaland": [
      "Ladismith",
      "Calitzdorp",
      "Zoar"
    ],
    "Hessequa": [
      "Riversdale",
      "Stilbaai",
      "Albertinia",
      "Slangrivier"
    ],
    "Mossel Bay": [
      "Mossel Bay CBD",
      "Hartenbos",
      "Great Brak River",
      "Dana Bay",
      "Klein Brak River",
      "Glentana"
    ],
    "Sol Plaatje": [
      "Kimberley",
      "Galeshewe",
      "Ritchie"
    ],
    "Dikgatlong": [
      "Barkly West",
      "Windsorton",
      "Delportshoop"
    ],
    "Magareng": [
      "Warrenton",
      "Andalusia"
    ],
    "Phokwane": [
      "Jan Kempdorp",
      "Hartswater",
      "Pampierstad"
    ],
    "//Khara Hais": [
      "Upington",
      "Paballelo",
      "Louisvale"
    ],
    "Kai !Garib": [
      "Kakamas",
      "Keimoes",
      "Augrabies",
      "Groblershoop"
    ],
    "Kgatelopele": [
      "Danielskuil",
      "Lime Acres",
      "Wrenchville"
    ],
    "Dawid Kruiper": [
      "Upington",
      "Kenhardt",
      "Mier"
    ],
    "Gamagara": [
      "Kathu",
      "Olifantshoek",
      "Dingleton"
    ],
    "John Taolo Gaetsewe": [
      "Kuruman",
      "Kathu",
      "Postmasburg",
      "Daniëlskuil",
      "Hotazel",
      "Black Rock"
    ],
    "Ga-Segonyana": [
      "Kuruman",
      "Mothibistad",
      "Wrenchville",
      "Bankhara-Bodulong"
    ],
    "Joe Morolong": [
      "Hotazel",
      "Black Rock",
      "Van Zylsrus"
    ],
    "Emthanjeni": [
      "De Aar",
      "Hanover",
      "Britstown"
    ],
    "Umsobomvu": [
      "Colesberg",
      "Noupoort",
      "Phillipstown"
    ],
    "Renosterberg": [
      "Petrusville",
      "Philipstown",
      "Vanderkloof"
    ],
    "Siyancuma": [
      "Douglas",
      "Griquatown",
      "Campbell"
    ],
    "Siyathemba": [
      "Hopetown",
      "Strydenburg"
    ],
    "Thembelihle": [
      "Hopetown",
      "Strydenburg"
    ],
    "Ubuntu": [
      "Richmond",
      "Murraysburg"
    ],
    "Kareeberg": [
      "Carnarvon",
      "Loxton"
    ],
    "Namakwa": [
      "Springbok",
      "Port Nolloth",
      "Kleinzee",
      "Nababeep",
      "Garies",
      "Kamieskroon",
      "Calvinia",
      "Nieuwoudtville",
      "Hondeklip Bay"
    ],
    "Nama Khoi": [
      "Springbok",
      "Nababeep",
      "Port Nolloth",
      "Steinkopf",
      "Kleinzee"
    ],
    "Kamiesberg": [
      "Garies",
      "Kamieskroon",
      "Hondeklip Bay",
      "Leliefontein"
    ],
    "Hantam": [
      "Calvinia",
      "Loeriesfontein",
      "Brandvlei",
      "Nieuwoudtville"
    ],
    "Khai-Ma": [
      "Pofadder",
      "Onseepkans",
      "Aggeneys"
    ],
    "Richtersveld": [
      "Alexander Bay",
      "Sendelingsdrift",
      "Eksteenfontein"
    ],
    "Karoo Hoogland": [
      "Sutherland",
      "Willowmore",
      "Fraserburg"
    ],
    "Great Kei": [
      "Komga",
      "Kei Mouth",
      "Chintsa"
    ],
    "Amahlathi": [
      "Stutterheim",
      "Cathcart",
      "Keiskammahoek"
    ],
    "Raymond Mhlaba": [
      "Fort Beaufort",
      "Alice",
      "Seymour",
      "Balfour"
    ],
    "Mnquma": [
      "Butterworth",
      "Centane",
      "Idutywa"
    ],
    "Mbhashe": [
      "Dutywa",
      "Willowvale",
      "Idutywa"
    ],
    "Ngqushwa": [
      "Hamburg",
      "Peddie"
    ],
    "Amathole": [
      "Butterworth",
      "Alice",
      "Fort Beaufort",
      "Stutterheim",
      "Cathcart",
      "Hamburg",
      "Hogsback"
    ],
    "Chris Hani": [
      "Queenstown (Komani)",
      "Cradock",
      "Cofimvaba",
      "Dordrecht",
      "Whittlesea",
      "Lady Frere"
    ],
    "Enoch Mgijima": [
      "Queenstown (Komani)",
      "Whittlesea",
      "Dordrecht",
      "Tarkastad"
    ],
    "Lukanji": [
      "Queenstown (Komani)",
      "Mlungisi",
      "Ezibeleni"
    ],
    "Intsika Yethu": [
      "Cofimvaba",
      "Tsomo"
    ],
    "Emalahleni": [
      "Lady Frere",
      "Cala"
    ],
    "Engcobo": [
      "Engcobo",
      "Ngcobo"
    ],
    "Sakhisizwe": [
      "Cala",
      "Elliot"
    ],
    "Tsolwana": [
      "Tarkastad",
      "Hofmeyr"
    ],
    "Inkwanca": [
      "Molteno",
      "Sterkstroom"
    ],
    "Joe Gqabi": [
      "Aliwal North",
      "Burgersdorp",
      "Jamestown",
      "Sterkspruit",
      "Barkly East",
      "Lady Grey"
    ],
    "Senqu": [
      "Lady Grey",
      "Barkly East",
      "Rhodes"
    ],
    "Maletswai": [
      "Aliwal North",
      "Jamestown"
    ],
    "Elundini": [
      "Sterkspruit",
      "Burgersdorp",
      "Mount Fletcher"
    ],
    "OR Tambo": [
      "Mthatha",
      "Port St Johns",
      "Lusikisiki",
      "Flagstaff",
      "Libode",
      "Ngqeleni",
      "Coffee Bay"
    ],
    "King Sabata Dalindyebo": [
      "Mthatha",
      "Libode",
      "Coffee Bay",
      "Mqanduli"
    ],
    "Nyandeni": [
      "Libode",
      "Ngqeleni",
      "Lusikisiki"
    ],
    "Mhlontlo": [
      "Qumbu",
      "Tsolo",
      "Mount Frere"
    ],
    "Port St Johns": [
      "Port St Johns",
      "Lusikisiki",
      "Flagstaff"
    ],
    "Qaukeni": [
      "Flagstaff",
      "Lusikisiki",
      "Bizana"
    ],
    "Alfred Nzo": [
      "Mount Ayliff",
      "Mount Frere",
      "Matatiele",
      "Bizana",
      "Mbizana",
      "Ntabankulu",
      "Qumbu"
    ],
    "Mbizana": [
      "Bizana",
      "Mbizana CBD",
      "Lusikisiki North"
    ],
    "Matatiele": [
      "Matatiele",
      "Cedarville"
    ],
    "Umzimvubu": [
      "Mount Ayliff",
      "Mount Frere",
      "Kokstad North"
    ],
    "Ntabankulu": [
      "Ntabankulu",
      "Qumbu South"
    ],
    "Sarah Baartman": [
      "Grahamstown (Makhanda)",
      "Jeffreys Bay",
      "Humansdorp",
      "Somerset East",
      "Graaff-Reinet",
      "Alexandria",
      "Kenton-on-Sea",
      "Patensie",
      "Kirkwood",
      "Port Alfred"
    ],
    "Makana": [
      "Grahamstown (Makhanda)",
      "Alicedale",
      "Riebeeck East"
    ],
    "Kouga": [
      "Jeffreys Bay",
      "Humansdorp",
      "Patensie",
      "Hankey"
    ],
    "Ndlambe": [
      "Port Alfred",
      "Kenton-on-Sea",
      "Alexandria",
      "Bathurst"
    ],
    "Blue Crane Route": [
      "Somerset East",
      "Cookhouse",
      "Pearston"
    ],
    "Camdeboo": [
      "Graaff-Reinet",
      "Nieu-Bethesda",
      "Aberdeen"
    ],
    "Dr Beyers Naudé": [
      "Graaff-Reinet",
      "Jansenville",
      "Steytlerville",
      "Willowmore"
    ],
    "Sunday's River Valley": [
      "Kirkwood",
      "Addo",
      "Paterson"
    ],
    "Kou-Kamma": [
      "Joubertina",
      "Kareedouw",
      "Clarkson"
    ]
  },

  /* ─────────────────────────────────────────────
   * SUBURBS  keyed by city
   * ───────────────────────────────────────────── */
  suburbs: {
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
      "Auckland Park",
      "Houghton Estate",
      "Parkhurst",
      "Greenside",
      "Parkview",
      "Norwood",
      "Kensington",
      "Observatory",
      "Craighall",
      "Craighall Park",
      "Forest Town"
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
      "Kliptown",
      "Protea Glen",
      "Protea North",
      "Chiawelo"
    ],
    "Randburg": [
      "Ferndale",
      "Blackheath",
      "Linden",
      "Northcliff",
      "Robindale",
      "Blairgowrie",
      "Bordeaux",
      "Cresta",
      "Fontainebleau",
      "Windsor East",
      "Windsor West",
      "Bromhof"
    ],
    "Sandton": [
      "Morningside",
      "Rivonia",
      "Hyde Park",
      "Bryanston",
      "Fourways",
      "Sunninghill",
      "Sandown",
      "Illovo",
      "Douglasdale",
      "Lonehill",
      "Paulshof",
      "Atholl",
      "Benmore Gardens"
    ],
    "Midrand": [
      "Halfway House",
      "Vorna Valley",
      "Kyalami",
      "Waterfall"
    ],
    "Pretoria": [
      "Arcadia",
      "Brooklyn",
      "Hatfield",
      "Menlyn",
      "Muckleneuk",
      "Lynnwood",
      "Sunnyside",
      "Centurion North",
      "Garsfontein",
      "Waterkloof",
      "Waterkloof Ridge",
      "Faerie Glen",
      "Moreleta Park",
      "Montana",
      "Wonderboom",
      "Waverley",
      "Silver Lakes",
      "Elardus Park",
      "Pretoria North"
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
      "De Waterkant",
      "Gardens",
      "Oranjezicht",
      "Tamboerskloof",
      "Vredehoek",
      "Fresnaye",
      "Bantry Bay",
      "Bloubergstrand",
      "Table View",
      "Parklands",
      "Century City"
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
      "Kenridge",
      "Boston",
      "Loevenstein",
      "Door de Kraal",
      "Protea Valley",
      "Welgemoed Extensions"
    ],
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
      "CBD",
      "Glen Anil",
      "Glenwood North",
      "Durban North",
      "Bluff",
      "Reservoir Hills",
      "Sydenham",
      "Clairwood",
      "Wentworth",
      "Merebank",
      "Yellowwood Park"
    ],
    "Pinetown": [
      "New Germany",
      "Westmead",
      "Marianhill",
      "Pinecrest",
      "Cowies Hill",
      "Ashley",
      "Farningham Ridge",
      "The Wolds",
      "Sarnia"
    ],
    "Port Elizabeth (Gqeberha)": [
      "Summerstrand",
      "Walmer",
      "Newton Park",
      "Kabega",
      "Uitenhage Road",
      "Humewood",
      "Richmond Hill",
      "Central",
      "Lorraine",
      "Framesby",
      "Summerstrand Extensions"
    ],
    "East London": [
      "Quigney",
      "Vincent",
      "Selborne",
      "Cambridge",
      "Nahoon",
      "Beacon Bay",
      "Nahoon Valley",
      "Vincent Heights",
      "Southernwood",
      "Amalinda",
      "Duncan Village",
      "Gonubie"
    ],
    "Bloemfontein": [
      "Westdene",
      "Waverley",
      "Universitas",
      "Fichardt Park",
      "Brandwag",
      "Mangaung Township",
      "Langenhoven Park",
      "Bayswater",
      "Dan Pienaar",
      "Heuwelsig",
      "Pellissier",
      "Wilgehof",
      "Hospitaalpark",
      "Park West",
      "Hilton",
      "Uitsig"
    ],
    "Boksburg": [
      "Boksburg North",
      "Parkrand",
      "Vosloorus",
      "Dawn Park",
      "Sunward Park",
      "Beyers Park",
      "Bartlett",
      "Freeway Park",
      "Atlasville"
    ],
    "Benoni": [
      "Actonville",
      "Daveyton",
      "Farrarmere",
      "Crystal Park",
      "Northmead",
      "Rynfield",
      "Lakefield",
      "Brentwood Park",
      "Morehill"
    ],
    "Tembisa": [
      "Ivory Park",
      "Rabie Ridge",
      "Umthambeka"
    ],
    "Rustenburg": [
      "Boitekong",
      "Tlhabane",
      "Waterkloof Rustenburg",
      "Cashan",
      "Safari Gardens",
      "Geelhoutpark",
      "Boitekong Extensions",
      "Protea Park",
      "Safari Gardens Extensions",
      "Cashan Extensions",
      "Waterfall East",
      "Waterval"
    ],
    "Polokwane": [
      "Bendor",
      "Ivy Park",
      "Fauna Park",
      "Superbia",
      "Seshego Township",
      "Flora Park",
      "Penina Park",
      "Annadale",
      "Nirvana",
      "Westenburg",
      "Sterpark",
      "Eduan Park"
    ],
    "Mbombela (Nelspruit)": [
      "Nelspruit CBD",
      "Riverside Park",
      "Sonheuwel",
      "Mataffin",
      "Kanyamazane",
      "West Acres",
      "Steiltes",
      "Valencia Park",
      "Kamagugu",
      "Drum Rock"
    ],
    "Kimberley": [
      "Galeshewe",
      "Phuthanang",
      "Greenpoint Kimberley",
      "Royldene",
      "Cassandra",
      "Rhodesdene",
      "Hadison Park",
      "Homelite",
      "New Park",
      "Beaconsfield",
      "Roodepan"
    ],
    "Thohoyandou": [
      "Maniini",
      "Muledane",
      "Shayandima",
      "Makwarela"
    ],
    "Tzaneen": [
      "Aquapark",
      "Arbor Park",
      "Premierpark"
    ],
    "Mokopane": [
      "Chroompark",
      "Impala Park",
      "Mahwelereng"
    ],
    "Bela-Bela": [
      "Bospoort",
      "Radium"
    ],
    "Burgersfort": [
      "Praktiseer"
    ],
    "Makhado": [
      "Louis Trichardt CBD",
      "Majosi",
      "Hluvukani"
    ],
    "Musina": [
      "Nancefield",
      "Tshipise"
    ],
    "White River": [
      "Kingsview",
      "Plaston",
      "White River Estates"
    ],
    "Witbank (eMalahleni)": [
      "Tasbet Park",
      "Reyno Ridge",
      "Die Heuwel",
      "Klarinet",
      "KwaGuqa"
    ],
    "Middelburg": [
      "Aerorand",
      "Kanonkop",
      "Mhluzi",
      "Dennesig"
    ],
    "Secunda": [
      "Trichardt Extension",
      "Green Area",
      "Evander Extensions"
    ],
    "Ermelo": [
      "Cassim Park",
      "Wesselton"
    ],
    "Bushbuckridge": [
      "Acornhoek",
      "Thulamahashe",
      "Maviljan",
      "Dwarsloop"
    ],
    "Sabie": [
      "Sabie CBD",
      "Hendriksdal"
    ],
    "Lydenburg (Mashishing)": [
      "Lydenburg CBD",
      "Mashishing Township"
    ],
    "Piet Retief": [
      "Mkhondo Township",
      "Sithobela"
    ],
    "KwaMhlanga": [
      "Tweefontein",
      "Doornkop"
    ],
    "Siyabuswa": [
      "Siyabuswa A",
      "Siyabuswa B",
      "Siyabuswa C"
    ],
    "Kempton Park": [
      "Birchleigh",
      "Birch Acres",
      "Glen Marais",
      "Norkem Park",
      "Croydon",
      "Rhodesfield"
    ],
    "Germiston": [
      "Primrose",
      "Lambton",
      "Elsburg",
      "Dinwiddie",
      "Sunnyridge"
    ],
    "Alberton": [
      "Brackenhurst",
      "Brackendowns",
      "New Redruth",
      "Meyersdal",
      "Raceview"
    ],
    "Katlehong": [
      "Germiston South",
      "Tokoza Section",
      "Natalspruit"
    ],
    "Sebokeng": [
      "Zone 7",
      "Zone 11",
      "Zone 14",
      "Boipatong Township",
      "Bophelong Township"
    ],
    "Ga-Rankuwa": [
      "Unit 1",
      "Unit 2",
      "Unit 3",
      "Unit 7",
      "Unit 9",
      "Plastic View"
    ],
    "Mabopane": [
      "Block S",
      "Block T",
      "Block X"
    ],
    "Kagiso": [
      "Kagiso 1",
      "Kagiso 2",
      "Swaneville"
    ],
    "Hammanskraal": [
      "Temba Township",
      "Suurman",
      "Stinkwater"
    ],
    "Ivory Park": [
      "Extension 1",
      "Extension 2",
      "Extension 10"
    ],
    "Daveyton": [
      "Daveyton East",
      "Daveyton West",
      "Vlakfontein"
    ],
    "KwaThema": [
      "KwaThema Section 1",
      "KwaThema Section 2",
      "Duduza Township"
    ],
    "Vosloorus": [
      "Vosloorus Extension 1",
      "Vosloorus Extension 5",
      "Likole"
    ],
    "Carletonville": [
      "Khutsong Township",
      "Wedela Township",
      "Blybank"
    ],
    "Krugersdorp": [
      "Munsieville Township",
      "Kagiso Ext",
      "Azaadville"
    ],
    "Randfontein": [
      "Mohlakeng Township",
      "Toekomsrus Township",
      "Randgate Township"
    ],
    "Welkom": [
      "Bedelia",
      "Flamingo Park",
      "Naudeville",
      "Riebeeckstad",
      "Dagbreek",
      "St Helena"
    ],
    "Bethlehem": [
      "Bohlokong",
      "Panorama",
      "Eureka",
      "Jordania"
    ],
    "Harrismith": [
      "Intabazwe",
      "Wilgepark"
    ],
    "Phuthaditjhaba": [
      "Bluegumbosch",
      "Namahadi",
      "Makwane"
    ],
    "Sasolburg": [
      "Vaalpark",
      "Roodia",
      "Zamdela",
      "Heron Banks"
    ],
    "Kroonstad": [
      "Maokeng",
      "Panorama",
      "Gelukwaarts"
    ],
    "Parys": [
      "Tumahole",
      "Vredefort Dome"
    ],
    "Ficksburg": [
      "Meqheleng"
    ],
    "Clarens": [
      "Kgubetswana"
    ],
    "Kestell": [
      "Qwaqwa Extension",
      "Makgolokoeng"
    ],
    "Senekal": [
      "Matwabeng",
      "Senekal Noord"
    ],
    "Reitz": [
      "Petsana",
      "Reitz Wes"
    ],
    "Viljoenskroon": [
      "Rammulotsi",
      "Vierfontein"
    ],
    "Heilbron": [
      "Phiritona",
      "Heilbron Oos"
    ],
    "Virginia": [
      "Meloding",
      "Phomolong"
    ],
    "Odendaalsrus": [
      "Kutloanong Township",
      "Bronville Township"
    ],
    "Zastron": [
      "Matlakeng",
      "Zastron Suid"
    ],
    "Ladybrand": [
      "Manyatseng",
      "Ladybrand Oos"
    ],
    "Frankfort": [
      "Namahadi",
      "Frankfort Wes"
    ],
    "Vrede": [
      "Thembalihle",
      "Vrede Noord"
    ],
    "Pietermaritzburg": [
      "Scottsville",
      "Hayfields",
      "Montrose",
      "Clarendon",
      "Pelham",
      "Bisley",
      "Northdale"
    ],
    "Umhlanga": [
      "Umhlanga Rocks",
      "Umhlanga Ridge",
      "La Lucia",
      "Prestondale",
      "Herrwood Park"
    ],
    "Chatsworth": [
      "Bayview",
      "Crossmoor",
      "Arena Park",
      "Moorton",
      "Shallcross"
    ],
    "Phoenix": [
      "Sunford",
      "Brookdale",
      "Eastbury",
      "Caneside",
      "Woodview"
    ],
    "Newcastle": [
      "Aviary Hill",
      "Lennoxton",
      "Osizweni",
      "Madadeni",
      "Signal Hill"
    ],
    "Richards Bay": [
      "Meer en See",
      "Arboretum",
      "Birdswood",
      "Brackenham",
      "Aquadene"
    ],
    "Port Shepstone": [
      "Marburg",
      "Umtentweni",
      "Sea Park",
      "Oslo Beach",
      "Southport"
    ],
    "Ballito": [
      "Compensation Beach",
      "Shakas Rock",
      "Sheffield Beach",
      "Simbithi",
      "Salt Rock"
    ],
    "Ladysmith": [
      "Acaciavale",
      "Hospitaalpark",
      "Steadville",
      "Ezakheni"
    ],
    "Kokstad": [
      "Shayamoya",
      "Bhongweni"
    ],
    "Ulundi": [
      "B Section",
      "C Section"
    ],
    "Empangeni": [
      "Empangeni CBD",
      "Empangeni Rail",
      "Allandale"
    ],
    "Eshowe": [
      "Eshowe CBD",
      "KwaNobamba",
      "Mthunzini Road"
    ],
    "Vryheid": [
      "Bhekuzulu",
      "Coronation",
      "Vryheid North"
    ],
    "Estcourt": [
      "Wembezi",
      "Estcourt South",
      "Loskopdam"
    ],
    "Mtunzini": [
      "Mtunzini CBD",
      "Ngoya"
    ],
    "Margate": [
      "Uvongo",
      "St Michaels-on-Sea",
      "Manaba Beach"
    ],
    "Scottburgh": [
      "Scottburgh Beach",
      "Kelso",
      "Pennington"
    ],
    "Hillcrest": [
      "Hillcrest CBD",
      "Upper Highway",
      "Waterfall"
    ],
    "Tongaat": [
      "Tongaat CBD",
      "Hambanathi",
      "Blackburn"
    ],
    "Stanger": [
      "Stanger CBD",
      "KwaDukuza Township",
      "Leadmine"
    ],
    "Dundee": [
      "Dundee CBD",
      "Murchison",
      "Glencoe Township"
    ],
    "Greytown": [
      "Greytown CBD",
      "Thembalihle",
      "Siyathuthuka"
    ],
    "Amanzimtoti": [
      "Doonside",
      "Umbogintwini",
      "Warner Beach"
    ],
    "Isipingo": [
      "Isipingo Beach",
      "Isipingo Rail",
      "Lotus Park"
    ],
    "Verulam": [
      "Verulam CBD",
      "Ottawa",
      "Shastri Park"
    ],
    "Klerksdorp": [
      "Flamwood",
      "Wilkoppies",
      "Meiringspark",
      "Adamayview",
      "Ellaton"
    ],
    "Potchefstroom": [
      "Baillie Park",
      "Van der Hoff Park",
      "Dassierand",
      "Ikageng",
      "Grimbeek Park"
    ],
    "Mahikeng": [
      "Mmabatho",
      "Montshiwa",
      "Riviera Park",
      "Danville"
    ],
    "Brits": [
      "Oukasie",
      "Elandsrand",
      "Letlhabile"
    ],
    "Hartbeespoort": [
      "Meerhof",
      "Melodie",
      "Ifafi",
      "Schoemansville"
    ],
    "Vryburg": [
      "Huhudi",
      "New Town"
    ],
    "Marikana": [
      "Wonderkop",
      "Nkaneng",
      "Marikana Township"
    ],
    "Orkney": [
      "Kanana",
      "Alabama",
      "Orkney North"
    ],
    "Stilfontein": [
      "Tigane",
      "Stilfontein Ext 4",
      "Waverly"
    ],
    "Lichtenburg": [
      "Boikhutso",
      "AVZ Township",
      "Lichtenburg North"
    ],
    "Zeerust": [
      "Gopane",
      "Zeerust CBD",
      "Zeerust Ext"
    ],
    "Taung": [
      "Taung Village",
      "Pudimoe Township",
      "Ga-Tlhose"
    ],
    "Schweizer-Reneke": [
      "Ipelegeng",
      "Schweizer-Reneke CBD"
    ],
    "Christiana": [
      "Geluksoord",
      "Utlwanang"
    ],
    "Bloemhof": [
      "Boitumelo",
      "Bloemhof Ext"
    ],
    "Sun City": [
      "Sun City Resort",
      "Pilanesberg Ext"
    ],
    "Phokeng": [
      "Phokeng Village",
      "Bojanala",
      "Chaneng"
    ],
    "Mogwase": [
      "Mogwase Unit 1",
      "Mogwase Unit 3",
      "Mogwase Unit 5"
    ],
    "Parow": [
      "Panorama",
      "Plattekloof",
      "Churchill Estate",
      "Glenlily"
    ],
    "Somerset West": [
      "Helderberg",
      "Strand North",
      "Paardevlei",
      "Heritage Park"
    ],
    "Stellenbosch": [
      "Die Boord",
      "Dalsig",
      "Idas Valley",
      "Cloetesville",
      "Kayamandi"
    ],
    "George": [
      "Heather Park",
      "Blanco",
      "Denneoord",
      "Thembalethu"
    ],
    "Knysna": [
      "Hunters Home",
      "Leisure Isle",
      "Hornlee",
      "Knysna Heights"
    ],
    "Plettenberg Bay": [
      "Robberg",
      "Seaside Longships",
      "Kwanokuthula"
    ],
    "Hermanus": [
      "Onrus",
      "Sandbaai",
      "Zwelihle",
      "Voelklip"
    ],
    "Mossel Bay": [
      "Heiderand",
      "Dana Bay",
      "Hartenbos",
      "Mossel Bay Central"
    ],
    "Durbanville": [
      "Sonstraal",
      "Sonstraal Heights",
      "Vierlanden",
      "Kenridge"
    ],
    "Milnerton": [
      "Table View",
      "Parklands",
      "Bloubergstrand",
      "Burgundy Estate"
    ],
    "Fish Hoek": [
      "Sun Valley",
      "Ocean View",
      "Capri Village"
    ],
    "Hout Bay": [
      "Imizamo Yethu",
      "Llandudno",
      "Hangberg"
    ],
    "Muizenberg": [
      "Lakeside",
      "Kalk Bay",
      "St James"
    ],
    "Simon's Town": [
      "Glencairn",
      "Red Hill",
      "Murdock Valley"
    ],
    "Paarl": [
      "Dal Josafat",
      "Groendal",
      "Mbekweni",
      "Jan Phillips Drive"
    ],
    "Wellington": [
      "Paarl Valley",
      "La Motte",
      "Groenberg"
    ],
    "Worcester": [
      "Avian Park",
      "Zweletemba",
      "Roodewal",
      "Nkqubela"
    ],
    "Caledon": [
      "Caledon CBD",
      "Genadendal",
      "Tesselaarsdal"
    ],
    "Bredasdorp": [
      "Bredasdorp CBD",
      "Napier Road",
      "Overberg Park"
    ],
    "Atlantis": [
      "Avondale",
      "Saxonsea",
      "Wesfleur"
    ],
    "Kraaifontein": [
      "Bloekombos",
      "Wallacedene",
      "Scottsdene"
    ],
    "Franschhoek": [
      "Groendal",
      "Franschhoek Valley",
      "La Motte"
    ],
    "Gansbaai": [
      "Kleinbaai",
      "Pearly Beach",
      "Franskraal"
    ],
    "Oudtshoorn": [
      "Bridgton",
      "Bongulethu",
      "Oudtshoorn North"
    ],
    "Sedgefield": [
      "Myoli Beach",
      "Swartvlei",
      "Lake Sedgefield"
    ],
    "Hartenbos": [
      "Hartenbos Central",
      "Hartenbos Heuwels",
      "Tergniet"
    ],
    "Saldanha": [
      "Saldanha CBD",
      "Diazville",
      "Middelpos"
    ],
    "Langebaan": [
      "Langebaan CBD",
      "Myburgh Park",
      "Club Mykonos"
    ],
    "Kleinmond": [
      "Kleinmond CBD",
      "Hawston",
      "Pringle Bay"
    ],
    "Upington": [
      "Paballelo",
      "Middelpos",
      "Keidebees",
      "Die Rand"
    ],
    "Kuruman": [
      "Seoding",
      "Wrenchville"
    ],
    "Kathu": [
      "Kathu West",
      "Sesheng"
    ],
    "Postmasburg": [
      "Beeshoek",
      "Postdene"
    ],
    "Springbok": [
      "Bergsig",
      "Okiep"
    ],
    "De Aar": [
      "Nonzwakazi",
      "Thembalesizwe"
    ],
    "Colesberg": [
      "Kuyasa",
      "Nooiensfontein"
    ],
    "Hopetown": [
      "Riemvasmaak",
      "Hopetown Township"
    ],
    "Port Nolloth": [
      "Kleinzee Township",
      "Port Nolloth CBD"
    ],
    "Calvinia": [
      "Calviniasdorp",
      "Niewoudtville Road"
    ],
    "Barkly West": [
      "Barkly West CBD",
      "Windsorton Road",
      "Delportshoop Road"
    ],
    "Jan Kempdorp": [
      "Ganspan",
      "Pampierstad Township"
    ],
    "Warrenton": [
      "Warrenton CBD",
      "Andalusia Township"
    ],
    "Hotazel": [
      "Hotazel CBD",
      "Bankhara"
    ],
    "Douglas": [
      "Rosedale",
      "Douglas Township"
    ],
    "Groblershoop": [
      "Wegdraai",
      "Groblershoop CBD"
    ],
    "Augrabies": [
      "Augrabies Falls Area",
      "Riemvasmaak"
    ],
    "Kakamas": [
      "Kakamas CBD",
      "Louisvale Road"
    ],
    "Keimoes": [
      "Keimoes CBD",
      "Kanoneiland"
    ],
    "Nababeep": [
      "Nababeep CBD",
      "Carolusberg"
    ],
    "Richmond": [
      "Richmond CBD",
      "Murraysburg Road"
    ],
    "Hanover": [
      "Hanover CBD",
      "Siyanda Township"
    ],
    "Mthatha": [
      "Southernwood Mthatha",
      "Northcrest",
      "Ikwezi",
      "Ncambedlana"
    ],
    "Mdantsane": [
      "NU1",
      "NU2",
      "NU6",
      "NU12"
    ],
    "Queenstown (Komani)": [
      "Mlungisi",
      "Ezibeleni",
      "Madeira Park"
    ],
    "Grahamstown (Makhanda)": [
      "Fingo Village",
      "Joza",
      "Oatlands",
      "Kingswood"
    ],
    "Jeffreys Bay": [
      "Wavecrest",
      "Aston Bay",
      "Marina Martinique",
      "Paradise Beach"
    ],
    "Port Alfred": [
      "West Bank",
      "East Bank",
      "Nemato"
    ],
    "Butterworth": [
      "Gcuwa",
      "Extension Areas"
    ],
    "Uitenhage": [
      "Uitenhage CBD",
      "Uitenhage North",
      "Rosedale"
    ],
    "Kariega": [
      "Kariega CBD",
      "Cannon Hill",
      "Kruisfontein"
    ],
    "Motherwell": [
      "Motherwell NU1",
      "Motherwell NU5",
      "Motherwell NU9",
      "Motherwell NU11"
    ],
    "King William's Town": [
      "Bhisho Township",
      "Zwelitsha Township",
      "Dimbaza Township"
    ],
    "Fort Beaufort": [
      "Bhofolo",
      "Fort Beaufort CBD",
      "Newtown"
    ],
    "Alice": [
      "Alice CBD",
      "Ntselamanzi",
      "Kuleka"
    ],
    "Stutterheim": [
      "Stutterheim CBD",
      "Mlungisi Stutterheim"
    ],
    "Graaff-Reinet": [
      "Graaff-Reinet CBD",
      "Umasizakhe",
      "Kroonvale"
    ],
    "Aliwal North": [
      "Aliwal North CBD",
      "Dukathole",
      "Jamestown Road"
    ],
    "Humansdorp": [
      "Humansdorp CBD",
      "Kruisfontein",
      "Pellsrus"
    ],
    "Somerset East": [
      "Somerset East CBD",
      "Inkwenkwezi",
      "Brakwater"
    ],
    "Cradock": [
      "Lingelihle",
      "Cradock CBD",
      "Michausdal"
    ],
    "Port St Johns": [
      "First Beach",
      "Second Beach",
      "Grosvenor"
    ],
    "Lusikisiki": [
      "Lusikisiki CBD",
      "Flagstaff Road"
    ],
    "Matatiele": [
      "Cedarville Township",
      "Matatiele CBD"
    ],
    "Bizana": [
      "Bizana CBD",
      "Nomlacu"
    ],
    "Hogsback": [
      "Hogsback Village",
      "Eselfontein"
    ],
    "Kenton-on-Sea": [
      "Kenton CBD",
      "Kariega Estuary",
      "Bushman's River Mouth"
    ],
    "Kirkwood": [
      "Kirkwood CBD",
      "Sunland",
      "Addo Road"
    ],
    "Coffee Bay": [
      "Coffee Bay Village",
      "Hole in the Wall"
    ]
  }
};

/* Make available globally */
window.SA_LOCATIONS = SA_LOCATIONS;
