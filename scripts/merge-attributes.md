# Azonos nevű WooCommerce attribútumok összevonása

A szkript az élő WordPress-adatbázis attribútumait rendezi. A CSV az ellenőrzéshez szolgált mintaként; futtatáskor nincs rá szükség. Az alapértelmezett futtatás csak előnézet, a módosításhoz `--apply` kell.

## Futtatás

Töltsd fel a frissített bővítményt, majd SSH-n vagy a tárhely termináljában lépj a WordPress könyvtárába. Szükséges: aktív WooCommerce, WP-CLI a `db export` paranccsal és működő adatbázis-exportáló kliens, PHP 8.1+, InnoDB termék- és termékattribútum-táblák.

Előnézet:

```bash
wp schrack-sync merge-attributes
```

Részletes előnézeti napló, új fájlba:

```bash
wp schrack-sync merge-attributes --report=/home/SAJAT_FELHASZNALO/attribute-preview.jsonl
```

Éles futtatás előtt állítsd le a katalógus- és CSV-importokat, várd meg a már futó importmunkásokat, és szüneteltesd a termékek szerkesztését. A szkript zárolja a párhuzamos összevonásokat; a más folyamatból futó importokat nem állítja le.

```bash
wp schrack-sync merge-attributes --apply --backup=/home/SAJAT_FELHASZNALO/attributes-before.sql
```

A példaútvonalakat cseréld a tárhelyeden létező, írható, **webgyökéren kívüli** könyvtárra. Az SQL-fájl és a mellette létrejövő `attributes-before.sql.attributes.jsonl` még ne létezzen. A szkript teljes adatbázismentést készít az első adatmódosítás előtt; sikertelen mentés esetén leáll. A napló rögzíti a megtartott és elhagyott értékeket, az átvezetett értékazonosítókat és a törölt attribútumdefiníciókat.

A parancs alapértelmezetten 200 soros adagokban olvas, ez például `--batch-size=100` kapcsolóval módosítható. Az egész katalógust feldolgozza, nincs automatikus időkorlát; hosszabb futáshoz használj tartós terminálmunkamenetet.

Ha a külön szkriptbelépési pontot szeretnéd használni:

```bash
wp --require=/TELJES/UTVONAL/schrack-woocommerce-sync/scripts/merge-attributes.php schrack-merge-attributes
wp --require=/TELJES/UTVONAL/schrack-woocommerce-sync/scripts/merge-attributes.php schrack-merge-attributes --apply --backup=/home/SAJAT_FELHASZNALO/attributes-before.sql
```

Ehhez a `scripts/merge-attributes.php` és az `includes/class-schrack-attribute-merger.php` közötti könyvtárszerkezetet meg kell tartani. Nem böngészőből és nem sima `php` paranccsal futtatandó. A későbbi importok miatt a frissített mapper és CSV-importer feltöltése is szükséges.

## Mit tart meg?

- A globális attribútumokat a látható nevük alapján csoportosítja. A kis- és nagybetűt, valamint a többlet szóközöket figyelmen kívül hagyja, de az ékezeteket, írásjeleket és mértékegységeket megkülönbözteti. A `Montaj` és `Montare` például külön marad.
- A megmaradó attribútum a bővítmény exportjának sorrendjében az első: először név, majd azonosító szerinti természetes rendezés. Így a `pa_tip`, `pa_tip_2`, `pa_tip_10` sorrend érvényesül. Teljesen azonos azonosítójú adatbázissoroknál a legkisebb attribútum-ID marad meg.
- Termékenként az első **kitöltött** attribútumoszlop teljes értéklistáját tartja meg. A későbbi oszlopok értékeit nem fűzi hozzá. Az üres mező átugorható; a `0`, a `Nespecificat` és más tényleges szövegek kitöltött értékek.
- A megtartott értéket adó attribútum láthatóságát és a csoport legkorábbi termékoldali pozícióját használja. Az érintett globális csoporttal azonos nevű helyi termékattribútumokat is átvezeti. Csak helyi attribútumokból álló, globális duplikátum nélküli csoportokat nem hoz létre vagy alakít át.
- Az összes régi értékelemet átviszi a közös értéklistába, a jelenleg nem használtakat is. Az új értékek leírása és metaadatai átkerülnek; már létező célértéknél a cél leírása és az azonos kulcsú metaadatai maradnak, a hiányzó metaadatkulcsokat pótolja.
- A vázlatokat és a lomtárban lévő termékeket is feldolgozza. Az árakat, készletet, kategóriákat, beszállítói nyersadatokat és más termékmezőket nem szerkeszti.

