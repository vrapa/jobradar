# Cílový provozní workflow

Tento dokument popisuje volitelnou integraci; konkrétní projekty, automatizace a souhlasy jsou soukromé nastavení instance.

## Jediný zdroj pravdy

Databáze JobRadaru je jediným zdrojem pravdy pro nabídky, zdroje, skutečné pokrytí kontrol, verze textu, podmínky, posouzení, rozhodnutí, navazující kroky a stav žádosti. Todoist ani Markdown nesmí vytvářet paralelní seznam nabídek.

Soukromý projekt uchovává dlouhodobý soukromý profil, CV, komunikační pravidla, šablony, vytvořené dokumenty, podklady k pohovorům a dočasně historické Markdownové účtenky již odeslaných žádostí. Tyto soubory nejsou operativní fronta. Po ověřené migraci zůstávají historickým archivem; novější stav se čte z JobRadaru.

Todoist obsahuje pouze konkrétní proveditelný krok nebo termín. Každý pracovní Todoist úkol musí mít odpovídající `action_item` v JobRadaru a po vytvoření se k němu uloží provider, externí ID a URL. Samotné `Reagovat` úkol nevytváří.

## Tok kontroly zdrojů

1. Uživatel v JobRadaru výslovně vybere zdroje a založí požadavek. Import konfigurace, běžící počítač ani plánované probuzení samy hledání nezahájí.
2. Nakonfigurovaný vykonavatel pravidelně ověří frontu a převezme nejvýše jeden čekající požadavek. Prázdná fronta končí bez otevření portálů; běh pak pouze ověří navazující kroky pro Todoist.
3. Vykonavatel pracuje v přihlášeném Chrome podle připnutého zadání. Nabídky, překlady, podmínky, posouzení, checkpointy a skutečné počty ukládá průběžně přes vykonávací API.
4. Přihlášení, MFA, CAPTCHA, kredity, platby a právní souhlasy se neobcházejí. Běh uloží čekající nebo částečný stav a vyžádá zásah uživatele.
5. Uživatel nabídku v JobRadaru označí jako `Reagovat`, `Nezajímavé` nebo ji nechá nerozhodnutou. Rozhodnutí nic neodesílá.

## Tok navazujících úkolů a Todoistu

1. Konkrétní krok vzniká v JobRadaru jako `action_item`, například ověřit sazbu, zkontrolovat připravenou žádost, odpovědět do data, provést follow-up nebo přihlásit se na portál.
2. Synchronizace načte pouze otevřené položky bez externí vazby, zkontroluje duplicitu podle ID `action_item`, vytvoří Todoist úkol v nakonfigurovaném projektu a uloží jeho ID a URL zpět do JobRadaru.
3. Dokončení Todoist úkolu smí uzavřít pouze odpovídající `action_item`. Nesmí změnit rozhodnutí nabídky, stav žádosti ani vytvořit potvrzení o odeslání.
4. Volitelná e-mailová integrace propojí ověřenou odpověď s existující nabídkou. Samotný e-mail bez úplného detailu není novou nabídkou.

## Příprava a odeslání žádosti

Implementovaný postup, API a zápis ze soukromého projektu: [Reakce a čekání na odpověď](application-workflow.md). Na detailu je formulář Stav reakce a historie; čekající nabídky mají samostatný přehled. Při zaznamenání odeslání je termín kontroly odpovědi povinný (formulář nabízí upravitelných 7 dní) a hotové přípravné úkoly se vybírají jednotlivě. Synchronizace jejich dokončení do Todoistu je samostatně potvrzovaná; nesmí změnit stav žádosti.

1. Text žádosti, upravené CV, cenová nabídka nebo podklady k pohovoru vznikají jako soubory v soukromém projektu; JobRadar nese jejich bezpečná metadata a stav procesu, ne nutně binární obsah.
2. Před odesláním se uživateli ukáže konečný obsah, cílová nabídka, použitá identita, přílohy, sazba a případné souhlasy. Bez výslovného schválení se nic neodešle.
3. Stav `submitted` smí vzniknout až po konkrétním potvrzení portálu nebo po ověřeném záznamu odeslaného e-mailu. Kliknutí na tlačítko ani dokončení Todoist úkolu nestačí.
4. Historické podklady uchovávat soukromě; aktuální operativní stav se čte z JobRadaru.

## Automatizace

Vykonavatel pravidelně zpracuje nejvýše jeden výslovný požadavek. Volitelná synchronizace přenáší pouze schválené navazující kroky. Aktivaci a konkrétní nastavení uchovává provozovatel soukromě.

## Přechod a akceptace

1. Nasadit migraci a ověřit vytvoření, dokončení a zrušení interního navazujícího úkolu bez změny rozhodnutí či stavu žádosti.
2. Vydat oddělený odvolatelný synchronizační token pro stejného vlastníka jako dashboard příkazem `executor/install-actions.ps1 -OwnerId <ověřené ID>`. Token se chrání přes Windows DPAPI a registruje samostatný MCP `jobradar-actions`; token ani Todoist přístupové údaje se neukládají do repozitáře.
3. Nejprve ověřit read-only transport příkazem `python executor/smoke_actions_stdio.py`. Potom jedním syntetickým `action_item` ověřit vytvoření Todoist úkolu, uložení externí vazby a idempotenci opakování.
4. Teprve poté aktivovat Todoist synchronizaci. Vyloučit duplicitní hledání mimo výslovnou frontu.
5. Migrovat historické `submitted` soubory nejprve v náhledu, s hashem a bez osobních kontaktů či přihlašovacích údajů. Původní soubory nemazat.

## Přílohy

[Workflow příloh](attachment-workflow.md) definuje krok `verify_attachment` (Prověřit přílohu), řešený v soukromém projektu. Synchronizace jej řadí do nakonfigurovaného projektu a sekce. Dokončení průchodu zdroje se eviduje odděleně od čekajícího ověření přílohy; odškrtnutí úkolu v Todoistu neověřuje podklady.
