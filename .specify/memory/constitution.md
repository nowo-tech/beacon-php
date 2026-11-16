# Constitution — Nowo beacon-php

## Purpose

Framework-free PHP SDK for Beacon (error tracking and observability): captures exceptions, messages, PHP errors,
transactions, deploy markers and check-ins and sends them to Beacon's Envelope ingest. Namespace `Nowo\Beacon\`.

## Non-negotiables

1. Core depends on PHP + PSR only; Symfony HttpClient, Monolog and PSR-15 adapters stay optional (`suggest`).
2. A capture never throws into the host application; every send and the shutdown flush are time-bounded.
3. Private by default: the DSN secret only in `X-Beacon-Auth`; sensitive data scrubbed before `before_send`.
4. Byte-for-byte wire parity with BeaconBundle (golden tests).
5. Long-running workers: request state cleared by `reset()`; process-wide caches bounded.
6. PHPStan level max on `src/`; line coverage of `src/` = 100%.
7. REQ-GIT-001: no AI co-author trailers in commits.
