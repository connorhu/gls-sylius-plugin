# Telepítés

A plugin két módban fut:

- **API nélküli mód** (alapértelmezés): nincs megadva MyGLS hitelesítő
  adat. A szállítási módok, a csomagpont-választó és a díjszámítás működik,
  a csomagot viszont kézzel kell feladni a GLS felületén (pl. ecsomag +
  GLS GO).
- **API-mód:** MyGLS-szerződéssel. Csomagfeladás, címke, sztornó és
  nyomkövetés az adminból.

A módot a plugin futásidőben dönti el az env-változókból, ezért a
hozzáférés megérkezésekor elég az env-et kitölteni.

## 1. Composer

```bash
composer require codeconjure/gls-sylius-plugin
```

## 2. Bundle-ök

`config/bundles.php` — a közös szállító-csomag a GLS plugin ELŐTT:

```php
CodeConjure\SyliusCarrierBundle\CodeConjureSyliusCarrierBundle::class => ['all' => true],
CodeConjure\SyliusGlsPlugin\CodeConjureSyliusGlsPlugin::class => ['all' => true],
```

A `codeconjure/sylius-carrier-bundle` saját host-lépései (pénztár- és admin
hookok) a csomag `docs/installation.md`-jében vannak. Ha a boltban már fut
egy szállító-plugin (pl. FoxPost), ezek már megvannak.

## 3. Env-változók

```dotenv
# Mind üres → API nélküli mód. Ha BÁRMELYIK ki van töltve, mind a négy kötelező.
GLS_USERNAME=
GLS_PASSWORD=
GLS_CLIENT_NUMBER=
# test | production — API-módban kötelező, nincs alapértéke
GLS_ENVIRONMENT=
```

Ha az adatok csak részben vannak kitöltve, az konfigurációs **hiba**,
nem kézi mód. Az `ApiAccess::missingSettings()` megnevezi a hiányzó
változókat, az API-t hívó műveletek pedig `GlsApiNotConfiguredException`-t
dobnak.

## 4. Konfiguráció (opcionális)

`config/packages/codeconjure_gls.yaml`:

```yaml
codeconjure_gls:
    api:
        # Alapból env-ből (%env(default::GLS_*)%); itt felülírható.
        country: HU             # a MyGLS-ország: HU, CZ, HR, RO, SI, SK, RS
        webshop_engine: Sylius
    # A feladó címe (MyGLS PickupAddress) — csak API-módban kell.
    pickup_address:
        name: ~
        street: ~
        house_number: ~         # CSAK szám; a betűjel a house_number_info-ba
        house_number_info: ~
        city: ~
        zip_code: ~
        country_iso_code: HU
        contact_name: ~
        contact_phone: ~
        contact_email: ~
    # Az utánvét szabályai GLS-szállítmánynál. A plugin a pénztár fizetési
    # módjai közül kiveszi az utánvétet, ha a GLS nem engedi (a határ fölött,
    # vagy külföldre), és a feladás előtti ellenőrzés is ezt használja.
    cod:
        payment_method_codes: [cash_on_delivery]   # a bolt utánvétes módjainak kódja
        max_amount: 50000000    # fillérben: 500 000 Ft (a GLS maxCodAmount-ja)
        international: false    # külföldre csak, ha a GLS-szerződés adja
    # A termékméretre rászámolt csomagolási ráhagyás (a díjbecsléshez).
    packaging:
        allowance_mm: 0
        allowance_g: 0
```

Konténer-paraméterek: `codeconjure_gls.api.*`, `codeconjure_gls.pickup_address`, `codeconjure_gls.cod.*`,
`codeconjure_gls.packaging.allowance_mm`, `codeconjure_gls.packaging.allowance_g`.

## 5. Naplózás

A plugin a `gls` Monolog-csatornát maga deklarálja. A **prod handler a
host dolga**: enélkül a csatorna rekordjai élesben elvesznek.
`config/packages/monolog.yaml`:

```yaml
when@prod:
    monolog:
        handlers:
            main:
                # ...
                channels: ["!gls"]
            gls:
                type: stream
                path: "%kernel.logs_dir%/gls.log"
                level: info
                channels: [gls]
```

## 6. A host entitásai

A bolt `Shipment`-je a `CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface`-t,
a `ShippingMethod`-ja a `GlsShippingMethodInterface`-t implementálja. A GLS-fajtát
a `getDeliveryKindSlug()` adja, a `GlsDeliveryKind` három értékének egyikével:

