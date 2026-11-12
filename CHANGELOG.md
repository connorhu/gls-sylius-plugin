# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API még mozog: egy `0.y.z` → `0.(y+1).0`
váltás törő változást is tartalmazhat, a javító kiadások viszont
visszafelé kompatibilisek.

## [Nem kiadott]

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

[Nem kiadott]: https://github.com/connorhu/gls-sylius-plugin/compare/0.4.0...main
[0.4.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.4.0
[0.3.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.3.0
[0.2.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.2.0
[0.1.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.1.0
