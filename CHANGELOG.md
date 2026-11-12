# Changelog

All notable changes to the official PeeStream DataLife Engine addon are documented here.

## 1.0.4-beta.1 — Pending public beta release

**Status:** Beta; staging use only. **Live DataLife Engine E2E: NOT RUN.**

### Changed
- Updated the XML manifest minimum DLE version to 13.0; version-specific runtime compatibility remains unverified in a live DLE environment.
- Excluded generated `engine/data/peestream.conf.php` from the installation file list.
- Hardened URL deduplication using case-sensitive URL identities.
- Strengthened queue ownership fencing, safe archiving, webhook concurrency handling, and rollback behavior.
- Kept automatic article creation disabled by default.

### Verification
- The repository contains a test log reporting **255 passing DLE mock/integration harness checks**.
- Additional DLE lifecycle and SQLite tests are **isolated simulations, not live CMS E2E tests**.
- Actual DLE Plugin Manager installation, upgrade, migration, and rollback remain **unverified in a running DLE instance**.
- Do not interpret these checks as a guarantee of compatibility or production readiness.

## Earlier releases

See the repository's [GitHub Releases](https://github.com/PeeStream/peestream-dle-addon/releases) for the historical release packages.