| Slug | Fajta | Csomagpont? |
|---|---|---|
| `gls_home_delivery` | házhoz szállítás | nem |
| `gls_parcel_shop` | GLS csomagpont | igen |
| `gls_parcel_locker` | GLS csomagautomata | igen |

A `getDeliveryKindSlug()` ugyanaz a metódus, amit a FoxPost plugin kér, tehát egy
közös host-enum mindkét szállító fajtáit hordozhatja. Twigben: `gls_handles_delivery(method)`,
`gls_delivery_kind(method)`.

## 7. Migrációk

A plugin migrációs névtere (`CodeConjure\SyliusGlsPlugin\Migrations`) a
plugin `prepend()`-jéből regisztrálódik, és a Sylius mag migrációi után fut.
A hostnak nincs vele teendője:

```bash
bin/console doctrine:migrations:migrate
```

## 8. Csomagpont-választó a pénztárban

A GLS csomagpont- és csomagautomata-választója a GLS saját térkép-widgetje
(`<gls-dpm-dialog>`, betöltő: `https://map.gls-hungary.com/widget/gls-dpm.js`),
fajtánként külön blokkban, a fajtára szűrve. A közös szállító-csomag
`carrier-shipment` hatókörében ül; a mezőt (`pickupPointId`) és a láthatóságot
a közös csomag adja.

### Stimulus controller

A `composer require` után a Flex felveszi a `gls-pickup-point` controllert az
`assets/controllers.json`-ba (a csomag `symfony-ux` kulcsszava miatt), és a
`package.json`-ba a
`"@codeconjure/gls-sylius-plugin": "file:vendor/codeconjure/gls-sylius-plugin/assets"`
függőséget. Frissítésnél (`composer update`) ez NEM mindig történik meg —
ellenőrizd, és ha hiányzik, vedd fel kézzel:

```json
"@codeconjure/gls-sylius-plugin": {
    "gls-pickup-point": { "enabled": true, "fetch": "eager" }
}
```

Utána: `npm install && npm run build`.

### Hookok

A plugin `prepend()`-je mindet host-lépés nélkül beveszi (mind új kulcs); a
host a sablont vagy a prioritást a saját `sylius_twig_hooks` configjában
felülírhatja, vagy `enabled: false`-szal kikapcsolhatja.

| Hook | Hookable | Mit rajzol |
|---|---|---|
| `sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields` | `gls` | a választó (2. lépés) |
| `sylius_shop.shared.order.show.summary.addresses.shipping.pickup_point` | `gls` | a választott pont a rendelés-összegzőben |
| `sylius_shop.shared.form.select_payment.payment.choice.details` | `gls_cod_notice` | utánvét-figyelmeztetés a 3. lépés utánvét-kártyáján |

Az összegző hookját (`…addresses.shipping.pickup_point`) a **host sablonja**
hívja a szállítási blokkban, amikor a szállítmány átvételi pontos — ezt a
FoxPost plugin is használja, egy boltban egyszer kell megírni.

### A pontlista és az ellenőrzés

A választott pont nevét, címét, fajtáját és azt, hogy fogad-e készpénzt, a
plugin a GLS nyilvános pontlistájából olvassa
(`https://map.gls-hungary.com/data/deliveryPoints/{ország}.json`, az ország a
`codeconjure_gls.api.country`), a host `cache.app` gyorsítótárában egy napig.
A pénztár a beküldött pontot ehhez méri: ismeretlen vagy másik fajtájú pontnál
a `pickupPointId` mező hibát kap. Ha a lista nem érhető el, a pont átmegy (a
GLS kiesése nem állítja le a pénztárt), és a hiba a `gls` csatornára naplózódik.

### Utánvét átvételi ponton

A pont a 2. lépésben dől el, a fizetési mód a 3.-ban — a térkép ezért nem
szűrhet fizetési mód szerint. A GLS automatái nem fogadnak készpénzt, a
csomagpontok egy része sem: az automata-blokk mindig, a csomagpont-blokk és a
3. lépés utánvét-kártyája a pont szerint kiírja, hogy ott csak kártyával lehet
fizetni.

### Content Security Policy

