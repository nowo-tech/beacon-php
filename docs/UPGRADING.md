# Upgrading

## To 0.1.0

First release; nothing to upgrade from.

### Coming from BeaconBundle

Symfony applications keep using `nowo-tech/beacon-bundle`; a future bundle minor will depend on this package
without behaviour change (see [BUNDLE-ADAPTER.md](BUNDLE-ADAPTER.md)). Using beacon-php directly in a non-Symfony
PHP application with the same Beacon project:

| BeaconBundle | beacon-php |
| --- | --- |
| `nowo_beacon.dsn` | `dsn` |
| `nowo_beacon.environment` / `release` / `server_name` | same keys |
| `nowo_beacon.send.*` | `send.*` (same keys and defaults) |
| `nowo_beacon.timeout` / `verify_peer` | same keys |
| `nowo_beacon.transport.mode: async` | `async: true` (Symfony HttpClient) or `queue: true` |
| `nowo_beacon.before_send` (service id) | `before_send` (callable) |
| `BeaconClientInterface` | `Nowo\Beacon\ClientInterface` |
| envelope header `dsn` with secret | not sent by default (`envelope_dsn: full` to restore) |
