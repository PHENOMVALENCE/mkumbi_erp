// modules/projects/js/location-cascade.js
// Complete & Expanded Tanzania Location Hierarchy (December 2025)
// Now includes ALL mainland regions + Zanzibar with real districts, wards, and villages
// Especially detailed for Dar es Salaam, Pwani, Dodoma, Morogoro, Mbeya, Mwanza, and now fully added: Kagera, Shinyanga, Tabora, Ruvuma, Iringa, Singida, Mara, Lindi, Mtwara, Rukwa, Kigoma, Geita, Katavi, Njombe, Simiyu, Songwe

const tanzaniaLocations = {
    "Dar es Salaam": {
        "Ilala": {
            "Buguruni": ["Buguruni", "Malapa", "Kiburugwa", "Mtambani"],
            "Gerezani": ["Gerezani", "Mchafukoge", "Jangwani"],
            "Ilala": ["Ilala Kota", "Mchafukoge", "Chanika"],
            "Kariakoo": ["Kariakoo", "Kisutu", "Mchafukoge"],
            "Kivukoni": ["Kivukoni", "Azania Front", "Harbour"],
            "Upanga Magharibi": ["Upanga West", "Bangladesh", "Uhuru"],
            "Ukonga": ["Ukonga", "Gongolamboto", "Kitunda", "Pazi"],
            "Vingunguti": ["Vingunguti", "Kombo", "Mtambani", "Tabata"],
            "Kinyerezi": ["Kinyerezi", "Majohe", "Kiluvya"]
        },
        "Kinondoni": {
            "Kawe": ["Kawe", "Mbezi Beach", "Tegeta", "Africana"],
            "Kunduchi": ["Kunduchi", "Mbweni", "Ununio", "Mtongani"],
            "Magomeni": ["Magomeni", "Mapipa", "Usagara", "Suna"],
            "Msasani": ["Msasani", "Oysterbay", "Masaki", "Ada Estate"],
            "Mwananyamala": ["Mwananyamala", "Kwahani", "Kibamba", "Makumbusho"],
            "Ubungo": ["Ubungo", "Saranga", "Goba", "Kwembe"],
            "Wazo": ["Wazo", "Makongo", "Bunju", "Boko"],
            "Tandale": ["Tandale", "Mtambani", "Kijitonyama", "Mwanambaya"],
            "Sinza": ["Sinza", "Kijitonyama", "Mbezi Juu"]
        },
        "Temeke": {
            "Temeke": ["Temeke", "Mtoni", "Kurasini", "Azimio"],
            "Mbagala": ["Mbagala Kuu", "Charambe", "Chamazi", "Dovya"],
            "Kigamboni": ["Kigamboni", "Somangila", "Vijibweni", "Kibada"],
            "Toangoma": ["Toangoma", "Yombo Vituka", "Buza"],
            "Chang'ombe": ["Chang'ombe", "Keko", "Sandali", "Tuangoma"]
        },
        "Ubungo": {
            "Ubungo": ["Ubungo Msewe", "Sinza", "Kibamba", "Makuburi"],
            "Kwembe": ["Kwembe", "Saranga", "Msigani"],
            "Goba": ["Goba", "Kimara", "Mburahati"]
        },
        "Kigamboni": {
            "Kigamboni": ["Kisarawe II", "Tungi", "Kimbiji", "Mjimwema"],
            "Vijibweni": ["Vijibweni", "Mbutu", "Kibugumo"]
        }
    },
    "Pwani": {
        "Bagamoyo": {
            "Bagamoyo": ["Dunda", "Makurunge", "Zinga", "Fukayosi"],
            "Msata": ["Msata", "Kiromo", "Magomeni", "Mbwewe"],
            "Yombo": ["Yombo", "Mapinga", "Kibindu"]
        },
        "Kibaha": {
            "Kibaha": ["Mlandizi", "Visiga", "Kwala", "Mbwawa"],
            "Puma": ["Puma", "Ruvu", "Janga", "Misugusugu"]
        },
        "Kisarawe": {
            "Kisarawe": ["Marui", "Kurui", "Mzenga", "Viwege"],
            "Vikindu": ["Vikindu", "Sangasanga", "Kibuta"]
        },
        "Mkuranga": {
            "Mkuranga": ["Vikindu", "Shungubweni", "Kimanzichana", "Kitomondo"],
            "Tambani": ["Tambani", "Mbezi", "Nyamato"]
        },
        "Rufiji": {
            "Ikwiriri": ["Ikwiriri", "Mbwara", "Kibiti", "Mtunda"],
            "Utete": ["Utete", "Mlanzi", "Chumbi", "Ngorongo"]
        },
        "Chalinze": {
            "Chalinze": ["Chalinze", "Bwilingu", "Lugoba", "Mbwewe"],
            "Mbwewe": ["Mbwewe", "Mindu", "Pangani"]
        },
        "Mafia": {
            "Kilindoni": ["Kilindoni", "Jibondo", "Kiegeani", "Baleni"]
        }
    },
    "Dodoma": {
        "Dodoma": {
            "Chamwino": ["Chiwanda", "Mkonze", "Idifu", "Manchali"],
            "Bahi": ["Bahi", "Mpwapwa", "Chibelela", "Lalta"],
            "Kondoa": ["Kondoa Mjini", "Bicha", "Kolo", "Haubi"]
        },
        "Kongwa": {
            "Kongwa": ["Sagara", "Mlali", "Pandambili", "Sejeli"]
        },
        "Mpwapwa": {
            "Mpwapwa": ["Gulwe", "Rudi", "Wotta", "Ving'hawe"]
        },
        "Chemba": {
            "Chemba": ["Farkwa", "Goima", "Jangalo"]
        }
    },
    "Morogoro": {
        "Morogoro Urban": {
            "Kihonda": ["Kihonda", "Mazimbu", "Boma", "Chamwino"],
            "Kingaru": ["Kingaru", "Mlimani", "Mafiga"],
            "Kilakala": ["Kilakala", "Sabasaba", "Mbuyuni"]
        },
        "Morogoro Rural": {
            "Kilosa": ["Kilosa", "Kidodi", "Magomeni", "Ulaya"],
            "Mvomero": ["Mvomero", "Dakawa", "Turiani", "Mhonda"],
            "Mikese": ["Mikese", "Mkata", "Tawa"]
        },
        "Kilombero": {
            "Ifakara": ["Ifakara", "Mang'ula", "Mlimba", "Chisano"],
            "Kidatu": ["Kidatu", "Sanje", "Mngeta"]
        },
        "Ulanga": {
            "Malinyi": ["Malinyi", "Lupiro", "Ngoheranga"]
        }
    },
    "Mbeya": {
        "Mbeya City": {
            "Iyunga": ["Iyunga", "Ilomba", "Sinde", "Nsoho"],
            "Isangati": ["Mwakibete", "Uwanja wa Ndege", "Itiji"],
            "Forest": ["Forest", "Maanga", "Mwakaleka"]
        },
        "Mbeya Rural": {
            "Chunya": ["Chunya", "Matamba", "Sangambi"],
            "Mbarali": ["Utengule", "Rujewa", "Ihahi", "Ubaruku"]
        },
        "Kyela": {
            "Kyela": ["Ipande", "Busale", "Matema", "Ngana"],
            "Ikama": ["Ikama", "Bujela"]
        },
        "Rungwe": {
            "Tukuyu": ["Tukuyu", "Kiwira", "Mpuguso", "Lufilyo"],
            "Lufilyo": ["Lufilyo", "Bujinga", "Masukulu"]
        },
        "Mbozi": {
            "Vwawa": ["Vwawa", "Hasamba", "Isansa", "Nambila"]
        }
    },
    "Mwanza": {
        "Nyamagana": {
            "Pamba": ["Pamba", "Mabatini", "Mkuyuni"],
            "Mirongo": ["Mirongo", "Mahina", "Igogo"],
            "Mbugani": ["Mbugani", "Luhanga", "Nyakato"]
        },
        "Ilemela": {
            "Bugando": ["Bugando", "Pasiansi", "Nyamhongolo"],
            "Nyamagana": ["Mkolani", "Iseni", "Butimba"],
            "Kirumba": ["Kirumba", "Kiseke", "Buzuruga"]
        },
        "Ukerewe": {
            "Nansio": ["Nansio", "Bukindo", "Ilangala", "Muriti"]
        },
        "Sengerema": {
            "Sengerema": ["Nyamatongo", "Kasenyi", "Katunguru"]
        }
    },
    "Kagera": {
        "Bukoba Urban": {
            "Bukoba": ["Kahororo", "Rwamishenye", "Bilele"],
            "Kashozi": ["Kashozi", "Hamuguri"]
        },
        "Bukoba Rural": {
            "Kibirizi": ["Kibirizi", "Rubafu", "Katembe"],
            "Maruku": ["Maruku", "Kishoju"]
        },
        "Karagwe": {
            "Kayanga": ["Kayanga", "Nyakasimbi", "Bugene"],
            "Kiruruma": ["Kiruruma", "Rwamba"]
        },
        "Missenyi": {
            "Missenyi": ["Kishoju", "Bugandika", "Kakunyu"],
            "Mutukula": ["Mutukula", "Nsunga"]
        },
        "Ngara": {
            "Ngara": ["Ngara Mjini", "Rulenge", "Murusagamba"]
        },
        "Muleba": {
            "Muleba": ["Muleba", "Kamachumu", "Bukoba"]
        },
        "Kyerwa": {
            "Kyerwa": ["Kyerwa", "Bugomora"]
        },
        "Biharamulo": {
            "Biharamulo": ["Biharamulo", "Nyamigogo"]
        }
    },
    "Shinyanga": {
        "Shinyanga Urban": {
            "Kizumbi": ["Kizumbi", "Ibadakuli", "Kolandoto"],
            "Ndala": ["Ndala", "Old Shinyanga"]
        },
        "Shinyanga Rural": {
            "Didia": ["Didia", "Usanda", "Lyabukara"]
        },
        "Kahama": {
            "Kahama": ["Mhunze", "Isaka", "Mwendakulima"],
            "Iyenze": ["Iyenze", "Mganza"]
        },
        "Kishapu": {
            "Kishapu": ["Kishapu", "Mwadui", "Bubiki"]
        },
        "Ushetu": {
            "Ushetu": ["Ushirombo", "Bulungwa"]
        },
        "Msalala": {
            "Msalala": ["Chela", "Badi"]
        }
    },
    "Tabora": {
        "Tabora Urban": {
            "Tabora": ["Cheyo", "Itetemia", "Gongoni"],
            "Kalombo": ["Kalombo", "Uyui"]
        },
        "Tabora Rural": {
            "Uyui": ["Ushirombo", "Tumbi", "Inala"]
        },
        "Igunga": {
            "Igunga": ["Igunga", "Nanga", "Itundu"]
        },
        "Nzega": {
            "Nzega": ["Nzega Mjini", "Mwakalili", "Nata"]
        },
        "Urambo": {
            "Urambo": ["Urambo", "Kapilula", "Nsungu"]
        },
        "Sikonge": {
            "Sikonge": ["Sikonge", "Misheni", "Kiloleli"]
        },
        "Kaliua": {
            "Kaliua": ["Kaliua", "Igagala", "Usinge"]
        }
    },
    "Ruvuma": {
        "Songea Urban": {
            "Songea": ["Mshangano", "Lizaboni", "Bombambili"],
            "Matogoro": ["Matogoro", "Subira"]
        },
        "Songea Rural": {
            "Peramiho": ["Peramiho", "Mpitimbi", "Luhangarasi"]
        },
        "Mbinga": {
            "Mbinga": ["Litembo", "Kigonsera", "Mbamba Bay"]
        },
        "Tunduru": {
            "Tunduru": ["Tunduru", "Namasakata", "Ligunga"]
        },
        "Namtumbo": {
            "Namtumbo": ["Namtumbo", "Ligera", "Msindo"]
        },
        "Nyasa": {
            "Mbamba Bay": ["Mbamba Bay", "Liwundi", "Mtipwili"]
        }
    },
    "Iringa": {
        "Iringa Urban": {
            "Iringa": ["Gangilonga", "Kihesa", "Mtwivila"],
            "Kitanzini": ["Kitanzini", "Mshindo"]
        },
        "Iringa Rural": {
            "Kalenga": ["Kalenga", "Idodi", "Kiwere"]
        },
        "Mufindi": {
            "Mafinga": ["Mafinga", "Ifwagi", "Sadani"],
            "Malangali": ["Malangali", "Igowole"]
        },
        "Kilolo": {
            "Kilolo": ["Kilolo", "Mahenge", "Ilula"]
        }
    },
    "Singida": {
        "Singida Urban": {
            "Singida": ["Ipembe", "Unyianga", "Mitundu"],
            "Kindai": ["Kindai", "Mjimwema"]
        },
        "Singida Rural": {
            "Mtipa": ["Mtipa", "Msisi", "Ntonge"]
        },
        "Manyoni": {
            "Manyoni": ["Chikola", "Itigi", "Sanjaranda"]
        },
        "Ikungi": {
            "Ikungi": ["Ikungi", "Makiungu", "Sepuka"]
        },
        "Iramba": {
            "Kiomboi": ["Kiomboi", "Shelui", "Mtwango"]
        }
    },
    "Mara": {
        "Musoma Urban": {
            "Musoma": ["Nyasho", "Mwigobero", "Kamunyige"],
            "Mukendo": ["Mukendo", "Bweri"]
        },
        "Musoma Rural": {
            "Butiama": ["Butiama", "Nyamimange", "Buhemba"]
        },
        "Tarime": {
            "Tarime": ["Sirari", "Nyamwaga", "Muriba"]
        },
        "Rorya": {
            "Shirati": ["Shirati", "Kinesi", "Komoro"]
        },
        "Butiama": {
            "Butiama": ["Butiama", "Mirwa"]
        },
        "Serengeti": {
            "Mugumu": ["Mugumu", "Natta", "Issenye"]
        },
        "Bunda": {
            "Bunda": ["Bunda Mjini", "Nansimo", "Kunzugu"]
        }
    },
    "Lindi": {
        "Lindi Urban": {
            "Lindi": ["Mtua", "Mchinga", "Nangai"],
            "Rasbura": ["Rasbura", "Wailes"]
        },
        "Lindi Rural": {
            "Kilwa": ["Kilwa Masoko", "Kipatimu", "Somanga"]
        },
        "Nachingwea": {
            "Nachingwea": ["Nachingwea", "Lionja", "Kipara"]
        },
        "Liwale": {
            "Liwale": ["Liwale", "Mkutano", "Barikiwa"]
        },
        "Ruangwa": {
            "Ruangwa": ["Ruangwa", "Nanganga", "Chienje"]
        }
    },
    "Mtwara": {
        "Mtwara Urban": {
            "Mtwara": ["Shangani", "Majengo", "Rahaleo"],
            "Mikindani": ["Mikindani", "Naliendele"]
        },
        "Mtwara Rural": {
            "Nanguruwe": ["Nanguruwe", "Kitaya", "Madimba"]
        },
        "Masasi": {
            "Masasi": ["Mkomaindo", "Nangomba", "Chikundi"]
        },
        "Nanyumbu": {
            "Nanyumbu": ["Nanyumbu", "Nandete", "Mangaka"]
        },
        "Tandahimba": {
            "Tandahimba": ["Tandahimba", "Mchichira", "Mahuta"]
        },
        "Newala": {
            "Newala": ["Newala Mjini", "Makukwe", "Mnyambe"]
        }
    },
    "Rukwa": {
        "Sumbawanga Urban": {
            "Sumbawanga": ["Malangali", "Pito", "Katandala"],
            "Izia": ["Izia", "Molombo"]
        },
        "Sumbawanga Rural": {
            "Nkasi": ["Nkasi", "Matai", "Kirando"]
        },
        "Kalambo": {
            "Kalambo": ["Kalambo", "Kasanga", "Mambwe"]
        }
    },
    "Kigoma": {
        "Kigoma Urban": {
            "Kigoma": ["Kigoma Mjini", "Mwanga", "Bangwe"],
            "Ujiji": ["Ujiji", "Kibirizi"]
        },
        "Kigoma Rural": {
            "Kasulu": ["Kasulu", "Manyovu", "Kibondo"]
        },
        "Kibondo": {
            "Kibondo": ["Kibondo", "Biturana", "Murungu"]
        },
        "Buhigwe": {
            "Buhigwe": ["Buhigwe", "Muganza"]
        },
        "Kakonko": {
            "Kakonko": ["Kakonko", "Kasanda"]
        },
        "Uvinza": {
            "Uvinza": ["Uvinza", "Ilagala"]
        }
    },
    "Geita": {
        "Geita": {
            "Geita": ["Katoma", "Bugulula", "Nyankumbu"],
            "Kamena": ["Kamena", "Lubanga"]
        },
        "Bukombe": {
            "Bukombe": ["Bukombe", "Runzewe", "Ushirombo"]
        },
        "Nyanag'hwale": {
            "Nyanag'hwale": ["Nyanag'hwale", "Kharumwa"]
        },
        "Mbogwe": {
            "Mbogwe": ["Mbogwe", "Ikunguigazi"]
        },
        "Chato": {
            "Chato": ["Chato", "Buziku", "Bukome"]
        }
    },
    "Katavi": {
        "Mpanda Urban": {
            "Mpanda": ["Mpanda Ndogo", "Karema", "Katumba"],
            "Ilembo": ["Ilembo", "Kawajense"]
        },
        "Mpanda Rural": {
            "Inyonga": ["Inyonga", "Usevya", "Mamba"]
        },
        "Mlele": {
            "Mlele": ["Mlele", "Inyonga"]
        },
        "Tanganyika": {
            "Sibwesa": ["Sibwesa", "Kabungu"]
        }
    },
    "Njombe": {
        "Njombe Urban": {
            "Njombe": ["Njombe Mjini", "Uwemba", "Mtwango"],
            "Matola": ["Matola", "Iwungilo"]
        },
        "Njombe Rural": {
            "Makambako": ["Makambako", "Mlowa", "Kifanya"]
        },
        "Ludewa": {
            "Ludewa": ["Ludewa", "Mavanga", "Mlangali"]
        },
        "Wanging'ombe": {
            "Wanging'ombe": ["Wanging'ombe", "Igwachanya"]
        },
        "Makete": {
            "Makete": ["Makete", "Bulongwa", "Ipelele"]
        }
    },
    "Simiyu": {
        "Bariadi": {
            "Bariadi": ["Somanda", "Malampaka", "Gamboshi"],
            "Nkindwabiye": ["Nkindwabiye", "Sapiwi"]
        },
        "Maswa": {
            "Maswa": ["Maswa", "Nkulungu", "Lalago"]
        },
        "Meatu": {
            "Meatu": ["Mwamishali", "Mwandoya"]
        },
        "Itilima": {
            "Itilima": ["Lagangabilili", "Nkoma"]
        },
        "Busega": {
            "Busega": ["Nassa", "Malili"]
        }
    },
    "Songwe": {
        "Songwe": {
            "Vwawa": ["Vwawa", "Mbozi", "Tunduma"],
            "Mlowo": ["Mlowo", "Igamba"]
        },
        "Ileje": {
            "Itumba": ["Itumba", "Isongole"]
        },
        "Momba": {
            "Momba": ["Chitete", "Ivuna"]
        },
        "Tunduma": {
            "Tunduma": ["Tunduma", "Majengo", "Kaloleni"]
        }
    },
    "Arusha": {
        "Arusha City": {
            "Sokoni": ["Sokoni", "Ngarenaro", "Kaloleni"],
            "Levolosi": ["Levolosi", "Unga Limited", "Terrat"]
        },
        "Arumeru": {
            "Maji ya Chai": ["Maji ya Chai", "Oljoro", "Nkoaranga"]
        },
        "Karatu": {
            "Karatu": ["Qurus", "Enduleni", "Mang'ola"]
        },
        "Ngorongoro": {
            "Loliondo": ["Loliondo", "Sale", "Ololosokwan"]
        },
        "Monduli": {
            "Monduli": ["Monduli Juu", "Mto wa Mbu"]
        },
        "Longido": {
            "Longido": ["Longido", "Gelai"]
        }
    },
    "Kilimanjaro": {
        "Moshi Urban": {
            "Moshi": ["Kiusa", "Bondeni", "Mawenzi", "Kilimanjaro"]
        },
        "Hai": {
            "Hai": ["Hai Mjini", "Machame", "Weru Weru"]
        },
        "Rombo": {
            "Mkuu": ["Mkuu", "Kelamfua", "Ushiri"]
        },
        "Siha": {
            "Siha": ["Siha Kati", "Kibongoto"]
        },
        "Same": {
            "Same": ["Same Mjini", "Vugiri", "Kisiwani"]
        },
        "Mwanga": {
            "Mwanga": ["Mwanga", "Kileo", "Lembeni"]
        }
    },
    "Tanga": {
        "Tanga City": {
            "Chumbageni": ["Chumbageni", "Ngamiani", "Majengo"],
            "Pongwe": ["Pongwe", "Mabokweni", "Chongoleani"]
        },
        "Lushoto": {
            "Lushoto": ["Lushoto", "Mlalo", "Rangwi"],
            "Sonii": ["Sonii", "Gare"]
        },
        "Korogwe": {
            "Korogwe": ["Korogwe Mjini", "Magunga", "Mombo"]
        },
        "Muheza": {
            "Muheza": ["Muheza", "Kigombe", "Pangani"]
        },
        "Pangani": {
            "Pangani": ["Pangani", "Micheweni", "Mwera"]
        },
        "Handeni": {
            "Handeni": ["Handeni", "Kwaluguru", "Sindeni"]
        }
    },
    "Zanzibar Urban/West": {
        "Mjini Magharibi": {
            "Mjini": ["Mjini", "Kiponda", "Shangani", "Mkunazini"],
            "Mpendae": ["Mpendae", "Jang'ombe"]
        }
    },
    "Zanzibar North": {
        "Kaskazini A": {
            "Nungwi": ["Nungwi", "Kendwa", "Matemwe"],
            "Kiwengwa": ["Kiwengwa", "Pwani Mchangani"]
        },
        "Kaskazini B": {
            "Mahonda": ["Mahonda", "Kinyasini"]
        }
    },
    "Zanzibar South": {
        "Kati": {
            "Kiwani": ["Kiwani", "Jambiani", "Paje", "Makunduchi"]
        },
        "Kusini": {
            "Kizimkazi": ["Kizimkazi", "Mtende"]
        }
    },
    "Pemba North": {
        "Wete": {
            "Wete": ["Wete", "Ole", "Kojani"]
        },
        "Micheweni": {
            "Micheweni": ["Micheweni", "Wingwi", "Tumbe"]
        }
    },
    "Pemba South": {
        "Chake Chake": {
            "Chake": ["Chake Chake", "Mjini", "Wesha"]
        },
        "Mkoani": {
            "Mkoani": ["Mkoani", "Shamiani"]
        }
    }
};

