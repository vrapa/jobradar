# Plán rozšíření a vyhodnocování projektového hledání

Stav: návrh k postupné implementaci. Tento dokument nemění chování aplikace ani aktivní konfiguraci a neopravňuje ke spuštění kontroly nebo odeslání reakce. Hlavní specifikací zůstává [implementační plán](implementation-plan.md).

## Cíl a hranice

Umožnit vlastníkovi porovnat různé typy projektových příležitostí podle skutečné dostupnosti, doložených podmínek a obchodního výsledku. Rozlišovat nový vývoj s návrhem řešení, převzetí a modernizaci existující aplikace a dokončení prototypu pro produkční provoz. Jde o obecné možnosti produktu, nikoli výchozí osobní strategii.

Konkrétní trhy, portály, dotazy, sazby, jazyky, komunikační omezení, rozpočty kontrol a nabídka služeb patří do verzované soukromé konfigurace. Veřejný repozitář obsahuje pouze obecné chování a nezávisle syntetická data. Změny konfigurace se provádějí podporovaným správním importem nebo webem; vykonavatel a MCP používají API bez přímého přístupu k MySQL.

## Ověřený základ a dosud neověřené předpoklady

Kontrola zdrojového kódu a dokumentace potvrzuje:

- `SearchPlan` přijímá 1–10 uspořádaných kroků s dotazem, režimem, filtry a dílčím limitem; parser odmítá neznámá pole.
- `SourceSettingsService::savePlan` vytváří novou verzi zadání. Požadavky používají připnutou definici a změna zadání sama nespouští kontrolu.
- `search_run_steps` eviduje průchod kroku a `search_step_opportunities` spojuje krok s importovanými nabídkami. Výskyt v několika krocích nemusí být několik různých příležitostí.
- `OpportunityTermsInput` již obsahuje odměnu, rozsah, remote režim, možnost práce z ČR, lokalitu, časové pásmo, pracovní jazyk a komunikaci.
- `projectCare` je doložená tříhodnotová klasifikace péče o aplikaci; role protistrany má vlastní historii. Tyto údaje nepopisují všechny typy projektu.
- Pokyny předvýběru už připouštějí nové projekty, volbu technologií a podstatnou shodu hlavního stacku. Chybějící doplňková znalost není automaticky důvodem k odmítnutí.

Nebyla ověřena aktivní soukromá konfigurace, nasazená verze aplikace ani výtěžnost konkrétních zdrojů. Z veřejné dokumentace nelze vyvozovat, že některý trh chybí nebo že historické nabídky byly nesprávně odmítnuté.

## 1. Kontrola aktuální konfigurace a návrh její změny

**Rozsah:** soukromá konfigurace; přednostně bez změny kódu.

1. Přes podporované rozhraní načíst aktivní profil, pravidla a definice zdrojů. Zvlášť zaznamenat rozpracované požadavky a jejich připnuté verze.
2. Rozlišit ověřenou praxi, preference, nepominutelné podmínky a neznámé údaje. Novější výslovné pokyny vlastníka mají přednost; chybějící jazykovou nebo technickou způsobilost nevymýšlet.
3. U každého zadání označit, zda vyhledává konkrétní technologii, pracovní roli nebo problém zákazníka. Zjistit, které typy projektů současné dotazy skutečně pokrývají.
4. Připravit soukromý přehled ponechat / změnit / doplnit s důvodem a návrhem nových verzí. Pořadí trhů odvodit od dostupnosti spolupráce a doložené ekonomiky, nikoli pouze sídla klienta.

**Výstup:** konkrétní náhled změn a seznam chybějících údajů, které mají dopad na výběr. Existující preference se nepřepisují odhadem; potvrzení se vyžaduje pouze pro nevyjasněné změny, které dosavadní zadání nepokrývá.

**Hotovo, když:** každý navržený filtr má podklad a je jasné, které připnuté požadavky zůstanou na staré konfiguraci.

## 2. Samostatná zadání podle typu projektu

**Rozsah:** nové verze soukromých definic a případné zobecnění pokynů vykonavatele.

| Typ projektu | Co má předvýběr rozpoznat | Co musí posouzení ověřit |
|---|---|---|
| Nový vývoj a návrh řešení | Skutečné zadání, prostor pro volbu řešení, požadovanou odpovědnost | Cíl, rozsah, rozhodovací pravomoc, rozpočet, placenou analýzu, očekávání realizace a provozu |
| Převzetí a modernizace | Existující aplikaci a placený rozvoj nebo správu | Důvod předání, přístupy a práva, stack, testy, nasazení, zálohy, pohotovost a vstupní audit |
| Dokončení prototypu | Potřebu uvést rozpracovanou aplikaci do produkce | Stav produktu, uživatele, integrace, data, oprávnění, opravitelnost, rozpočet a následnou péči |

