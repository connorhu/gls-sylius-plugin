# codeconjure/gls-sylius-plugin

[![CI](https://github.com/connorhu/gls-sylius-plugin/actions/workflows/ci.yaml/badge.svg)](https://github.com/connorhu/gls-sylius-plugin/actions/workflows/ci.yaml)

GLS szállítási integráció Syliushoz, a
[`codeconjure/gls`](https://github.com/connorhu/gls-lib) MyGLS-kliensre és a
[`codeconjure/sylius-carrier-bundle`](https://github.com/connorhu/sylius-carrier-bundle)
közös szállító-alapjára építve.

**Két mód:**

- **API nélkül** (alapértelmezés): szállítási módok, csomagpont-választó,
  díjszámítás; a csomagot kézzel kell feladni a GLS felületén.
- **MyGLS API-val** (szerződés kell): csomagfeladás, címke, sztornó,
  nyomkövetés az adminból.

A mód futásidőben dől el az env-változókból (`GLS_USERNAME`, `GLS_PASSWORD`,
`GLS_CLIENT_NUMBER`, `GLS_ENVIRONMENT`) — l. [docs/installation.md](docs/installation.md).

## Állapot

- `0.1`: a váz — konfiguráció, az API-mód eldöntése (`Api\ApiAccess`),
  Monolog-csatorna, migrációs és fordítási névtér, CI.
- `0.2`: a három GLS szállítási fajta, a host-szerződés interfészei, az
  utánvét-szabály (pénztári szűrő is) és a feladás előtti ellenőrzés.

A pénztári csomagpont-választó, a díj, a feladás és a nyomkövetés a
következő kiadásokban jön.

## Fejlesztés

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

A plugin nem szállít teszt-kernelt: a konténer-teszt egy csupasz
`ContainerBuilder`-en fordítja és dumpolja a plugin konténerét, mindkét
módban.

## Verziózás

[SemVer](https://semver.org/lang/hu/); a kiadások a [CHANGELOG.md](CHANGELOG.md)-ben.
