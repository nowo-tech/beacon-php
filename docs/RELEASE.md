# Release

1. `make release-check` (co-author check, open PRs, composer sync, cs-fix / cs-check, Rector, PHPStan, coverage 100%,
   PHP matrix) and the release security checklist in [SECURITY.md](SECURITY.md#release-security-checklist-1241).
2. Move the `[Unreleased]` entries of `docs/CHANGELOG.md` to `## [X.Y.Z] - YYYY-MM-DD`, update
   `Nowo\Beacon\Version::VERSION` and `docs/UPGRADING.md`, commit `chore(release): X.Y.Z`, then
   run `make check-no-cursor-coauthor` **after** the commit and **before** `git push` (REQ-GIT-001).
3. Wait for CI green on that exact commit, then tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"` and push the tag.
   `.github/workflows/release.yml` creates the GitHub release from the tag message and the changelog entry.
4. Packagist picks the tag up from the GitHub hook. `.github/workflows/sync-releases.yml` backfills a GitHub release
   for any tag that has none.
