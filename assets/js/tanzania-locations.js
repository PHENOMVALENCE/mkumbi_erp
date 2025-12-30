// Tanzania Location Data - Regions, Districts, Wards, and Villages
const tanzaniaLocations = {
    regions: [
        { id: 1, name: "Dar es Salaam" },
        { id: 2, name: "Dodoma" },
        { id: 3, name: "Arusha" },
        { id: 4, name: "Kilimanjaro" },
        { id: 5, name: "Tanga" },
        { id: 6, name: "Morogoro" },
        { id: 7, name: "Pwani (Coast)" },
        { id: 8, name: "Lindi" },
        { id: 9, name: "Mtwara" },
        { id: 10, name: "Ruvuma" },
        { id: 11, name: "Iringa" },
        { id: 12, name: "Mbeya" },
        { id: 13, name: "Singida" },
        { id: 14, name: "Tabora" },
        { id: 15, name: "Rukwa" },
        { id: 16, name: "Kigoma" },
        { id: 17, name: "Shinyanga" },
        { id: 18, name: "Kagera" },
        { id: 19, name: "Mwanza" },
        { id: 20, name: "Mara" },
        { id: 21, name: "Manyara" },
        { id: 22, name: "Njombe" },
        { id: 23, name: "Katavi" },
        { id: 24, name: "Simiyu" },
        { id: 25, name: "Geita" },
        { id: 26, name: "Songwe" }
    ],

    districts: [
        // Dar es Salaam Region
        { id: 1, name: "Ilala", regionId: 1 },
        { id: 2, name: "Kinondoni", regionId: 1 },
        { id: 3, name: "Temeke", regionId: 1 },
        { id: 4, name: "Ubungo", regionId: 1 },
        { id: 5, name: "Kigamboni", regionId: 1 },

        // Dodoma Region
        { id: 6, name: "Dodoma Urban", regionId: 2 },
        { id: 7, name: "Bahi", regionId: 2 },
        { id: 8, name: "Chamwino", regionId: 2 },
        { id: 9, name: "Chemba", regionId: 2 },
        { id: 10, name: "Kondoa", regionId: 2 },
        { id: 11, name: "Kongwa", regionId: 2 },
        { id: 12, name: "Mpwapwa", regionId: 2 },

        // Arusha Region
        { id: 13, name: "Arusha Urban", regionId: 3 },
        { id: 14, name: "Arusha Rural", regionId: 3 },
        { id: 15, name: "Karatu", regionId: 3 },
        { id: 16, name: "Longido", regionId: 3 },
        { id: 17, name: "Meru", regionId: 3 },
        { id: 18, name: "Monduli", regionId: 3 },
        { id: 19, name: "Ngorongoro", regionId: 3 },

        // Kilimanjaro Region
        { id: 20, name: "Moshi Urban", regionId: 4 },
        { id: 21, name: "Moshi Rural", regionId: 4 },
        { id: 22, name: "Hai", regionId: 4 },
        { id: 23, name: "Mwanga", regionId: 4 },
        { id: 24, name: "Rombo", regionId: 4 },
        { id: 25, name: "Same", regionId: 4 },
        { id: 26, name: "Siha", regionId: 4 },

        // Tanga Region
        { id: 27, name: "Tanga Urban", regionId: 5 },
        { id: 28, name: "Handeni", regionId: 5 },
        { id: 29, name: "Kilindi", regionId: 5 },
        { id: 30, name: "Korogwe", regionId: 5 },
        { id: 31, name: "Lushoto", regionId: 5 },
        { id: 32, name: "Muheza", regionId: 5 },
        { id: 33, name: "Mkinga", regionId: 5 },
        { id: 34, name: "Pangani", regionId: 5 },

        // Morogoro Region
        { id: 35, name: "Morogoro Urban", regionId: 6 },
        { id: 36, name: "Morogoro Rural", regionId: 6 },
        { id: 37, name: "Kilombero", regionId: 6 },
        { id: 38, name: "Kilosa", regionId: 6 },
        { id: 39, name: "Mvomero", regionId: 6 },
        { id: 40, name: "Ulanga", regionId: 6 },
        { id: 41, name: "Malinyi", regionId: 6 },

        // Pwani Region
        { id: 42, name: "Kibaha Urban", regionId: 7 },
        { id: 43, name: "Kibaha Rural", regionId: 7 },
        { id: 44, name: "Bagamoyo", regionId: 7 },
        { id: 45, name: "Kisarawe", regionId: 7 },
        { id: 46, name: "Mafia", regionId: 7 },
        { id: 47, name: "Mkuranga", regionId: 7 },
        { id: 48, name: "Rufiji", regionId: 7 },

        // Mwanza Region
        { id: 49, name: "Mwanza Urban", regionId: 19 },
        { id: 50, name: "Ilemela", regionId: 19 },
        { id: 51, name: "Nyamagana", regionId: 19 },
        { id: 52, name: "Kwimba", regionId: 19 },
        { id: 53, name: "Magu", regionId: 19 },
        { id: 54, name: "Misungwi", regionId: 19 },
        { id: 55, name: "Sengerema", regionId: 19 },
        { id: 56, name: "Ukerewe", regionId: 19 }
    ],

    wards: [
        // Ilala District (Dar es Salaam)
        { id: 1, name: "Buguruni", districtId: 1 },
        { id: 2, name: "Kariakoo", districtId: 1 },
        { id: 3, name: "Ilala", districtId: 1 },
        { id: 4, name: "Jangwani", districtId: 1 },
        { id: 5, name: "Kivukoni", districtId: 1 },
        { id: 6, name: "Kisutu", districtId: 1 },
        { id: 7, name: "Mchikichini", districtId: 1 },
        { id: 8, name: "Gerezani", districtId: 1 },
        { id: 9, name: "Upanga East", districtId: 1 },
        { id: 10, name: "Upanga West", districtId: 1 },
        { id: 11, name: "Pugu", districtId: 1 },
        { id: 12, name: "Tabata", districtId: 1 },
        { id: 13, name: "Segerea", districtId: 1 },
        { id: 14, name: "Ukonga", districtId: 1 },
        { id: 15, name: "Kipawa", districtId: 1 },
        { id: 16, name: "Kinyerezi", districtId: 1 },

        // Kinondoni District
        { id: 17, name: "Kinondoni", districtId: 2 },
        { id: 18, name: "Mwananyamala", districtId: 2 },
        { id: 19, name: "Tandale", districtId: 2 },
        { id: 20, name: "Manzese", districtId: 2 },
        { id: 21, name: "Ubungo", districtId: 2 },
        { id: 22, name: "Sinza", districtId: 2 },
        { id: 23, name: "Kijitonyama", districtId: 2 },
        { id: 24, name: "Magomeni", districtId: 2 },
        { id: 25, name: "Kinondoni", districtId: 2 },
        { id: 26, name: "Mburahati", districtId: 2 },
        { id: 27, name: "Msasani", districtId: 2 },
        { id: 28, name: "Kawe", districtId: 2 },
        { id: 29, name: "Mikocheni", districtId: 2 },
        { id: 30, name: "Regent Estate", districtId: 2 },

        // Temeke District
        { id: 31, name: "Temeke", districtId: 3 },
        { id: 32, name: "Mtoni", districtId: 3 },
        { id: 33, name: "Keko", districtId: 3 },
        { id: 34, name: "Chang'ombe", districtId: 3 },
        { id: 35, name: "Mbagala", districtId: 3 },
        { id: 36, name: "Kurasini", districtId: 3 },
        { id: 37, name: "Sandali", districtId: 3 },
        { id: 38, name: "Miburani", districtId: 3 },
        { id: 39, name: "Charambe", districtId: 3 },
        { id: 40, name: "Buza", districtId: 3 },

        // Ubungo District
        { id: 41, name: "Ubungo", districtId: 4 },
        { id: 42, name: "Makuburi", districtId: 4 },
        { id: 43, name: "Mburahati", districtId: 4 },
        { id: 44, name: "Makongo", districtId: 4 },
        { id: 45, name: "Kwembe", districtId: 4 },
        { id: 46, name: "Kibamba", districtId: 4 },
        { id: 47, name: "Kimara", districtId: 4 },
        { id: 48, name: "Saranga", districtId: 4 },
        { id: 49, name: "Sinza", districtId: 4 },
        { id: 50, name: "Makumbusho", districtId: 4 },

        // Kigamboni District
        { id: 51, name: "Kigamboni", districtId: 5 },
        { id: 52, name: "Vijibweni", districtId: 5 },
        { id: 53, name: "Kibada", districtId: 5 },
        { id: 54, name: "Somangira", districtId: 5 },
        { id: 55, name: "Tungi", districtId: 5 },
        { id: 56, name: "Pemba Mnazi", districtId: 5 },
        { id: 57, name: "Kisarawe II", districtId: 5 },

        // Arusha Urban
        { id: 58, name: "Levolosi", districtId: 13 },
        { id: 59, name: "Sombetini", districtId: 13 },
        { id: 60, name: "Unga Limited", districtId: 13 },
        { id: 61, name: "Daraja II", districtId: 13 },
        { id: 62, name: "Sekei", districtId: 13 },
        { id: 63, name: "Terrat", districtId: 13 },
        { id: 64, name: "Themi", districtId: 13 },

        // Mwanza Urban
        { id: 65, name: "Nyamagana", districtId: 49 },
        { id: 66, name: "Isamilo", districtId: 49 },
        { id: 67, name: "Igoma", districtId: 49 },
        { id: 68, name: "Buhongwa", districtId: 49 },
        { id: 69, name: "Pamba", districtId: 49 },
        { id: 70, name: "Mahina", districtId: 49 }
    ],

    villages: [
        // Buguruni Ward (Ilala)
        { id: 1, name: "Buguruni Mnyamani", wardId: 1 },
        { id: 2, name: "Buguruni Malapa", wardId: 1 },
        { id: 3, name: "Buguruni Suna", wardId: 1 },

        // Kariakoo Ward
        { id: 4, name: "Kariakoo Market", wardId: 2 },
        { id: 5, name: "Kariakoo Msimbazi", wardId: 2 },
        { id: 6, name: "Kariakoo Mnazi Mmoja", wardId: 2 },

        // Ilala Ward
        { id: 7, name: "Ilala Boma", wardId: 3 },
        { id: 8, name: "Ilala Kwa Magomeni", wardId: 3 },

        // Jangwani Ward
        { id: 9, name: "Jangwani A", wardId: 4 },
        { id: 10, name: "Jangwani B", wardId: 4 },

        // Kivukoni Ward
        { id: 11, name: "Kivukoni Front", wardId: 5 },
        { id: 12, name: "Kivukoni South", wardId: 5 },

        // Kisutu Ward
        { id: 13, name: "Kisutu Posta", wardId: 6 },
        { id: 14, name: "Kisutu Mnazi Mmoja", wardId: 6 },

        // Pugu Ward
        { id: 15, name: "Pugu Kajiungeni", wardId: 11 },
        { id: 16, name: "Pugu Mjimwema", wardId: 11 },

        // Tabata Ward
        { id: 17, name: "Tabata Relini", wardId: 12 },
        { id: 18, name: "Tabata Matangani", wardId: 12 },
        { id: 19, name: "Tabata Dampo", wardId: 12 },

        // Segerea Ward
        { id: 20, name: "Segerea Kati", wardId: 13 },
        { id: 21, name: "Segerea Mtoni", wardId: 13 },

        // Ukonga Ward
        { id: 22, name: "Ukonga Magharibi", wardId: 14 },
        { id: 23, name: "Ukonga Mashariki", wardId: 14 },

        // Kinyerezi Ward
        { id: 24, name: "Kinyerezi I", wardId: 16 },
        { id: 25, name: "Kinyerezi II", wardId: 16 },

        // Kinondoni Ward
        { id: 26, name: "Kinondoni Mtaa wa Umoja", wardId: 17 },
        { id: 27, name: "Kinondoni Mtaa wa Amani", wardId: 17 },

        // Mwananyamala Ward
        { id: 28, name: "Mwananyamala A", wardId: 18 },
        { id: 29, name: "Mwananyamala B", wardId: 18 },

        // Tandale Ward
        { id: 30, name: "Tandale A", wardId: 19 },
        { id: 31, name: "Tandale B", wardId: 19 },

        // Manzese Ward
        { id: 32, name: "Manzese A", wardId: 20 },
        { id: 33, name: "Manzese B", wardId: 20 },
        { id: 34, name: "Manzese C", wardId: 20 },

        // Sinza Ward
        { id: 35, name: "Sinza Mori", wardId: 22 },
        { id: 36, name: "Sinza Madiola", wardId: 22 },

        // Msasani Ward
        { id: 37, name: "Msasani Peninsula", wardId: 27 },
        { id: 38, name: "Msasani Village", wardId: 27 },

        // Kawe Ward
        { id: 39, name: "Kawe Beach", wardId: 28 },
        { id: 40, name: "Kawe Wazo", wardId: 28 },

        // Mikocheni Ward
        { id: 41, name: "Mikocheni A", wardId: 29 },
        { id: 42, name: "Mikocheni B", wardId: 29 },

        // Temeke Ward
        { id: 43, name: "Temeke Stereo", wardId: 31 },
        { id: 44, name: "Temeke Kwa Aziz Ali", wardId: 31 },

        // Mbagala Ward
        { id: 45, name: "Mbagala Kuu", wardId: 35 },
        { id: 46, name: "Mbagala Rangi Tatu", wardId: 35 },
        { id: 47, name: "Mbagala Chamazi", wardId: 35 },

        // Kigamboni Ward
        { id: 48, name: "Kigamboni Ferry", wardId: 51 },
        { id: 49, name: "Kigamboni Mtoni", wardId: 51 },

        // Ubungo Ward
        { id: 50, name: "Ubungo Maji Matitu", wardId: 41 },
        { id: 51, name: "Ubungo Maziwa", wardId: 41 },

        // Kimara Ward
        { id: 52, name: "Kimara Baruti", wardId: 47 },
        { id: 53, name: "Kimara Matangini", wardId: 47 }
    ]
};

// Utility functions to get location data
function getRegions() {
    return tanzaniaLocations.regions;
}

function getDistrictsByRegion(regionId) {
    return tanzaniaLocations.districts.filter(d => d.regionId == regionId);
}

function getWardsByDistrict(districtId) {
    return tanzaniaLocations.wards.filter(w => w.districtId == districtId);
}

function getVillagesByWard(wardId) {
    return tanzaniaLocations.villages.filter(v => v.wardId == wardId);
}

function getRegionName(regionId) {
    const region = tanzaniaLocations.regions.find(r => r.id == regionId);
    return region ? region.name : '';
}

function getDistrictName(districtId) {
    const district = tanzaniaLocations.districts.find(d => d.id == districtId);
    return district ? district.name : '';
}

function getWardName(wardId) {
    const ward = tanzaniaLocations.wards.find(w => w.id == wardId);
    return ward ? ward.name : '';
}

function getVillageName(villageId) {
    const village = tanzaniaLocations.villages.find(v => v.id == villageId);
    return village ? village.name : '';
}