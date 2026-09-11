# Vykonavatel Codex Desktop

## Rozdělení odpovědnosti

Web a MySQL uchovávají zadání, nabídky, hodnocení a skutečné výsledky zdrojů. Codex prochází Chrome podle uloženého zadání a skillu `executor/skills/jobradar-check/SKILL.md`. Samostatný STDIO MCP `executor/server.py` (Python 3.11+, standardní knihovna) používá pouze verzované HTTP API, nikoli MySQL. Nevolá OpenAI API ani model samostatně.

Běžné čtecí JobRadar MCP a vykonávací MCP mají různé odvolatelné tokeny. Vykonavatel dostává pouze `search:execute`, klient typu `runner`, svázaný se zařízením a vlastníkem. Nemá oprávnění vytvářet další kontroly, rozhodovat za uživatele ani odesílat žádosti.

## Nasazení

1. Aplikovat migrace `008_execution.sql` a `009_executor_opt_in.sql` příkazem `php bin/jobradar database:migrate` jako webový uživatel.
2. Pro Windows se spuštěným Dockerem, Pythonem a Codex CLI použít `executor/install.ps1 -Container <název webového kontejneru> -OwnerId <ověřené ID uživatele dashboardu>`. Správní skript ověřuje aktivního administrátora. Bez explicitního ID převezme vlastníka existujícího `JOBRADAR_MCP_TOKEN`, případně jediného administrátora; tento fallback nepoužívat bez kontroly shody s uživatelem dashboardu, zejména v prostředí se syntetickými účty. Vytvoří oddělený token na 30 dní. Token není argumentem příkazu ani výstupem instalace; uloží se přes Windows DPAPI do `%LOCALAPPDATA%/JobRadar/executor-token.xml`. Původní čtecí MCP se nemění. `-ReplaceRevokedCredential` použít pouze po výslovném odvolání původního tokenu; jeho chráněný soubor se ponechá jako časově označená záloha. Pro správní import lze shodného vlastníka určit prostředím `JOBRADAR_EXECUTOR_OWNER_ID`.
3. Instalátor zaregistruje `jobradar-executor` pomocí `codex mcp add` přímo na jediný explicitní Python a `executor/stdio.py --credential-file <DPAPI soubor>`. Nativní proces vlastní stdin/stdout po celou dobu MCP relace, token načte přes Windows CryptUnprotectData pouze do paměti. Konfigurace obsahuje cestu k chráněnému souboru, nikoli token. `launch.ps1` zůstává diagnostický pomocník, není provozním STDIO transportem. Pokud MCP není vidět v novém probuzení, ověřit startovací log a skutečný registrovaný příkaz; samotný restart nebo změna konfigurace nedokazuje funkčnost.
4. `executor/launch.ps1 -Probe` ověří pouze spojení; `python executor/smoke_stdio.py` načte skutečný registrovaný příkaz z uživatelské konfigurace a postupně ověří inicializaci STDIO, seznam nástrojů a `status` s otevřeným vstupem. Žádný z nich nepřevezme kontrolu. Jednorázové odeslání všech zpráv s uzavřením stdin není platným testem dlouhotrvajícího transportu. Výchozí adresa je `http://jobradar.localhost/api/v1`, změna přes `JOBRADAR_EXECUTOR_API_URL`. Pro vzdálené API je povinné HTTPS, přesměrování se odmítá. Lokální HTTP `*.localhost` se připojuje přes loopback se zachovanou hlavičkou Host.
5. Před produkčním spouštěním uložit skutečné zdroje, verzované `source_search_definitions` s dotazem, filtry a limitem, platný kandidátský profil a pravidla. Při prvním převzetí se preferují aktivní pravidla; nejsou-li dostupná, připne se poslední návrh a povolí se pouze kvalitativní rozklad a doporučení bez číselných skóre a vah. Návrh neaktivuje bodovou křivku ani rozhodování. Obsah patří do soukromé DB, nikoli repozitáře. Správní import `php bin/executor-admin.php import-config var/private/<soubor>.json` používá ignorovaný JSON s `assessment` (kontrakt AssessmentConfigurationMapper) a `sources`; vytváří verze transakčně a sám nespouští hledání.
6. Teprve po skutečném plánovaném syntetickém probuzení, načtení Chrome a vykonávacího MCP a omezeném veřejném/login pilotu zapnout produkční probouzení. Počítač, desktopová aplikace, Chrome a API musí být dostupné. Přesný čas vyzvednutí není zaručen. Nezaměňovat ověřovací automatizaci s produkčním zpracováním fronty.

