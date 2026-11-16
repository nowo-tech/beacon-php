# PSR evaluation (REQ-CS-007)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 (coding style) | Adopted | PHP-CS-Fixer (`.php-cs-fixer.dist.php`), CI gate. |
| PSR-4 (autoloading) | Adopted | `Nowo\Beacon\` → `src/`. |
| PSR-3 (logger) | Adopted | Optional logger for transport failures; Monolog handler forwards records to Beacon. |
| PSR-7 / PSR-17 (HTTP messages, factories) | Adopted | Envelopes are built as PSR-7 requests for PSR-18 clients. |
| PSR-18 (HTTP client) | Adopted | `Psr18Transport` works with any client (auto-discovered or injected). |
| PSR-15 (middleware) | Adopted | `Http\BeaconMiddleware` for Slim / Mezzio stacks (optional). |
| PSR-20 (clock) | Adopted | Injectable clock for timestamps and backoff. |
| PSR-11 (container), PSR-14 (events), PSR-6/16 (cache) | N/A | The SDK has no container, no event dispatcher and keeps its bounded caches in memory. |