- Použít stávající `key`, `name`, `query`, `mode`, `filters` a `limit`. Nevkládat nové nepodporované atributy do zadání.
- Při prvním srovnání uchovat soukromou vazbu typu hledání na konkrétní verzi definice a klíč kroku. Stejný klíč u různých zdrojů nemusí mít stejný význam.
- Dotazy odvozovat také od problému klienta. U nové aplikace nevyřazovat příležitost jen proto, že zatím nemá určený stack; závazný nesplněný technický požadavek zůstává překážkou.
- AI původ kódu sám o sobě nepotvrzuje vadnost, vhodnost ani vyšší hodnotu projektu. Dokončení prototypu může zahrnovat i aplikaci vzniklou běžným vývojem.
- Potvrzenou neslučitelnost jazyka, docházky nebo dostupnosti odmítnout podle pravidel. Neuvedenou podmínku uložit jako otázku; jazyk inzerátu ani označení remote nejsou důkazem způsobilosti.
- Rozdělit dílčí limity tak, aby první větev nemohla spotřebovat rozpočet plánovaný pro ostatní. Respektovat společný limit a nejvýše deset kroků. Kategorie s navazujícími výsledky mají odlišné počítání; pro srovnání evidovat skutečné zpracované kandidáty, nikoli zaměňovat počty kategorií a nabídek.
- Nepodporovaný filtr nahradit doloženým kontrolovatelným postupem v nové definici, nebo jej označit jako nepokrytý. Neměnit rozsah běžícího požadavku potichu.

**Hotovo, když:** každá plánovaná větev má proveditelný postup, vlastní rozpočet, dohledatelnou verzi a pravidla posouzení. Uložení zadání nevytvoří nový běh.

## 3. Posouzení obchodní vhodnosti a příprava nabídky služby

**Rozsah:** soukromá hodnoticí pravidla a šablony; využití stávajících podmínek, nálezů a otázek.

- Posouzení oddělí technickou proveditelnost, dostupnost spolupráce a obchodní vhodnost. Důvody i nejistoty budou čitelné bez jediného souhrnného skóre.
- U odměny rozlišit hodinovou/denní sazbu a projektový rozpočet. Bez doložené pracnosti nevytvářet hodinový přepočet; poplatky, pohotovost a neplacenou komunikaci uvést jen v rozsahu známých podkladů.
- Doložit kontakt s rozhodující osobou, možnost placeného úvodního posouzení a potenciál navazující péče. Agentura není automaticky nevhodná a přímý klient není automaticky výhodný.
- Použít stávající nálezy a otázky pro neznámý rozpočet, podmínky auditu, stav projektu nebo komunikaci. Nové bodové váhy a automatické rozhodování nejsou součástí první změny.
- Soukromě připravit popis výstupu služby pro každý zvolený typ projektu: úvodní posouzení, návrh další etapy, realizace a případná péče. Profesní tvrzení musí odpovídat doloženým zkušenostem.

**Hotovo, když:** u příležitosti lze vysvětlit, proč stojí za další ověření, co není známé a jaký konkrétní první placený krok připadá v úvahu. Šablona ani klasifikace nic neodesílá.

## 4. Omezený pilot a srovnání výsledků

**Závislosti:** dokončené body 1–3 a samostatný výslovný požadavek na hledání.

Před spuštěním soukromě určit zdroje, větve, období, rozpočet výsledků a času i podmínku ukončení. Nevytvářet automatické opakování. Je-li to možné, porovnávat větve na stejných zdrojích v podobném období; pořadí a rozdílné pokrytí uvést jako omezení.

První vyhodnocení využije existující databázové záznamy dostupné přes web/API. Pokud potřebná evidence není dostupná, doplnit nejmenší nutný přehled podle bodu 5; nenahrazovat ji nezávislou evidencí skutečných nabídek mimo JobRadar.

Srovnání obsahuje:

