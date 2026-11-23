# Roadmap

Status of `nowo-tech/beacon-php` and what is left before `1.0.0`. The plan comes from the Beacon SDK plan of
2026-11-09: a framework-free PHP core, BeaconBundle depending on it, then Laravel and Slim integrations. Status checked
on 2026-11-23.

## Status

Latest release: **v0.3.2** (2026-11-20). `main` has no change after the tag.

| Step | Status |
| --- | --- |
| Framework-free core: DSN, envelope, scope and breadcrumbs, PSR-18 / Symfony HttpClient transport, `Beacon::init()` with error, exception and shutdown handlers, Monolog handler, PSR-15 middleware | Released (v0.1.0, 2026-11-09) |
| Versioned ingest paths (`/api/ingest/v1/…`, Beacon ≥ 1.147) with the legacy fallback | Released (v0.2.0, 2026-11-11) |
| `final` value classes and DSN secret protection (BREAKING, see [UPGRADING](UPGRADING.md)) | Released (v0.3.0, 2026-11-17) |
| Card masking never touches hex trace ids | Released (v0.3.1, 2026-11-17) |
| Redaction fails closed and is aligned with BeaconBundle 1.27 (advisory GHSA-rpqc-gqjp-7jh9, Low, `< 0.3.2`) | Released (v0.3.2, 2026-11-20) |
| BeaconBundle depends on beacon-php | Done on the bundle side: BeaconBundle requires `^0.3.2` and keeps its API through a shim ([BUNDLE-ADAPTER](BUNDLE-ADAPTER.md)) |
| Laravel and Slim | Documented in [USAGE](USAGE.md) (Laravel-style applications; Slim and other PSR-15 stacks); no dedicated integration package |

## Road to 1.0.0 (proposed, pending owner OK)

No 1.0 criterion has been written down for this package yet. Nothing below is a commitment until the owner approves
it. Proposed criterion:

1. **Integration scope decided by the owner**: either the Laravel and Slim support stays as documented usage of the
   core (and 1.0 needs no new package), or dedicated integrations (a Laravel service provider, a Slim package) ship
   first, as the SDK plan of 2026-11-09 listed them.
2. **Public API frozen**: the classes and interfaces under `Nowo\Beacon\` that hosts use (`Beacon`, `Client`,
   `ClientInterface`, `Options`, the capability interfaces, `Monolog\BeaconHandler`, `Http\BeaconMiddleware`) reviewed
   and declared stable, with every break since 0.1 listed in [UPGRADING](UPGRADING.md). After 1.0 a break needs a
   major.
3. **BeaconBundle on the 1.0 line**: BeaconBundle can move its constraint to `^1.0` without a behaviour change, keeping
   the byte-for-byte wire parity tests green.
4. **REQ audit**: the applicable REQ subset re-audited at 100 % on the 1.0 candidate (last re-audit: COMPLIANT on
   v0.3.2, 2026-11-22).
5. **REQ-SEC-004**: a delta security review of the 1.0 candidate against v0.3.2, recorded before the tag.

Open question for the owner: the integration scope (point 1).
