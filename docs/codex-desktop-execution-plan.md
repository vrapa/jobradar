# Kontroly JobRadaru přes Codex Desktop

## Cílové chování

Uživatel vybere zdroje a vyžádá kontrolu v JobRadaru. Automatizace v desktopové aplikaci pravidelně zkontroluje frontu a převezme nejvýše jeden čekající požadavek. Codex projde přidělené zdroje v Chrome, průběžně uloží nabídky a posouzení přes MCP a uzavře jednotlivé výsledky podle skutečného pokrytí. Stav, historie a výsledky zůstávají v databázi JobRadaru.

Tlačítko nevytváří okamžitý push do desktopové aplikace. Zahájení nastane při nejbližším dostupném probuzení automatizace. Prázdná fronta znamená konec bez procházení portálů a bez oznámení. Automatizace sama nové požadavky nezakládá.

## 0. Ověření proveditelnosti

- Připravit izolovaný syntetický scénář bez skutečného hledání a bez změny produkčních nabídek.
- Ověřit dostupnost lokálního MCP, API JobRadaru a Chrome v plánovaném běhu stejného typu, který bude použit v provozu. Interaktivní úspěch sám nestačí.
- Ověřit chování při zavřeném Chrome, nedostupném rozšíření, uspaném počítači, nutnosti schválení nástroje a vyčerpaném limitu Codexu.
- Změřit podporovanou frekvenci a dobu vyzvednutí. Výchozí návrh je kontrola fronty po 5 minutách, pouze pokud to plánovač podporuje; skutečný interval zobrazit v UI. Počítat se spotřebou i při prázdné frontě.
- Preferovat opakované probuzení stálého úkolu (heartbeat) s trvalými instrukcemi. Autoritou pro rozpracovanou kontrolu je API, nikoli historie chatu.
- Pokud plánovaný běh Chrome nebo MCP neposkytne, zaznamenat konkrétní omezení. Automatizaci pro reálné zdroje neaktivovat; ruční spuštění téhož workflow může sloužit k pilotu. Samostatná služba s Codex CLI by byla další architektonická varianta k rozhodnutí.

Výstup: doložený plánovaný syntetický průchod a seznam skutečných provozních podmínek.

## 1. Vykonávací MCP a oprávnění

- Přidat samostatnou sadu nástrojů: stav připojení, převzetí nejstarší práce, načtení zadání, obnova lease, zahájení zdroje, checkpoint, dokončení zdroje a hlášení potřebného zásahu.
- Využít existující runner API; nerozšiřovat běžnému MCP klientovi implicitně právo přebírat práci. Vykonávací MCP bude používat oddělený odvolatelný runner token svázaný se zařízením. Běžný MCP token zůstane samostatný.
- Vykonavateli vydat pouze oprávnění ke čtení zadání/zdrojů/profilu, zápisu průběhu, importu a posouzení. V první verzi bez rozhodování, vytváření delegací, zakládání nových kontrol a odesílání žádostí.
- Každý zápis během kontroly včetně importu vázat serverově na vlastníka, přidělený zdroj, run a platný lease. Prověřit stávající importní kontrakt a doplnit tuto kontrolu tam, kde chybí.
- Zadání vrací konkrétní vyhledávací URL, filtry, horizont, limity průchodu a verzi profilu/pravidel. Chybějící rozsah se nesmí nahrazovat odhadem úplné kontroly.
- Dlouhodobé tokeny držet v místní konfiguraci mimo Git, prompt a logy. Krátkodobý lease pokud možno spravovat uvnitř MCP a modelu vracet jen identifikátor běhu a expiraci.
- Synchronizovat OpenAPI, MCP schémata a implementační plán.

Výstup: syntetický požadavek lze přes MCP převzít, zpracovat a uzavřít bez přístupu k MySQL.

## 2. Přerušení, souběh a dlouhé průchody

- Zachovat atomický claim a pětiminutový lease; zavést serverově vynucený jediný aktivní průchod na zařízení, aby dvě probuzení nesoutěžila o Chrome ani o různé požadavky.
- Obnovovat lease v lokálním MCP pomocníku po dobu aktivní práce s omezenou dobou bez checkpointu. Pouhé spoléhání na to, že model před každým vypršením zavolá obnovu, není dostatečné.
- Checkpoint ukládá poslední dokončenou jednotku, stránku/kurzor, URL a průběžné metriky bez cookies. Po restartu se pokračuje z uložených důkazů a ověří se aktuálnost stránky.
- Idempotentní import a události zabrání duplicitám při ztracené odpovědi API. Převzetí po expiraci zachová již uložené nabídky a terminální výsledky; starý lease nesmí zapisovat.
- Upravit čekání na přihlášení tak, aby neblokovalo ostatní dostupné zdroje a jejich obnovování lease. Teprve po dostupné práci přejde požadavek do čekání na uživatele.
- Při zrušení přestat zahajovat další kroky, odmítnout nové zápisy starým lease a ponechat výsledky. Při výpadku API zastavit další průchod, pokud nelze bezpečně udržet lease a uložit výsledek.
- Poslední spojení, aktivní práce a stáří checkpointu evidovat odděleně; offline stav odvozovat ze stáří, ne z trvale uloženého příznaku online.