Oficiální podklad: [MCP konfigurace](https://learn.chatgpt.com/docs/extend/mcp?surface=cli) a [plánované úkoly](https://learn.chatgpt.com/docs/automations?surface=app). Konkrétní funkčnost hostitele vždy ověřit prakticky.

## Protokol v1

Vícekrokové definice vyžadují migrace `013_search_plans.sql` a `014_counterparty.sql`. `task.sources[].search_plan` vrací připnuté kroky a společný limit. Vykonavatel dodržuje [protokol kroků](../executor/skills/jobradar-check/search-steps.md): aktuální `step_key`, kumulativní `step_displayed_count`, stav a důvod dokončení; import uvádí `searchStep`. Staré definice pokračují původním protokolem. Nezávislé nepovinné `counterparty` používá doloženou roli protistrany, nikoli odhad podle portálu. Konfiguraci a zavedení popisuje [placené převzetí aplikací](direct-takeover.md).

`POST /api/v1/runner/execution`, Bearer token, JSON objekt s `operation`. MCP nabízí jediný nástroj `execute` s operacemi:

- `status`: spojení a poslední kontakt zařízení, nikdy nepřebírá práci.
- `claim`: nejvýše jeden výslovný požadavek vlastníka. Prázdná fronta vrací null. Souběh blokuje databázový zámek zařízení; existující nevypršelý lease vylučuje další přidělení na stejném zařízení.
- `task`: přidělené zdroje, připnuté definice včetně `review_guidance`, profil/pravidla, checkpointy, importované ID a JSON schémata importu/posouzení. `prepare_access` vyžaduje nejdříve přípravu přihlášení podle [pokynů](../executor/skills/jobradar-check/access-preparation.md), nikoli průchod nabídek.
- `prepare_access`: přidělený `source_id`, stabilní `idempotency_key` a payload pouze se `status` (`available`, `login_required`, `blocked`, `error`). Poslední výsledek uvolní lease a čeká na výslovné potvrzení vlastníka na webu. Připravené záložky Chrome zůstanou otevřené pro uživatele. Hesla, MFA ani obsah správce hesel se nečtou ani neukládají. Do potvrzení server odmítá běžné operace průchodu.
- `start`, `checkpoint`, `import`, `assessment`, `finish`: povinný přidělený `source_id`, `idempotency_key` 16–200 znaků, `payload`. V HTTP navíc `run_id` a `lease_token`; MCP je doplňuje interně. Parametry importu a posouzení určuje `task.schemas`.
- `renew`: pouze interní obnova lease přes API. MCP ji provádí každých 60 sekund, nejvýše 10 minut bez aktivity nebo hodinu na jeden běh. Výpadek či zamítnutí obnovy zastavuje další nové zápisy. Codex má mezi jednotkami průchodu ověřovat stav a neovládat Chrome po ztrátě přidělení.

Výsledek `finish` obsahuje `status`, doložené počty `pages_traversed`, `displayed_count`, `detail_opened_count`, `stored_count`, `updated_count`, `duplicate_count`, `rejected_count`, a případně `incomplete_reason`, `error_code`. `complete` vyžaduje všechny počty, připnutou konfiguraci a checkpoint. Nula je pouze skutečné pozorování. `partial`, `waiting_for_login` a `error` vyžadují důvod; chyba navíc bezpečný kód.

`checkpoint` obsahuje stručnou `completed_unit`, případně bezpečnou veřejnou `url`, `page`, `pages_traversed`, `detail_opened_count`. Neobsahuje cookies, hesla, auth URL ani browser storage. Kontrola zdroje s loginem neblokuje zbývající plánované zdroje; teprve po jejich průchodu se běh uvolní a čeká na výslovné pokračování uživatele.

Události mají idempotenci v DB a uložení výsledku ve stejné transakci. Při ztrátě odpovědi opakovat přesně původní klíč a obsah. Dokončenou událost lze jako neměnnou účtenku přečíst i po uvolnění lease s původním tokenem a stejným vlastníkem/zařízením; nový zápis se starým lease je odmítnut. Po převzetí jiným zařízením původní zařízení ztrácí i přístup k běhu.

## Přechod a obnova

Staré požadavky mají `executor_eligible = 0`, zachovávají se v historii a nový vykonavatel je nepřebírá. Nové ruční žádosti jsou způsobilé; původní výběr lze výslovně zadat znovu. Starý `runner:work-once` je vypnutý a starý claim endpoint vrací HTTP 410. Fake adaptér zůstává pouze pro testy.

Po expiraci se pokračuje ve stejném běhu, dokončené zdroje zůstávají, rozpracovaný zdroj se vrací do plánovaného stavu s uloženým checkpointem. Importy se deduplikují podle normalizované URL a zdrojového obsahu. Zrušení odvolá lease a ponechá historii.

Dashboard zobrazuje poslední požadavek i poslední výsledek každého neúplného zdroje napříč požadavky, důvody, rozsah, počty, pokus a poslední úplný úspěch. Nová kontrola jiného zdroje starou blokaci neskryje. Průběh se obnovuje po 15 sekundách bez nahrazování rozpracovaných formulářů rozhodnutí.

Rollback provozu: pozastavit automatizaci a odvolat konkrétní executor token správním příkazem `bin/executor-admin.php revoke <token-id>` se stejným vlastníkem. Volitelně odebrat MCP přes Codex. Neodstraňovat historii ani databázi a neobnovovat demonstrační worker. DPAPI soubor lze po odvolání odstranit; jeho kopie sama neobnoví zrušené oprávnění.

## Testy

Všechny aplikační CLI příkazy (včetně migrací, záloh a provisioning skriptů) v Dockeru spouštět přes `docker compose exec --user www-data web ...` nebo `docker exec --user www-data <kontejner> ...`. Bootstrap odmítá root před vytvořením runtime souborů. Instalační PowerShell skripty tento účet již nastavují. Nepoužívat `chmod 777`; již vzniklé chybné vlastnictví opravit pouze na ověřeném runtime adresáři.

`python -m unittest discover -s executor -v`; PHP integrační/regresní testy v Dockeru jako webový uživatel; PHPStan s odděleným runtime; `npm run build`; `python executor/smoke_stdio.py` až po instalaci. Poslední konkrétní výsledky a neověřené kroky jsou v [ověření integrace](execution-verification.md).
