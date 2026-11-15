# Changelog

All notable changes to the **PeeStream Video Player, Inserter & Safe Auto-Migrator for DataLife Engine (DLE)** addon will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.5-beta.2] - 2026-11-15

### Fixed
- Release packaging integrity: the tag `v1.0.5-beta.1` tracked `dist/dle_peestream_v1.0.5-beta.1.zip` with SHA-256 `f304f70c...`, which contradicts the `SHA256SUMS` entry and the published release asset (`f573af24...`). `v1.0.5-beta.2` rebuilds the archive from the tagged sources and removes the stale tracked artifact.

### Added
- `scripts/build-release.sh` - byte-reproducible ZIP build (fixed timestamps, manifest-derived allowlist, explicit deflate level) that writes `dist/` and refreshes `SHA256SUMS`.

### Changed
- No functional code changes; webhook, identity mapping, and cron code is unchanged from `v1.0.5-beta.1`.

---

## [1.0.5-beta.1] - 2026-11-15

### Added
- Configurable DLE AutoPublish identity xfields for IMDb, TMDb, series TMDb, season, and episode, plus optional player-embed and source-code fields.
- Strict movie matching by configured IMDb with TMDb fallback; strict episode matching by series TMDb + season + episode, including season zero.
- Per-event webhook audit discriminator derived from `event_id`, preventing the legacy `(file_code,event)` uniqueness key from conflating distinct V2 deliveries.
- Failed, ambiguous, and unmatched event processing releases the delivery reservation so a retry or corrected mapping is not falsely acknowledged as already applied.
- Shared side-effect-free identity and xfield mapping helpers with mock coverage for legacy behavior and responsive player embeds.
- DLE AutoPublish V2 webhook: raw-body HMAC-SHA256 verification, configured `site_id` validation, early `site.test` and unknown-event acknowledgements, and `event_id` deduplication with a 72-hour retention window.
- README configuration steps for the registered site ID and shared HMAC webhook secret; explicitly documents that the webhook URL has no query-string secret.
- Focused standalone webhook mock suite (`tests/mock_webhook_test.php`).

### Security
- Removed support for `?secret=` and the legacy `X-Webhook-Secret` authentication path from the webhook receiver.

---

## [1.0.4-beta.1] - 2026-11-12

### Important Notice
> **BETA RELEASE - NOT PRODUCTION TESTED**
> This pre-release build incorporates critical architectural bug fixes and unit test harnesses. Live CMS end-to-end testing on an active DataLife Engine instance has not been run. Staging deployment and complete database backup prior to migration are mandatory.

### Added
- **DLE Version Manifest Lower-Bound Compatibility:** Standardized XML manifest to `<dleversion>13.0</dleversion>` with `<versioncompare>greater</versioncompare>` and dynamic forward-compatibility notices for untested future DLE versions (> 17.3).
- **Binary-Safe Case-Sensitive URL Deduplication:** Enforced SHA-256 identity (`url_hash`) and `BINARY original_url` comparison in database migration queue queries, preventing case-sensitive video URLs from colliding on case-insensitive collations.
- **Fail-Safe Deduplication Archiving:** Upgrades safely archive duplicate queue items to `{PREFIX}_peestream_queue_archive` before adding unique index `uq_post_urlhash`; any SQL error halts migration with zero data loss.
- **Clean Installation Resilience:** Eliminated missing file errors during clean installation by removing `peestream.conf.php` from `<filelist>` (configuration is automatically initialized by `<phpinstall>`).
- **Comprehensive Unit & Harness Test Suite:** 255 automated tests covering syntax linting, XML manifest compliance, version comparing, rollback safety, and webhook handlers.

---

## [1.0.3] - 2026-11-11

### Added
- **Multi-Hoster Article Atomicity:** Articles containing multiple competitor links process each server link individually and atomically with distinct backup snapshots in `{PREFIX}_peestream_backups`.
- **Idempotent Queue Pre-Reservation:** Added `UNIQUE KEY uq_post_orig (post_id, original_url(191))` to the migration queue table. Tasks are atomically pre-reserved before calling PeeStream's `/api/upload/url`.
- **Strict DB Schema Compliance Verification:** Table creation verifies the presence of all mandatory columns across all tables and validates indexes.
- **Consistent UTC Timestamps:** Switched all timestamp writes and comparison cutoffs to consistent UTC.

---

## [1.0.2] - 2026-11-10

### Added
- **Queue Lock Lease Extension & Time Budgeting:** Heartbeat lease extension and execution time budgeting preventing worker usurpation.
- **Atomic Lock Release by Owner Token:** Implemented atomic SQL conditional deletion ensuring a worker can only release its own lock token.
- **Partial Failure Recovery:** Backups are written with status `in_progress` prior to article modification and promoted to `completed` only after save confirmation.

---

## [1.0.1] - 2026-11-09

### Added
- **Server Integrity & Strict Matching:** `replace` migration strictly matches identical original server URLs.
- **Differentiated Surgical Rollback:** Rollback engine restores complete original article state.
- **Atomic Webhook Idempotency:** Webhook receiver atomically pre-reserves incoming `(file_code, event)` records with status `processing`.
- **Security:** CSRF protection across admin actions, DOM textContent XSS immunity, rate limiting (60 req/min).

---

## [1.0.0] - 2026-11-08

### Added
- Initial release with DLE editor modal, BBCode/template tags, competitor auto-migrator, and webhook receiver.