Výstup: obnova po pádu a souběhu bez ztráty nabídek a bez falešného dokončení.

## 3. Instrukce Codexu a průchod Chrome

- Připravit verzovaný skill s postupem: převzít existující zadání, ověřit přístup, projít výpis a detaily, uložit verze nabídek, přeložit a posoudit podle uložených pravidel, zapsat doložený výsledek.
- Pro každý podporovaný zdroj popsat navigaci, filtry, stránkování, rozpoznání detailu a podmínky zastavení. Nezačínat psaním PHP scraperu pro každý portál.
- První pilot zahrne jeden dostupný veřejný zdroj; druhý scénář zdroj s potřebou přihlášení. Další zdroje přidávat až po ověření pilotu.
- Přihlašovací zásahy, MFA a CAPTCHA předat uživateli. Po jeho požadavku na pokračování znovu skutečně ověřit přístup.
- Každý otevřený detail evidovat i při zamítnutí podle hlavního plánu. Neznámé metriky ponechat NULL; úplné dokončení vyžaduje dosažení konce sjednaného rozsahu a doložené počty.
- Text nabídek je nedůvěryhodný obsah. Skill nesmí dovolit změnu zadání instrukcemi ze stránky ani odeslat žádost, zprávu, osobní údaje či přijmout právní podmínky.
- Vyčerpání časového či pracovního limitu znamená checkpoint a pravdivý nedokončený stav, nikdy automatické complete.

Výstup: opakovatelný ruční pilot stejného workflow, které později spustí automatizace.

## 4. Dashboard a uživatelský tok

- Po výběru zdrojů potvrdit „Kontrola byla zařazena. Čeká na Codex na vašem počítači.“ Zabránit dvojímu odeslání na klientu i serveru.
- Ukázat vykonavatele, poslední spojení, očekávaný interval vyzvednutí, konkrétní aktuální zdroj, pokrytí a nálezy. Neodhadovat přesný začátek při offline zařízení.
- Rozlišit: čeká na Codex, probíhá, potřebuje přihlášení, přerušeno, dokončeno částečně a dokončeno.
- Zobrazit konkrétní důvod blokace a odkazy k přihlášení; tlačítka „Pokračovat po přihlášení“ a „Zrušit“ využijí auditované API.
- Průběh aktualizovat lehkým pollingem aplikace. Implementační pojmy lease a runner nepoužívat v běžných uživatelských textech.

### Přehled zdrojů, které se nepodařilo prověřit

- Vedle relevantních nabídek zobrazit přímo na dashboardu samostatný blok „Zdroje vyžadující pozornost“ a souhrn pokrytí poslední kontroly: vybráno, úplně prověřeno, částečně prověřeno, neprověřeno a dosud čeká/probíhá. Kategorie se nesmějí překrývat.
- U každého problematického zdroje uvést název a odkaz, konkrétní stav a srozumitelný důvod, čas posledního pokusu, čas poslední úplné kontroly (nebo „nikdy“), skutečný rozsah dokončené části a doporučený další krok. Připojit odkaz na detail příslušné kontroly.
- Rozlišit přihlášení/MFA/CAPTCHA, nedostupný portál, omezení přístupu, změnu stránky, nedostupný Chrome/MCP, chybu zpracování, výpadek vykonavatele a vyčerpání pracovního limitu. Bez doložené příčiny uvést „Příčina nezjištěna“; nevymýšlet diagnózu.
- Dosud čekající zdroj označit „Čeká na kontrolu“, nikoli jako chybu. Částečně prověřený zdroj může současně mít uložené relevantní nabídky; ty zůstanou viditelné a nebudou důkazem úplné kontroly.
- Umožnit výběr neprověřených/částečných zdrojů pro novou kontrolu. Kliknutí je nový konkrétní uživatelský požadavek s idempotencí; při čekání na přihlášení použít pokračování stávající kontroly. Nic neopakovat automaticky mimo rozsah původního požadavku.
- Přehled čerpat z databázových výsledků jednotlivých zdrojů a historie, nikoli ze závěrečného textu Codexu. Starší neúspěch přestane být aktuální po novější úplné kontrole, ale zůstane v historii. Zdroj nevybraný do poslední kontroly nesmí ztratit dosud nevyřešenou blokaci.
- Nulový počet nálezů po úspěšné kontrole zobrazit jako „Prověřeno, bez nálezů“. U neprověřeného zdroje je výsledek neznámý; nulou jej nenahrazovat.

