# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API még mozog: egy `0.y.z` → `0.(y+1).0`
váltás törő változást is tartalmazhat, a javító kiadások viszont
visszafelé kompatibilisek.

## [0.7.0] – 2026-11-14

A címkés lap kezdőpozíciója (bolt-issue #263).

### Hozzáadva

- Kezdőpozíció-választó (1–4, a MyGLS `PrintPosition`-je) minden
  címke-gomb mellett: az admin feladó-panelen, a csomaglista soraiban és a
  tömeges címkenyomtatásnál. A negyedek neve az elrendezést követi (2×2 vagy
  4×1); hőnyomtató-formátumnál a választó nem jelenik meg.
- Twig: `gls_label_format()`, `gls_label_print_positions()`.

### Változott

- A címke-gomb a feladó-panelen és a csomaglistán link helyett GET-űrlap
  (új lapon nyíló PDF), hogy a kezdőpozíciót is elküldje.

## [0.6.0] – 2026-11-14

A díjszámítás a GLS kalkulátorának képletével (bolt-issue #260).

### Hozzáadva

- `gls` shipping calculator (`Shipping\GlsShippingCalculator`, `GlsRateTable`):
  alapár + kézbesítési felár + feladási felár + értesítések méretosztályonként,
  csomagonként; GLS GO-egységár belföldön; országonkénti alapár külföldre;
  a sávos utánvét-díj a szállítási díjban, ha a fizetési mód utánvét. Minden ár
  a mód konfigurációjából jön, csatornánként, fillérben.
- Admin-űrlap a díjtáblához (`Form\Type\ChannelBasedGlsCalculatorConfigurationType`),
  forintban szerkeszthető; az országonkénti alapár soronként egy ország.
- `Packaging\ParcelEstimator`, `SizeLimits`, `SizeClass`: a csomagméret a tételek
  egymásra rakva, plusz a ráhagyás; a több csomagra bontás first-fit decreasing;
  a méretosztályok a GLS `parcelLimits.hu` értékei.
- `Shipping\GlsShippingMethodEligibilityChecker`: ha egy tétel a mód legnagyobb
  osztályába sem fér (vagy 40 kg fölött van), a GLS-mód nem választható.
- Twig: `gls_cod_fee(order)`, `gls_parcel_estimate(shipment)`; az admin
  feladó-űrlapja a becsült súllyal és darabszámmal töltődik elő.

## [0.5.0] – 2026-11-12

A nyomkövetés-szinkron (bolt-issue #258).

### Hozzáadva

- `gls:tracking:sync` parancs és `Tracking\GlsTrackingSyncService`: a nyitott
  csomagok státusza 100-as kötegekben (`GetParcelListStatuses`, magyar
  nyelvű leírással), a legfrissebb státusz szerint léptetve; a nyers kód
  eltárolva; a kinyomtatott, de át nem adott csomagot a GLS szkennelése
  átadottá teszi (a Sylius szállítmány `ship`); API nélküli módban
  lekérdezés nélkül, sikeresen lép ki.
- `Tracking\GlsStatusMap` / `TrackingOutcome` — a G függelék kódjai: kézbesítve
  (5, 54, 55, 58, 59, 92), visszaküldve (23, 40), sikertelen kézbesítés
  (11–20, 33–36, 68), úton / ponton vár, még nincs a GLS-nél (51, 52); a többi
  ismeretlen, átmenet nélkül.
- `Tracking\GlsTrackingSyncSummary` — a záró napló és a konzol ugyanabból a
  számlálókészletből (`found = synced + skipped + failed`).
- `GlsParcelRepository::findForTracking()`.

## [0.4.0] – 2026-11-12

Csomagfeladás, címke és sztornó az adminból, API-val és API nélkül
(bolt-issue #257).

### Hozzáadva

- `Entity\GlsParcel` + `GlsParcelStatus` + migráció (`gls_parcel` tábla),
  `Repository\GlsParcelRepository`, `codeconjure_gls.parcel` Sylius-resource.
- A `gls_parcel` workflow: a „regisztrált" (`ParcelId`) és a „címke kész"
  (`ParcelNumber`) külön hely; kézi ág (`manual_hand_over`); a nyomkövetés
  (#258) átmenetei.
- `Address\StreetSplitter` — magyar címsor a MyGLS `Street` / `HouseNumber` /
  `HouseNumberInfo` hármasára.
- `Parcel\GlsParcelPayloadFactory` — pillanatkép a csomagon, abból a MyGLS
  `Parcel` (utánvét forintban, PSD a pont azonosítójával és teljes
  kapcsolattartóval, átvételi pontnál a pont címe, értesítések a konfig szerint).
- `Parcel\GlsParcelService` — feladás (`PrepareLabels`), címke
  (`GetPrintedLabels`, a nyomkövetési szám a Sylius szállítmányra is), sztornó
  (`DeleteLabels`), átadás és kézi feladás (a Sylius szállítmány `ship`).
- Admin: feladás-panel a közös szállító-csomag `ship` hookján (API-módban
  feladás / címke / átadás / sztornó, API nélkül a GLS felületére átviendő
  adatok és a nyomkövetési szám rögzítése), csomaglista (Értékesítés → GLS
  csomagok) tömeges címkenyomtatással és sztornóval; CSRF-védett POST-ok.
- Konfiguráció: `label.format` (alapból `A4_2x2`), `notifications.email` /
  `notifications.sms` (FDS / FSS, alapból ki).
- Twig: `gls_api_enabled()`, `gls_parcel_for_shipment()`,
  `gls_parcel_preview()`, `gls_tracking_url()`.

### Változott

- Új futásidejű követelmény: `symfony/workflow ^7.4` — a `gls_parcel`
  állapotgép backed enumban tárolja a helyét, amit a `MethodMarkingStore` a
  7.4 óta kezel (régebbin: „could not be converted to string").

## [0.3.0] – 2026-11-12

A csomagpont- és csomagautomata-választó a pénztárban, és a választott pont
megjelenítése (bolt-issue #255).

### Hozzáadva

- A pénztár választója a GLS térkép-widgetjével (`<gls-dpm-dialog>`),
  fajtánként külön, a fajtára szűrt blokkban, a közös szállító-csomag
  widget-hookján; a `gls-pickup-point` Stimulus controller a
  `carrier:pickup-point-selected` eseménnyel adja át a pontot.
- `Pickup\DeliveryPointCatalog` — a GLS nyilvános pontlistája, gyorsítótárazva;
  `Pickup\DeliveryPoint`.
- `Pickup\PickupPointValidator` és a pénztár `ShipmentType`-kiterjesztése:
  ismeretlen vagy másik fajtájú pontnál a `pickupPointId` mező hibát kap.
- `Cod\PickupPointCashCheck` — utánvétnél csak kártyával lehet-e fizetni a
  ponton; figyelmeztetés a választóban és a 3. lépés utánvét-kártyáján.
- A választott pont a rendelés-összegzőben
  (`…addresses.shipping.pickup_point` hook).
- `GlsDeliveryKind::mapFilterType()`, `fromShipment()`, `of()`.
- Twig: `gls_pickup_point()`, `gls_cod_card_only()`,
  `gls_is_cash_on_delivery()`, `gls_map_widget_url()`, `gls_map_country()`.

### Változott

- A `gls_handles_delivery()` és a `gls_delivery_kind()` a szállítmányt is
  elfogadja (a fajta pillanatképéből).

## [0.2.0] – 2026-11-12

A GLS szállítási fajták, az utánvét-szabály és a feladás előtti
jogosultság-ellenőrzés (bolt-issue #254).

### Hozzáadva

- `Model\GlsDeliveryKind` — a három GLS fajta (`gls_home_delivery`,
  `gls_parcel_shop`, `gls_parcel_locker`), a GLS `DeliveryPointType`-jával.
- `Model\GlsShipmentInterface` és `Model\GlsShippingMethodInterface` — a
  host entitásainak szerződése, a közös szállító-csomag interfészeire építve.
- `Cod\CodPolicy` és a `codeconjure_gls.cod` konfiguráció: utánvét mindhárom
  belföldi módon, 500 000 Ft-os felső határ, külföldre alapból nincs.
- `Payment\CodPaymentMethodsResolver` — a Sylius fizetésimód-resolver
  dekorátora: a pénztárban kiveszi az utánvétet, ha a GLS nem engedi.
- `Eligibility\EligibilityChecker` — feladható-e a szállítmány (csomagpont,
  címzett neve, telefonja, e-mailje a PSD-hez; házszám házhoz szállításnál;
  fizetés vagy utánvét-szabály).
- Twig: `gls_handles_delivery()`, `gls_delivery_kind()`.

## [0.1.0] – 2026-11-12

A plugin váza, üzleti funkció nélkül.

### Hozzáadva

- `CodeConjureSyliusGlsPlugin` bundle és a `codeconjure_gls` konfiguráció:
  `api` (env-alapértékekkel: `GLS_USERNAME`, `GLS_PASSWORD`,
  `GLS_CLIENT_NUMBER`, `GLS_ENVIRONMENT`; ország, webshop engine),
  `pickup_address`, `packaging` (`allowance_mm`, `allowance_g`, alapból 0).
- `Api\ApiAccess` és `Api\ApiMode` — az API nélküli és az API-mód futásidejű
  eldöntése; részleges kitöltésre `missingSettings()` és
  `GlsApiNotConfiguredException`, nem csendes kézi mód.
- A `gls` Monolog-csatorna és a plugin migrációs névtere a `prepend()`-ből.
- `codeconjure.gls.*` fordítási névtér (hu, en).
- `docs/installation.md` a host-lépésekkel.
- Kapuk: PHPUnit, PHPStan level 9, ECS — GitHub Actionsön (PHP 8.4/8.5 ×
  Sylius 2.1/2.2), plusz `--prefer-lowest` tesztfutás.

[0.7.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.7.0
[0.6.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.6.0
[0.5.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.5.0
[0.4.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.4.0
[0.3.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.3.0
[0.2.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.2.0
[0.1.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.1.0