Ha a boltnak van CSP-je: a widget a `https://map.gls-hungary.com` originről
tölt szkriptet (`script-src`) és adatot (`connect-src`); a térkép-csempék
forrását a böngészőben kell mérni.

### Twig-függvények

`gls_pickup_point(id)` (a pont, vagy null), `gls_cod_card_only(order)`,
`gls_is_cash_on_delivery(method)`, `gls_map_widget_url()`, `gls_map_country()`;
a `gls_handles_delivery()` és a `gls_delivery_kind()` a módot és a
szállítmányt (a fajta pillanatképét) is elfogadja.

## 9. Csomagfeladás az adminból

### Route-ok

A plugin admin route-jai EGY importból jönnek. `config/routes/codeconjure_gls.yaml`:

```yaml
codeconjure_gls:
    resource: '@CodeConjureSyliusGlsPlugin/src/Resources/config/routes.yaml'
```

Az előtag `/%sylius_admin.path_name%` — a műveletek a Sylius admin tűzfalán belül
maradnak. A csomaglista: **Értékesítés → GLS csomagok**
(`codeconjure_gls_admin_parcel_index`).

### Migráció

A `gls_parcel` tábla a plugin migrációja (`Version20261112000001`, PostgreSQL):

```bash
bin/console doctrine:migrations:migrate
```

Ha a bolt a `sylius_labs_doctrine_migrations_extra.migrations` kulcsát maga is
beállítja, az **felülírja** a plugin kijelentését (a kulcs cserélődik, nem
fésülődik): ilyenkor a `'CodeConjure\SyliusGlsPlugin\Migrations': ~` sort fel kell venni.

### A rendelés-oldal

A feladás-panel a közös szállító-csomag admin-hookján ül
(`codeconjure_carrier.admin.order.shipment.ship` → `gls`), tehát csak a csomag
`ship` slot-sora kell (a csomag telepítési leírása) — ha a boltban már fut
szállító-plugin, ez megvan. A panel csak GLS-szállítmánynál és `ship`-képes
(`ready`) szállítmánynál jelenik meg.

- **API-módban:** feladás (súly, darabszám) → `PrepareLabels`; a címke-PDF
  (`GetPrintedLabels`) adja a nyomkövetési számot, ami a Sylius szállítmány
  `tracking` mezőjébe is bekerül; „Átadva a GLS-nek" → a szállítmány `ship`
  (a Sylius kiküldi a szállítási e-mailt a nyomkövetési számmal); közben
  sztornó (`DeleteLabels`). A csomaglistán tömeges címkenyomtatás (max. 99) és
  tömeges sztornó.
- **API nélkül:** a GLS felületére átviendő adatok másolható formában
  (hivatkozás, címzett, telefon, e-mail, cím vagy átvételi pont, utánvét), és a
  nyomkövetési szám kézi rögzítése, amitől a szállítmány `ship`.

A műveletek POST-ok, `gls_parcel` CSRF-tokennel.

### A címke és az értesítések

```yaml
codeconjure_gls:
    label:
        format: A4_2x2      # A4_2x2 (4 db A6 egy A4-en), A4_4x1, vagy hőnyomtató
    notifications:
        email: false        # FDS — a GLS e-mailben értesíti a címzettet
        sms: false          # FSS — SMS is (csak e-maillel együtt)
```

A címke nyomtatásakor a `print_position` (1–4) adja meg, hányadik negyeden
kezdődjön egy részben elhasznált A4-es lap.

### Az utca és a házszám

A bolt egyetlen `street` mezőt tárol, a MyGLS `Street` / `HouseNumber` (csak
szám) / `HouseNumberInfo` hármast vár: a plugin `Address\StreetSplitter`-e bontja
(„Petőfi tér 5. 2. em. 4." → `Petőfi tér` / `5` / `2. em. 4.`). Ami nem bontható
(nincs házszám, helyrajzi szám), a teljes sorral, házszám nélkül megy.

## API-t hívó kód a pluginban és a hostban

A MyGLS `Client` szándékosan **nem service**. API nélküli módban a
példányosítása dobna, és vele minden rá függő service is. Az API-t hívó kód
ezért az `ApiAccess`-t kéri, és csak a hívás pillanatában szól a
`client()`-nek:

```php
if (!$apiAccess->isEnabled()) {
    // kézi mód vagy hiányos beállítás — nincs API-hívás
}

$apiAccess->client()->prepareLabels([...]);
```