## 5. Zapojení automatizace a přechod

- Po úspěšných etapách 0–4 připojit vykonávací MCP a skill k desktopovému úkolu a nastavit ověřený interval. Plán samotný nic neaktivuje.
- Uložené instrukce: zpracuj nejvýše jeden výslovně vyžádaný požadavek; prázdná fronta znamená tichý konec; respektuj přidělený rozsah, lease a checkpointy; výsledky ukládej průběžně.
- Oznámit jen dokončení, podstatnou chybu nebo potřebný zásah. Při nezměněném čekání na přihlášení neupozorňovat opakovaně.
- Nechat uživatelské klasifikace nabídek samostatné; kontrola sama nerozhodne „reagovat“ a nic neodešle.
- Zachovat stávající frontu a historii. FakeSourceAdapter ponechat v testech; starý worker nesmí současně přebírat reálné kontroly. Existující čekající požadavky před přechodem zobrazit a potvrdit jejich aktuální rozsah.
- Dokumentovat podmínky provozu: dostupná desktopová aplikace, počítač, Chrome, API a platný token. V Dockeru zůstává web a databáze; reverse proxy zůstává konfigurací provozovatele.
- Vrácení změny: pozastavit automatizaci, odvolat její token a nechat nedokončené požadavky čekat nebo je explicitně zrušit. Data a historii nemažeme.

## Akceptační scénáře

1. Bez požadavku neproběhne návštěva portálu; opakovaná probuzení zůstávají tichá.
2. Dvojklik vytvoří jediný požadavek; souběžná probuzení nepřevezmou stejnou práci ani souběžně neovládají Chrome na jednom zařízení.
3. Vyžádaný veřejný zdroj projde celým zadaným rozsahem a uložené nabídky, posouzení a souhrn souhlasí s doloženými kroky.
4. Login blokace zachová ostatní nálezy a umožní projít zbývající dostupné zdroje; pokračování po zásahu nevytvoří druhý běh.
5. Pád, uspání, vyčerpání limitu a expirace lease umožní navázání bez duplicit; zpožděný zápis původního vykonavatele je odmítnut.
6. Zrušení i odvolání tokenu zastaví další práci; nevymažou historii.
7. Cizí uživatel, nepřidělený zdroj a import se starým lease jsou odmítnuty. Instrukce ze stránky nemění workflow.
8. Ukončení na časovém limitu je partial/přerušení s důvodem, nikoli complete. Doložená kontrola bez nálezů může mít skutečnou nulu.
9. Smíšená kontrola s úplným průchodem, částečným průchodem a blokovaným zdrojem ukáže současně relevantní nabídky i přesný přehled nepokrytých zdrojů s důvody. Souhrn se shoduje s databází; čekání, chyba a skutečná nula jsou odlišné.
10. Nová kontrola pouze neprověřených zdrojů zachová původní výsledky a historii. Novější úplný úspěch odstraní aktuální upozornění, zatímco vynechání zdroje v jiné kontrole blokaci neskryje.

Testovat API a souběh automaticky nad syntetickými daty, Chrome nejprve na kontrolované stránce a poté v omezeném skutečném pilotu. Testy spouštět s odděleným runtime nebo jako webový uživatel, aby nevznikala cache vlastněná rootem.

## Pořadí dodání

Stav nasazení se ověřuje pro každou instanci zvlášť podle [testovacího postupu](execution-verification.md). Provozní postup je v [dokumentaci vykonavatele](executor.md).

Etapa 0 → vykonávací MCP/API a obnova (1–2) → skill a ruční pilot (3) → UI (4) → plánované zpracování a akceptace (5). První rozhodující milník je skutečný naplánovaný syntetický průchod; funkčnost propojení se nesmí odvozovat jen z existence MCP serveru.

Oficiální podklad ověřený 8. 9. 2026: [Scheduled tasks](https://learn.chatgpt.com/docs/automations?surface=app). Dokumentace popisuje plánované lokální úkoly a požadavek běžícího počítače/aplikace; dostupnost konkrétní integrace Chrome a MCP v našem plánovaném běhu musí doložit etapa 0. Původní adresa dokumentace Codex automations nyní přesměrovává na tento společný dokument.