- plánované a skutečné pokrytí, přerušení a vynechané kroky;
- počty zpracovaných kandidátů, otevřených detailů, importů a unikátních příležitostí;
- doložené překážky a počet nabídek s nevyjasněnými podmínkami;
- počet posouzených vhodných příležitostí a zvlášť uživatelská rozhodnutí `reagovat`;
- známou odměnu s měnou, jednotkou a velikostí vzorku; neznámé údaje zvlášť;
- aktivní čas kontroly, pokud jej evidence skutečně umožňuje určit. Doba čekání na přihlášení či obnovení není čas práce;
- při pozdějším vyhodnocení doložené odeslání a odpověď. Placenou zakázku evidovat až s odpovídajícím podkladem; samotná reakce nebo uzavření žádosti ji nepotvrzují.

Jedna nabídka se může objevit v několika větvích: uvést zásah každé větve, průnik a celkový počet unikátních nabídek. Součet větví nesmí být vydáván za počet různých projektů. Nulový nebo neznámý jmenovatel se nezobrazí jako nulová úspěšnost. Při chybějícím pokrytí či malém vzorku je závěr „nedostatek podkladů“, nikoli „trh nefunguje“.

**Výstup:** soukromé rozhodnutí ponechat / upravit / upozadit pro jednotlivé definice, podložené nálezy a limity srovnání. Další hledání vyžaduje nový konkrétní pokyn.

## 5. Cílené změny produktu podle zjištěných mezer

Tyto změny se nerealizují automaticky všechny před pilotem. Nejprve určit, zda chybějící schopnost nelze pokrýt existujícím rozhraním.

| Změna | Kdy ji zařadit | Místa změny a akceptace |
|---|---|---|
| Přehled výsledků po krocích | Stávající detail neumožní potřebné srovnání | `SearchStepService`, čtecí API a webový přehled; vazba na připnutou definici, oddělení kategorií a kandidátů, deduplikace, oprávnění vlastníka, neúplnost a neznámé hodnoty |
| Typ projektu na nabídce | Je potřebný filtr napříč zdroji nezávislý na dotazu nálezu | Doména Opportunity, import, API/MCP, detail a přehled; samostatná doložená klasifikace s historií a vazbou na verzi, podpora kombinací a neověřeného stavu; zachovat význam `projectCare` |
| Strukturované údaje obchodní vhodnosti | Textové nálezy nestačí pro opakované filtrování | Nejprve určit konkrétní chybějící pole; doplnit vstupní validaci, původ, jistotu a čas. Neduplikovat existující sazbu, jazyk ani remote režim |
| Trvalé seskupení hledání | Ruční vazba verzí a kroků nestačí pro dlouhodobé reporty | Verzovaná metadata s jednotným kontraktem v `SearchPlan`, správě zdrojů, importu konfigurace a vykonavateli; staré definice mají neznámé seskupení, nikoli odhad |

Kódové změny doprovodí aktualizace `docs/implementation-plan.md`, dotčených API kontraktů v `docs/openapi.yaml` a pokynů vykonavatele. Rozšíření bude zpětně kompatibilní: chybějící pole nemění historii, historické nabídky se bez důkazů nepřeklasifikují. Nové migrace obsahují pouze potřebné schéma, žádné osobní seedy. Klasifikace typu projektu se neodvozuje automaticky z dotazu, kterým byla nabídka nalezena.

## Ověření a pořadí dodání

1. **Konfigurační návrh:** soukromý náhled změn a vyjasnění pouze skutečně chybějících podmínek.
2. **Zadání a pokyny:** nové verze definic, pravidla posouzení a rozpočty; bez spuštění hledání.
3. **Minimální evidence:** ověřit, že lze srovnání získat z databáze přes podporované rozhraní; případně doplnit chybějící čtecí přehled.
4. **Výslovně vyžádaný pilot:** skutečný průchod, checkpointy, pauzy a pravdivý souhrn pokrytí.
5. **Vyhodnocení a další rozsah:** rozhodnout o prioritách a pouze doložených potřebách dalších funkcí.

Pro změny kódu použít existující `SearchPlanTest`, `ExecutionServiceTest`, `SourcePreferencesTest` a testy importu, podmínek a posouzení podle skutečného zásahu. Nové testy pokryjí hlavně připnutí staré definice, limity, obnovení bez dvojího započtení, duplicitu mezi větvemi, neznámé podmínky, autorizaci a neměnnost rozhodnutí při klasifikaci. Syntetické scénáře zahrnou nový projekt s volbou stacku, povinný nesplněný stack, prototyp neznámého původu a zahraniční remote nabídku s neověřenou dostupností.

Testy databáze běží pouze nad oddělenou testovací databází. Před commitem provést `scripts/privacy-check.py --staged` a významovou kontrolu diffu, před push také `--history`. Pro samotný tento plán není potřeba spouštět aplikaci, migrace ani produkční hledání.
