# Spec-driven development

## Table of contents

- [Layers](#layers)
- [User stories](#user-stories)
- [Validation](#validation)

## Layers

1. **Product behaviour** — what beacon-php guarantees: [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md),
   [PRIVACY.md](PRIVACY.md) and the wire format shared with BeaconBundle ([BUNDLE-ADAPTER.md](BUNDLE-ADAPTER.md)).
   PHPUnit (unit, golden, parity, smoke) and PHPStan enforce it in CI.
2. **Spec Kit baseline** — [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and the 100% code inventory
   [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md); the manual is
   [SPEC-KIT.md](SPEC-KIT.md).
3. **Repository REQs** — nowo-tech bundle standards (`BUNDLES_FULL_SPECS_DETAILS.md`) mapped to Makefile targets:
   `release-check` (REQ-MAKE-002), `check-no-cursor-coauthor` (REQ-GIT-001), `test-coverage` (REQ-TEST-003/008).
   Pending: the `nowo-tech/phpstan-frankenphp` rulesets and Igor (REQ-CS-005/008) report the intentional bounded
   process-wide state (ingest-path cache, error handlers, buffers) and need a justified baseline first.

## User stories

- **US-01** — A plain PHP, Laravel or Slim application captures exceptions and messages with one DSN and never breaks
  because Beacon is slow or down.
- **US-02** — A long-running worker resets request state between jobs and keeps memory bounded.
- **US-03** — An operator relies on privacy defaults: no secret in URLs, no end-user IP, scrubbed sensitive keys.
- **US-04** — BeaconBundle delegates to beacon-php and produces byte-identical envelopes.

## Validation

```bash
make release-check
make parity BUNDLE=../BeaconBundle
git ls-files src | wc -l   # must equal the inventory count
```
