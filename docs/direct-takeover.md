# Přímé převzetí aplikací

Zákazník si ponechává aplikaci a platí za převzetí vývoje a správy. Nejde o nákup projektu. Péče o aplikaci a role protistrany jsou nezávislé doložené klasifikace. Agentury nejsou automaticky vyloučené; přímý zákazník není automaticky potvrzen portálem ani názvem firmy.

## Správa a nasazení

Migrace 013 přidává štítky zdrojů, kroky na verzované definici a stav průchodu; 014 přidává historii protistrany. Není třeba měnit existující nabídky ani připnuté definice. Schéma samo nezadává žádnou kontrolu.

Administrátor ve Zdroje → Upravit zdroj a zadání upraví prioritu/štítky nebo samostatným formulářem vytvoří novou verzi zadání. JSON kroků obsahuje `key`, `name`, `query`, `mode` (keywords/category/skill), `filters` a `limit`. Pořadí v poli je závazné. Doložení podpory vyhledávání se ukládá do auditu; ověřené ovládací prvky se musí při skutečném průchodu znovu zkontrolovat. Nepodporovaný filtr vede k pravdivému neúplnému výsledku, nikoli rozšíření rozsahu.

Konkrétní zdroje a dotazy nastavuje administrátor soukromě ve webu nebo správním importem popsaným v [dokumentaci vykonavatele](executor.md). Změna konfigurace nespouští hledání.

## API v1 a MCP

Import nabídky přijímá nepovinné `counterparty`: `{ "value": "owner|supplier|recruiter" nebo null, "reason": text, "confidence": 0–1, "verifiedAt": ISO 8601 }`. Známá hodnota vyžaduje všechny podklady. Vynechaný/null objekt zachová dosavadní zjištění; objekt s value=null uloží neověřeno do historie. Původem je konkrétní source_version. Čtení přehledu/detailu vrací `counterparty`, detail navíc `counterparty_history` nejnovější první. Rozhodnutí ani kontaktování se nemění.

Vykonávací task vrací na zdroji `search_plan.limit` a `search_plan.steps` s uloženým stavem, počtem a checkpointem. Prázdné kroky znamenají původní protokol. Nové zadání vyžaduje v checkpointu `step_key`, `step_status` (running/complete), `step_displayed_count`; complete navíc `step_completion_reason` (end_of_results/step_limit/source_limit). Ostatní bezpečná pole checkpointu zůstávají.

Import přes vykonavatele nese navíc `searchStep`, ověřený proti právě běžícímu kroku. Tento údaj není součástí běžného ručního importu. Nové MCP operace nejsou nutné; používají se checkpoint/import/task. Server hlídá pořadí, monotónní počty, limity a kontext, včetně alternativního API dokončení zdroje.

Limit se počítá podle zpracovaných pozic výsledků. Duplicita v dalším dotazu spotřebuje pozici, ale nezaloží další nabídku. Samotné zobrazení celé stránky neznamená prozkoumání všech jejích položek. Po vyčerpání společného limitu jsou další kroky skipped_limit: byly vynechané, nikoli prohledané. Complete zdroje vyžaduje terminální kroky a součet jejich počtů odpovídající displayed_count. Neznámé počty jiných metrik zůstávají null.

Detail požadavku na webu i GET /search-requests/{id} (`search_plans`) zobrazují připnutou verzi a skutečný průchod. Starší a rozepsané požadavky nemění konfiguraci; obnova zachovává dokončené kroky i importy.

## Ověření

Integrační testy ověřují pořadí kroků, společné limity, deduplikaci, připnutí definice a obnovu přerušeného běhu. Reálná výtěžnost a konkrétní výsledky patří do soukromé evidence instance.
