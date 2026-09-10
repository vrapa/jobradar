# Hranice veřejného návrhu a soukromého provozního kontextu

Tento dokument zachycuje výsledek kontroly původních pracovních podkladů proti implementačnímu plánu JobRadaru. Neobsahuje skutečné osobní údaje, pracovní žádosti, ceny předplatných ani přístupové informace a může být součástí veřejného repozitáře.

## Co je úplně pokryté implementačním plánem

- profil, preferované technologie, dostupnost, sazby a neznámé údaje jako verzovaná pravidla;
- ručně spuštěné hledání, priorita zdrojů a důkaz skutečného pokrytí;
- nabídky, firemní leady a veřejné zakázky jako odlišné typy příležitostí;
- bezpečný plný detail, překlad, posouzení, otázky a vratná rozhodnutí;
- oddělení rozhodnutí `reagovat`, přípravy žádosti a výslovně potvrzeného odeslání;
- místní runner, přihlašovací zásah uživatele, omezené API a MCP;
- migrace dosavadní evidence žádostí a pozdější měření nákladů a přínosu zdrojů.

## Soukromý provozní balíček instance

Konkrétní nasazení potřebuje údaje, které veřejný plán smí popsat pouze strukturou:

- skutečný profil kandidáta a ověřená profesní fakta;
- komunikační pravidla podle trhu, jazyka a typu spolupráce;
- schválené odpovědi a textové šablony s verzí a rozsahem použití;
- metadata soukromých CV a dalších příloh, včetně kontrolního součtu;
- účty a stav registrace na zdrojích bez hesel, cookies a MFA údajů;
- předplatná, kredity, ověřené náklady, další obnovu a stav automatického prodlužování;
- dosavadní žádosti, jejich potvrzení, odpovědi a konkrétní udělené souhlasy.

Balíček se bude importovat pouze lokálně z ignorovaného souboru s náhledem změn. Tajemství zůstávají mimo balíček. Veřejné fixtures musejí být syntetické.

## Pravidla použití kontextu

1. Fakt kandidáta a preference nejsou totéž. Každý údaj má původ, čas ověření a případně platnost.
2. Obecná preference komunikace se může lišit podle trhu. Konkrétní nabídka či výslovný pokyn uživatele má přednost a změna se zaznamená.
3. Soukromá šablona sama neopravňuje k odeslání. Je pouze podkladem pro přípravu.
4. Evidence CV neznamená souhlas přiložit je k nové žádosti. Použití přílohy patří do schvalovaného návrhu žádosti.
5. Technická dostupnost zdroje, registrace účtu a komerční přístup jsou samostatné stavy. Neznámá cena ani neznámé prodlužování se neukládají jako nula nebo `false`.
6. Nákup, obnova, zrušení předplatného nebo spotřeba kreditu vždy vyžadují samostatný výslovný souhlas.
7. Aktivní nabídka se neslučuje s pouhým firemním leadem. Přímé oslovení leadu je samostatný schvalovaný workflow.
8. Při migraci staré evidence je konkrétní potvrzení systému důkazem odeslání; samotný stav či text konceptu nestačí.

## Kontrolní body implementace

- Před runnerem a MCP: import soukromého profilu, politik a zdrojových účtů bez tajemství.
- Před workflow žádostí: model příloh, šablon, souhlasů a potvrzení.
- Před migrací: dry-run, kontrolní součty, deduplikace a seznam záměrně vynechaných osobních údajů.
- Před reporty: oddělit cenu, spotřebované kredity, další obnovu, automatické prodlužování a skutečný výsledek zdroje.
- Před veřejným releasem: kontrola celé historie Gitu a syntetických fixtures.

## Kontrola před commitem a publikací

Aktivujte lokální hooky příkazem `git config core.hooksPath .githooks` (vyžadují Python 3). Kontrola `python scripts/privacy-check.py --staged` čte obsah indexu včetně souborů přidaných vynuceně. `--history` kontroluje dostupné větve, vzdálené větve a tagy, také smazané soubory a zprávy commitů. CI používá úplný checkout. Hlášení uvádí pouze cestu a kategorii, nikdy nalezenou hodnotu.

Kontrola zachytává zakázané soukromé soubory a rozpoznané formáty tajemství. Nedokáže určit, zda obecně vypadající text popisuje skutečný život člověka. Každý diff proto vyžaduje významovou kontrolu, včetně dotazů, priorit, dostupnosti, sazeb, reálných ID a účtů. Výjimky musí být skutečně syntetické, nikoli skryté v allowlistu.

Po přepsání historie založte další pracovní kopie novým klonem. Staré větve se nesmějí mergovat ani znovu pushovat. Původní záloha zůstává soukromým archivem; patří mimo webroot a Docker build context s omezeným přístupem. Viditelnost repozitáře se nemění automaticky.

Pre-push kontroluje skutečně odesílané SHA včetně přímého push commitu. Soubor `.privacy-history-root` připíná schválený čistý kořen; historie se starým nebo cizím kořenem se odmítne. Změna této kotvy vyžaduje nový audit historie.