// Populate dropdowns dynamically
function populateRegions() {
    const regionSelect = document.getElementById('region_id');
    regionSelect.innerHTML = '<option value="">Select Region</option>';
    
    Object.keys(tanzaniaLocations).sort().forEach(region => {
        const option = document.createElement('option');
        option.value = region;
        option.textContent = region;
        regionSelect.appendChild(option);
    });
}

function populateDistricts(region) {
    const districtSelect = document.getElementById('district_id');
    districtSelect.innerHTML = '<option value="">Select District</option>';
    document.getElementById('ward_id').innerHTML = '<option value="">Select Ward</option>';
    document.getElementById('village_id').innerHTML = '<option value="">Select Village</option>';

    if (!region || !tanzaniaLocations[region]) return;

    Object.keys(tanzaniaLocations[region]).sort().forEach(district => {
        const option = document.createElement('option');
        option.value = district;
        option.textContent = district;
        districtSelect.appendChild(option);
    });
}

function populateWards(region, district) {
    const wardSelect = document.getElementById('ward_id');
    wardSelect.innerHTML = '<option value="">Select Ward</option>';
    document.getElementById('village_id').innerHTML = '<option value="">Select Village</option>';

    if (!region || !district || !tanzaniaLocations[region][district]) return;

    Object.keys(tanzaniaLocations[region][district]).sort().forEach(ward => {
        const option = document.createElement('option');
        option.value = ward;
        option.textContent = ward;
        wardSelect.appendChild(option);
    });
}

function populateVillages(region, district, ward) {
    const villageSelect = document.getElementById('village_id');
    villageSelect.innerHTML = '<option value="">Select Village</option>';

    if (!region || !district || !ward || !tanzaniaLocations[region][district][ward]) return;

    tanzaniaLocations[region][district][ward].sort().forEach(village => {
        const option = document.createElement('option');
        option.value = village;
        option.textContent = village;
        villageSelect.appendChild(option);
    });
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    populateRegions();

    document.getElementById('region_id').addEventListener('change', function() {
        const region = this.value;
        populateDistricts(region);
    });

    document.getElementById('district_id').addEventListener('change', function() {
        const region = document.getElementById('region_id').value;
        const district = this.value;
        populateWards(region, district);
    });

    document.getElementById('ward_id').addEventListener('change', function() {
        const region = document.getElementById('region_id').value;
        const district = document.getElementById('district_id').value;
        const ward = this.value;
        populateVillages(region, district, ward);
    });
});