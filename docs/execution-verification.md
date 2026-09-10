# Ověření vykonavatele

Výsledky skutečných kontrol, přihlašování, nabídky a identifikátory provozní instance se ukládají pouze do databáze a soukromého provozního protokolu. Tento dokument je obecný testovací postup, nikoli potvrzení nasazení.

1. V izolovaném prostředí vytvořit syntetické zdroje, profil a vyžádanou kontrolu.
2. Ověřit přidělení, checkpointy, idempotentní import a dokončení podle skutečného rozsahu.
3. Ověřit přihlašovací blokaci a výslovné obnovení stejného běhu bez změny připnutých verzí.
4. Ověřit výpadek vykonavatele a spojení, expiraci lease, zrušení a konflikty.
5. Zkontrolovat, že čekající příloha vytváří navazující krok a dokončení úkolu samo neověřuje přílohu.
6. Samostatně ověřit dostupnost hostitelských nástrojů při plánovaném probuzení. Prázdná fronta nesmí otevřít portály.

PHP testy musí používat APP_ENV=test a vyhrazenou databázi jobradar_test nebo jobradar_test_<suffix>. Veřejný výsledek testování uvádí pouze technický rozsah a syntetické scénáře.
