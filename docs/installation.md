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
    # A termékméretre rászámolt csomagolási ráhagyás (a díjbecsléshez).
    packaging:
        allowance_mm: 0
        allowance_g: 0
```

Konténer-paraméterek: `codeconjure_gls.api.*`, `codeconjure_gls.pickup_address`,
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

## 6. Migrációk

A plugin migrációs névtere (`CodeConjure\SyliusGlsPlugin\Migrations`) a
plugin `prepend()`-jéből regisztrálódik, és a Sylius mag migrációi után fut.
A hostnak nincs vele teendője:

```bash
bin/console doctrine:migrations:migrate
```

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
