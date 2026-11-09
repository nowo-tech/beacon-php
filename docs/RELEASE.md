# Release

1. `make release-check` (hygiene, validate, cs, Rector, PHPStan, coverage 100%, PHP matrix).
2. Move the `[Unreleased]` entries of `docs/CHANGELOG.md` to `## [X.Y.Z] - YYYY-MM-DD`, update
   `Nowo\Beacon\Version::VERSION` and `docs/UPGRADING.md`, commit `chore(release): X.Y.Z`.
3. Wait for CI green on that exact commit, then tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"` and push the tag.
   `.github/workflows/release.yml` creates the GitHub release from the tag message and the changelog entry.
4. Packagist picks the tag up from the GitHub hook.
