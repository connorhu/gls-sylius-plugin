# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API még mozog: egy `0.y.z` → `0.(y+1).0`
váltás törő változást is tartalmazhat, a javító kiadások viszont
visszafelé kompatibilisek.

## [Nem kiadott]

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

[Nem kiadott]: https://github.com/connorhu/gls-sylius-plugin/compare/0.1.0...main
[0.1.0]: https://github.com/connorhu/gls-sylius-plugin/releases/tag/0.1.0