A forrásdefiníciók törlése előtt teljes újraellenőrzés történik. A termékenkénti módosítás tranzakcióban fut: a termék attribútummetaadata és a hozzá tartozó értékkapcsolatok együtt kerülnek mentésre. Hiba esetén az aktuális termék visszagörgetődik, a korábban elkészült termékek megmaradnak. A javítás ugyanazzal a paranccsal, új mentési/naplófájlnévvel újrafuttatható.

Az attribútumszűrők bővítménybeli nyilvántartása és a WooCommerce attribútum-keresőtáblája is frissül. A keresőtábla frissítése a WooCommerce [LookupDataStore](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/ProductAttributesLookup/LookupDataStore.php) szolgáltatásán keresztül történik. A régi attribútumazonosítókat használó kézzel beállított widgeteket, külső bővítménybeállításokat és mentett szűrőlinkeket külön át kell állítani, ha vannak ilyenek.

## Későbbi importok

Az összevonás elmenti a régi azonosítók és az összevont nevek célattribútumát. A frissített beszállítói mapper ezeket használja, így az ugyanilyen nevű új beszállítói oszlopok is a közös attribútumhoz kerülnek. Egy importált terméksoron az első kitöltött érték marad. A bővítmény saját, külön attribútumoszlopokat olvasó CSV-importere a korábbi exportok régi azonosítóit is átirányítja. Külső importerek saját attribútumlétrehozását ez nem módosítja.

## Megállást okozó esetek

A migráció az írás előtt megáll, ha az érintett attribútum variációs dimenzió, variációs érték vagy alapértelmezett variációs érték része. A variációs dimenziók összevonása külön döntést igényelne az ütköző variációkról. Ugyancsak megáll hibás attribútummetaadatnál, nem termékhez tartozó forráshivatkozásnál, árva értékkapcsolatnál, hierarchikus forrásértéklistánál vagy nem kompatibilis attribútumtípusoknál. Ezek a hibaüzenetben azonosíthatók.

Az export 43 665 egyszerű terméket tartalmazott. 53 duplikált globális névcsoport 2324 terméken szerepelt; 2003 terméknél volt kitöltött, átvezetendő forrásoszlop. 1103 termék–attribútum csoportban volt több kitöltött mező, ebből 5-ben különböztek az értékek. Az élő eredmény eltérhet a CSV pillanatképétől, és az exportból nem látható üres hozzárendeléseket is tartalmazhatja.

## Ellenőrzés és visszaállítás

Futtatás után az előnézeti parancs már nem találhat ismétlődő globális attribútumneveket. Ellenőrizd néhány érintett termék adatlapját és a szűrőket; külső oldalgyorsítótár esetén ürítsd azt.

```bash
wp schrack-sync merge-attributes
```

Szükség esetén a teljes adatbázismentés állítható vissza:

```bash
wp db import /home/SAJAT_FELHASZNALO/attributes-before.sql
wp cache flush
```

Ez a **teljes adatbázist** visszaállítja, tehát a mentés óta létrejött rendeléseket és más módosításokat is felülírja. A visszaállítást a bolt karbantartási eljárásának megfelelően végezd.

Fejlesztői ellenőrzés:

```bash
php tests/attribute-merger.php
php tests/attribute-merger.php /teljes/utvonal/export.csv
```

A tervező a teljes mellékelt CSV-n is ellenőrizhető. A WordPress nélküli teszt nem módosítja az exportot vagy az adatbázist.
