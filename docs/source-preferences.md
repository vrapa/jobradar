# Zdroje, Google a péče o aplikace

Na stránce Zdroje jsou předvolena A. Přidat B/C zachovává předchozí výběr. Jen A, Vše a Nic výběr nahrazují. Administrátor může upravit prioritu; při souběžné změně je nutné stránku obnovit.

Ruční Google: otevřete uložený dotaz, najděte původní detail a použijte Uložit nalezenou nabídku. Vložte původní URL/text, nikoli snippet nebo AI shrnutí. Dotazy lze upravit pod stejným názvem jako novou verzi nebo deaktivovat. Původní verze zůstávají dohledatelné.

## API v1

Import nabídky (JSON, MCP i vykonavatel) přijímá nepovinné `projectCare`: objekt s `value` (boolean/null), `reason`, `confidence` (0–1), `verifiedAt` (ISO 8601 s pásmem). Známá hodnota vyžaduje všechny údaje. Vynechání/null objektu zachová dosavadní klasifikaci; explicitní `{ "value": null }` uloží neověřeno. Historie odkazuje na skutečnou verzi textu. Čtecí přehled vrací `project_care`, detail `project_care_history` a `discoveries`.

Ruční import může poslat `discoveryDefinitionId`: ID existující definice ručního vyhledávání. Ukládá se vazba, čas a zdroj objevení; cílová URL zůstává identitou nabídky. Vykonavatel může použít jen kontext svého přiděleného zdroje; ruční Google mu není přidělován. `/sources` nadále vrací pouze kontrolovatelné zdroje.

## Příprava přístupu

Web předvolí přípravu v Chrome – Práce. Po odeslání vznikne jeden požadavek; připravené stránky a stav najdete v jeho detailu. Přihlaste se v Chrome a potvrďte „Přihlášení mám připravené – pokračovat v kontrole“. Pokračování proběhne až při dalším převzetí vykonavatelem, nikoli samo kliknutím na odkaz Google.

API vytváření požadavku přijímá nepovinné `prepare_access`, MCP `request_search` přijímá `prepareAccess`. Pro zpětnou kompatibilitu mají klienti výchozí hodnotu false; web přípravu předvoluje.

Vykonávací API task vrací `prepare_access`, `browser_session_name` a `access_preparations`. Operace `prepare_access` má běžný source_id/idempotency_key a payload `{ "status": "available|login_required|blocked|error" }`. Příprava blokuje běžné zápisové operace do výslovného potvrzení vlastníkem. Poslední výsledek přepne požadavek na waiting_for_login a uvolní lease. Ztracenou odpověď lze opakovat se stejným klíčem a původním lease.

## Vývoj

Integrační testy spouštějte s APP_ENV=test, DB_NAME=jobradar_test nebo jobradar_test_<suffix> a samostatným MySQL schématem. Bootstrap odmítá běžnou databázi. Migrace nevytvářejí soukromé dotazy. Ruční zdroj i definice se nastavují importem; testy používají syntetické záznamy. Aktuální nabídky se zpětně nepřeklasifikují.
