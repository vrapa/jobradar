# JobRadar

[![CI](https://github.com/vrapa/jobradar/actions/workflows/ci.yml/badge.svg)](https://github.com/vrapa/jobradar/actions/workflows/ci.yml)

JobRadar je open-source, privacy-first self-hosted systém pro vyhledávání, ukládání, překlad, hodnocení a řízení pracovních příležitostí. Evidence konkrétního uživatele zůstává v jeho soukromé instanci.

JobRadar není auto-apply bot. Rozhodnutí `Reagovat` pouze zařadí nabídku do interní fronty; odeslání žádosti je vždy samostatný auditovaný krok s výslovným souhlasem.

Kompletní zadání a pořadí implementace jsou v [docs/implementation-plan.md](docs/implementation-plan.md).

## Rychlé spuštění

Repozitář obsahuje samostatný Compose stack bez závislosti na konkrétní cestě, doméně nebo reverzní proxy. Výchozí hesla jsou pouze pro první lokální spuštění; před jiným nasazením je nastavte v `.env`.

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec web php bin/jobradar database:migrate
docker compose exec web php bin/jobradar user:create-admin uzivatel@example.test "Administrátor"
```

Aplikace bude dostupná pouze z lokálního počítače na [http://localhost:8080](http://localhost:8080).

## Provoz a konfigurace

Stack lze spustit z libovolného umístění, kde jsou dostupné Docker a Docker Compose. V `.env` nastavte zejména veřejnou adresu `APP_URL`, bezpečná databázová hesla a případně `APP_PORT`. `APP_BIND_ADDRESS` zůstává ve výchozím stavu `127.0.0.1`; změňte ji jen při vědomém zpřístupnění přes vlastní síťové zabezpečení nebo reverzní proxy.

Správa databáze a administrátorského účtu probíhá uvnitř aplikačního kontejneru:

```powershell
docker compose exec web php bin/jobradar database:migrate
docker compose exec web php bin/jobradar user:create-admin uzivatel@example.test "Administrátor"
```

Heslo administrátora se zadá skrytě interaktivně a není argumentem příkazu.

API klienta a časově omezený token vytvoří administrátor například takto:

```powershell
docker compose exec web php bin/jobradar api:create-client admin@example.test "Local MCP" mcp --scope=sources:read --scope=opportunities:read --days=30
```

Plná hodnota tokenu se vypíše právě při vytvoření; JobRadar ukládá jen její hash a bezpečný prefix. Každé oprávnění uvádějte samostatnou volbou `--scope`.

Kontroly nově zpracovává Codex přes Chrome a oddělené vykonávací MCP se scope `search:execute`. Demonstrační `runner:work-once` je vypnutý a jeho claim API vrací 410. Nasazení, zabezpečení, obnovu a testy popisuje [vykonavatel](docs/executor.md).

```powershell
.\executor\install.ps1 -Container JobRadar-web
.\executor\launch.ps1 -Probe
python executor/smoke_stdio.py
```

Jméno kontejneru přizpůsobte nasazení. Instalace na Windows chrání token přes DPAPI a nepřebírá žádnou kontrolu. Produkční automatizaci zapněte až po skutečném plánovaném ověření a pilotu s uloženými zdroji, profilem a pravidly. Stav ověření je v [protokolu integrace](docs/execution-verification.md).

MCP server používá oficiální PHP SDK a standardní STDIO transport. Spouští se `php bin/jobradar-mcp`, URL čte z `JOBRADAR_MCP_API_URL` a vlastní token z `JOBRADAR_MCP_TOKEN`. Token vystavený pro MCP může mít jen scopes potřebné pro konkrétní nástroje; například read-only klient nepotřebuje zápisová oprávnění. Server nabízí nástroje pro zdroje, kontroly, nabídky, frontu k reakci, posouzení a vratná jednotlivá i atomická dávková rozhodnutí pod časově omezenou delegací. Vytváření delegací má samostatný scope `decisions:delegate`, který běžnému MCP tokenu nevydávejte; použije se jen tehdy, když má klient delegaci založit po vašem výslovném pokynu. Žádný nástroj neodesílá žádost.

Příklad konfigurace MCP hostitele (doplňte absolutní cestu a token mimo Git):

```json
{
  "mcpServers": {
    "jobradar": {
      "command": "php",
      "args": ["C:/absolute/path/jobradar/bin/jobradar-mcp"],
      "env": {
        "JOBRADAR_MCP_API_URL": "http://localhost:8080/api/v1",
        "JOBRADAR_MCP_TOKEN": "<samostatný MCP token>"
      }
    }
  }
}
```

Kontrola konfigurace a stavu:

```powershell
docker compose config
docker compose ps
docker compose logs -f web db
```

Zastavení prostředí bez smazání databáze:

```powershell
docker compose down
```

Smazání lokálních dat je záměrně oddělený destruktivní krok a není součástí běžného vypnutí.

## Záloha a obnova

SQL záloha se vytváří konzistentním snapshotem a nikdy nepřepíše existující soubor. Obsahuje soukromá data instance, proto ji necommitujte, uchovávejte ji mimo veřejný repozitář a pro přenos nebo dlouhodobé uložení ji zašifrujte:

```powershell
docker compose exec web php bin/jobradar database:backup var/backups/jobradar-2026-09-07.sql
docker compose cp web:/var/www/html/var/backups/jobradar-2026-09-07.sql C:\private-backups\jobradar-2026-09-07.sql
```

`database:restore` z bezpečnostních důvodů odmítne databázi obsahující jedinou tabulku. Nejprve proto vytvořte samostatnou prázdnou MySQL databázi, nastavte pro ni `DB_NAME` a oprávnění aplikačního uživatele a teprve poté spusťte:

```powershell
docker compose exec -e DB_NAME=jobradar_restore web php bin/jobradar database:restore var/backups/jobradar-2026-09-07.sql
```

Heslo se nepředává argumentem procesu ani nevypisuje; klienti `mysqldump` a `mysql` je dostanou pouze přes prostředí podřízeného procesu. Po obnově ověřte přihlášení, počty nabídek a poslední běh dříve, než novou databázi použijete jako produkční.

## Kontroly kvality

```powershell
docker compose exec web composer check
npm run build
```

Stejné kontroly spouští GitHub Actions nad MySQL 8.4; CI navíc ověřuje reprodukovatelné assety a sestavení samostatného Docker image.

## Stav projektu

Implementovaná je Nette aplikace pro nabídky, posouzení, vratná rozhodnutí, auditované kontroly zdrojů, verzované API, čtecí i vykonávací MCP a ověřitelná záloha/obnova. Přestavba přidává checkpointy, izolaci přidělené práce a dashboard neúplných zdrojů; produkční konfigurace a průchod portály vyžadují dokončení integračního pilotu.

Navazující konkrétní kroky se ukládají jako interní `action_items`; volitelný Todoist je pouze zrcadlí a vazbu drží v `external_tasks`. Samotné rozhodnutí `Reagovat` úkol nevytváří. Aktuální rozdělení odpovědnosti a stav automatizací popisuje [cílové provozní workflow](docs/target-workflow.md).

## Licence

JobRadar je dostupný pod licencí [MIT](LICENSE). Zdrojový kód a demonstrační data mohou být veřejné; reálné nabídky, profily kandidátů, tokeny, logy a databáze do repozitáře nepatří.

Bezpečnostní hlášení a pravidla pro přispívání popisují [SECURITY.md](SECURITY.md) a [CONTRIBUTING.md](CONTRIBUTING.md).
