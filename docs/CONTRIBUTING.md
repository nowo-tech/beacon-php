# Contributing

## Table of contents

- [Workflow](#workflow)
- [Cursor Agent chat](#cursor-agent-chat)

## Workflow

1. Fork, branch from `main`, use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`,
   `docs:`, `test:`, `chore:`). No agent co-author trailers (`make setup-hooks` installs a `commit-msg` hook that
   strips them; run it before your first commit; CI checks the history). After a release commit run
   `make check-no-cursor-coauthor` **before** `git push`.
2. Development runs in Docker (`make up`, `make shell`, `make down`). Every tool runs with at most 2 worker
   processes (php-cs-fixer `ParallelConfig(2)`, PHPStan `maximumNumberOfProcesses: 2`, Rector `withParallel(…, 2)`).
3. Before a pull request: `make qa`, `make rector-dry`, `make test-coverage` (100% lines), `make test-matrix`
   (PHP 8.2 – 8.5). Changes to the wire format must keep `tests/Golden` green or come with an explicit, documented
   decision; re-record goldens only from a BeaconBundle checkout (`BEACON_GOLDEN_UPDATE=1 make parity BUNDLE=…`).
4. Update `docs/CHANGELOG.md` (`[Unreleased]`) and, for user-visible changes, `docs/UPGRADING.md`.

## Cursor Agent chat

Submit with **Ctrl+Enter** (macOS: **⌘+Enter**) so Enter inserts a newline, and queue follow-ups instead of
interrupting the running agent:

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```
