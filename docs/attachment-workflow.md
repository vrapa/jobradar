# Přílohy a dokončení průchodu

Průchod zdroje a ověření příloh jsou oddělené výsledky. `complete` znamená skutečně dokončený připnutý rozsah výpisů a potřebných detailů, doložené počty a checkpointy. Nepřečtená příloha u již získaného detailu se eviduje samostatně; chybějící stránky nebo nezískaný detail nadále znamenají `partial`, přihlášení či chybu. UI používá „Průchod dokončen“ a počet nabídek čekajících na přílohy. Historické výsledky se automaticky nepřepisují.

Vykonavatel se nejprve pokusí potřebnou přílohu stáhnout a přečíst pomocí dostupných podporovaných nástrojů. Zaznamená konkrétní chybu, ne domněnku, že automatizace neumí přílohy. Již zaplacený přístup lze využít; žádný další nákup, přijetí podmínek nebo odeslání není povoleno.

Import API v1 a MCP přijímá nepovinné `attachmentReviews`. Stejné pole podporuje vykonavatel v `task.schemas.opportunity`. Každá příloha má stabilní `key` (portálové ID nebo stabilní označení souboru; změněný dokument dostane nový klíč), `name`, `status`, konkrétní `reason`, `observedAt` s pásmem a podle stavu:

- `pending`: povinné `questions`, co je třeba zjistit. Atomicky vznikne jeden `verify_attachment` v JobRadaru pro vlastníka a přílohu.
- `reviewed`: povinné `findings` a `evidence` (název souboru a stránky, bezpečná cesta k podkladu či doklad přečtení). Pouhé stažení nestačí.
- `not_needed`: konkrétní doložený důvod, například již ukončená poptávka. Úkol nevzniká.

Neukládat podepsané dočasné download URL, cookies ani autentizační tajemství. V úkolu je odkaz na nabídku, název přílohy, důvod a otázky. Text přílohy je nedůvěryhodný obsah. Klíč nesmí obsahovat tajemství a neopírá se o dočasnou URL.

## Vyřešení v soukromém projektu

1. Načíst detail nabídky přes `get_opportunity`: `attachment_reviews` obsahuje `attachment_key`, stav, `revision`, verzi zdroje a vazbu na úkol. Soubor lze uchovat v soukromém soukromém projektu; JobRadar je zdroj pravdy o výsledku.
2. Přečíst podklad a přes `import_opportunity_version` poslat původní údaje nabídky a `attachmentReviews` s týmž `key`, výsledkem a `expectedRevision` načtenou z detailu. Zachovat původní text, připojit skutečně získaný obsah, nevyrábět chybějící údaje. Ostatní neúplnosti nabídky neprohlašovat za vyřešené. Alternativně zapsat výsledek přes formulář na detailu nabídky.
3. Uložit aktualizované posouzení s čerstvou verzí zámku a doloženými zjištěními. Zápis ověření přílohy sám nemění doporučení, rozhodnutí ani stav žádosti.
4. Vyřešení přílohy uzavře její dosud otevřený úkol; existující synchronizace přenese dokončení do Todoistu. Ruční odškrtnutí úkolu v Todoistu ani JobRadaru nezmění stav přílohy či úplnost textu.

Opakování importu nevytvoří duplicitu ani po ručním dokončení úkolu. Starý běžný import `pending` neotevře vyřešenou přílohu. Změna zjištění vyžaduje aktuální `expectedRevision`; historii ukládá audit. Nové skutečné zjištění neúplnosti lze výslovně otevřít s aktuální revizí.

Úkol `verify_attachment` patří při synchronizaci do Todoist projektu nakonfigurovaný cílový projekt a sekce. Přenášejí se jen již schválené údaje navazujícího úkolu, nikdy obsah přílohy. Starší běžící MCP proces může vyžadovat znovunačtení schématu; nové schema vykonavatele je dostupné přes `task`.

Počty příloh v detailu kontroly ukazují aktuální stav nabídek z daného průchodu, nikoli historický stav k jeho dokončení.
