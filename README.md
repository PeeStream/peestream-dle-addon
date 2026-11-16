<p align="center">
  <a href="https://peestream.com" target="_blank" rel="noopener noreferrer">
    <img src="assets/branding/peestream-logo-horizontal.svg" alt="PeeStream for DataLife Engine" width="380" style="max-width: 100%;">
  </a>
</p>

# PeeStream Video Player, Inserter & Safe Auto-Migrator for DataLife Engine (DLE)

Official DataLife Engine addon for **PeeStream** (`https://peestream.com` / `https://peeplayer.com`). Seamlessly embed high-performance HLS video streams, upload remote videos directly from your article editor, synchronize TV episodes automatically via webhooks, and safely migrate links from failing third-party hosters.

> [!WARNING]
> **BETA RELEASE (v1.0.5-beta.2) - NOT PRODUCTION TESTED**
> Compatibility across DLE 13.0 - 17.3+ is expected based on architecture review, DLE manifest specification, and extensive automated harness simulations (255 tests passed). Live CMS End-to-End runtime testing on an active DLE installation has **NOT BEEN RUN**. Installation is recommended exclusively on staging/testing sites. Always make a full database backup before running migrations. Please report any issues along with your CMS, PHP, and database versions.

---

## Technical Specifications & Requirements

- **Target DLE Versions:** 13.0 and newer (expected compatibility based on manifest specification and code review; Live CMS E2E: NOT RUN)
- **PHP Compatibility:** PHP 7.4 through 8.3 (syntax lint verified on lsphp72 through lsphp83)
- **PHP Extensions:** `curl`, `json`, `mbstring`
- **Database:** MySQL 5.7+ / MariaDB 10.3+ (InnoDB, UTF-8/UTF8MB4)
- **Security Features:** CSRF protection, DOM textContent XSS immunity, Rate limiting (60 req/min), Replay attack protection, Pre-migration DB backups, Atomic 1-click rollback

---

## Key Features

1. **Native DLE Plugin Manager Integration:**
   - 1-click install via XML manifest (`peestream.xml`).
   - Virtual hook patching for `options.php`, `show.full.php`, `show.short.php`, `addnews.php`, and `editnews.php`.

2. **Interactive Article Editor Modal:**
   - Adds a dedicated **PeeStream** button to the DLE BBCode and WYSIWYG editor toolbars.
   - **Quick Code:** Instant alphanumeric code or embed URL insert.
   - **Remote URL Upload:** Import direct MP4s or verified hoster URLs straight into your cloud account.
   - **IMDb Importer:** Reserve and link videos by IMDb ID (`tt...`) with season and episode numbers.
   - **My Library:** Browse, filter, and insert existing account videos directly from the modal.

3. **Safe Competitor Auto-Migrator:**
   - Automatically detects video links from dying hosters and replaces them with PeeStream players.
   - **Verified Supported Hosters:**
     - Streamtape (`streamtape.com`, `streamta.pe`, `strtape.cloud`)
     - MixDrop (`mixdrop.co`, `mixdrop.to`, `mixdrop.sx`, `mixdrop.bz`)
     - VOE (`voe.sx`, `voe-network.net`)
     - StreamWish (`streamwish.to`, `streamwish.com`, `wishembed.pro`)
     - EarnVids / VidHide (`earnvids.com`, `vidhide.com`, `vidhidepro.com`)
     - PixelDrain (`pixeldrain.com`)
     *(Note: Doodstream and Filemoon are intentionally excluded due to Cloudflare/captcha barriers to guarantee 100% transfer integrity).*
   - **Safety First:**
     - **Dry-Run Mode:** Test and preview migrations without making any changes to the database.
     - **Pre-Migration DB Backup:** Full snapshots stored in `{PREFIX}_peestream_backups`.
     - **1-Click Rollback:** Instantly restore any or all migrated articles back to their original state.
     - **Multi-link Post Preservation:** Replaces strictly the target hoster URL; all surrounding text and additional links remain untouched.

4. **Hardened AutoPublish V2 Webhook Receiver (`engine/ajax/peestream_webhook.php`):**
   - **POST-Only Enforcement and HMAC-SHA256:** Verifies `X-PeeStream-Signature: sha256=<hex>` against the exact raw request body with `hash_equals()`; URL/query-string secrets are not accepted.
   - **Site Binding:** Requires the configured PeeStream `site_id` to match the signed `media.ready` payload.
   - **V2 Event Handling:** `site.test` gets an immediate JSON 200 response; unknown event types are acknowledged with 200 and ignored.
   - **Idempotency and Replay Window:** Reserves `event_id` uniquely and prunes event IDs after 72 hours; events older than 72 hours are acknowledged without processing.
   - **Rate Limiting:** Protects against flooding with a 60 requests/minute per-IP limit.
   - **Strict IMDb Matching and DLE xfields:** Matches articles by verified IMDb identifiers and preserves the existing configurable player xfield mapping.
   - **Moderation Queue:** Option to place newly auto-created articles in the moderation queue (`approve=0`).
   - **Configurable DLE identity mapping:** Movies match exact configured IMDb first, then TMDb; episodes match series TMDb ID + season + episode (including season 0).
   - **Player/source xfields:** Optional player xfield receives a responsive PeeStream iframe; a separate source-code xfield preserves the canonical PeeStream file code. The legacy `peestream` code field remains the default unless an embed xfield is configured.

---

## Installation Guide

### Method A: DLE Plugin Manager (Recommended)
1. Download `dle_peestream_v1.0.5-beta.2.zip` from the official GitHub Releases page and extract the files.
2. In your DLE Administration Panel, navigate to **Plugin Management** (`?mod=plugins`).
3. Click **Upload Plugin** and select `peestream.xml`.
4. Upload the `engine/` and `language/` directories to your server root (if not automatically extracted).
5. Set file permissions on `engine/data/peestream.conf.php` to `0644`.

### Method B: Manual Installation via FTP/SSH
1. Upload the contents of `engine/` and `language/` directly into your DLE root directory.
2. Open `engine/inc/options.php` and register the module in your admin options list.
3. Import the table structures into your database:
   ```sql
   CREATE TABLE IF NOT EXISTS dle_peestream_webhooks (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       file_code VARCHAR(64) NOT NULL,
       event VARCHAR(32) NOT NULL DEFAULT 'video_ready',
       post_id INT UNSIGNED NOT NULL DEFAULT 0,
       ip VARCHAR(45) NOT NULL DEFAULT '',
       received_at DATETIME NOT NULL,
       status VARCHAR(32) NOT NULL DEFAULT 'processed',
       UNIQUE KEY uq_fc_event (file_code, event),
       INDEX idx_ip_time (ip, received_at)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   CREATE TABLE IF NOT EXISTS dle_peestream_backups (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       post_id INT UNSIGNED NOT NULL,
       original_xfields MEDIUMTEXT,
       original_short MEDIUMTEXT,
       original_full MEDIUMTEXT,
       migrated_url VARCHAR(500) NOT NULL DEFAULT '',
       new_code VARCHAR(64) NOT NULL DEFAULT '',
       backup_time DATETIME NOT NULL,
       status VARCHAR(32) NOT NULL DEFAULT 'completed',
       INDEX idx_post_id (post_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   CREATE TABLE IF NOT EXISTS dle_peestream_queue (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       post_id INT UNSIGNED NOT NULL,
       original_url VARCHAR(500) NOT NULL,
       url_hash VARCHAR(64) NOT NULL DEFAULT '',
       filecode VARCHAR(64) NOT NULL DEFAULT '',
       state VARCHAR(32) NOT NULL DEFAULT 'pending',
       original_xfields MEDIUMTEXT,
       original_short MEDIUMTEXT,
       original_full MEDIUMTEXT,
       status VARCHAR(32) NOT NULL DEFAULT 'pending',
       owner_token VARCHAR(64) NOT NULL DEFAULT '',
       retry_count INT UNSIGNED NOT NULL DEFAULT 0,
       created_at DATETIME NOT NULL,
       updated_at DATETIME NOT NULL,
       error_msg VARCHAR(255) NOT NULL DEFAULT '',
       UNIQUE KEY uq_post_urlhash (post_id, url_hash),
       INDEX idx_post_id (post_id),
       INDEX idx_status (status)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

   CREATE TABLE IF NOT EXISTS dle_peestream_queue_archive (
       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       original_queue_id INT UNSIGNED NOT NULL DEFAULT 0,
       post_id INT UNSIGNED NOT NULL,
       original_url VARCHAR(500) NOT NULL,
       url_hash VARCHAR(64) NOT NULL DEFAULT '',
       filecode VARCHAR(64) NOT NULL DEFAULT '',
       state VARCHAR(32) NOT NULL DEFAULT 'pending',
       original_xfields MEDIUMTEXT,
       original_short MEDIUMTEXT,
       original_full MEDIUMTEXT,
       status VARCHAR(32) NOT NULL DEFAULT 'pending',
       owner_token VARCHAR(64) NOT NULL DEFAULT '',
       retry_count INT UNSIGNED NOT NULL DEFAULT 0,
       created_at DATETIME NOT NULL,
       updated_at DATETIME NOT NULL,
       archived_at DATETIME NOT NULL,
       error_msg VARCHAR(255) NOT NULL DEFAULT '',
       INDEX idx_post_id (post_id)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```

---

## Configuration

1. In DLE Admin Panel, go to **Other Sections -> PeeStream Player** (`?mod=peestream`).
2. Enter your **PeeStream API Key** (obtainable under *Settings > API* at `https://peestream.com`).
3. Select your **Default Cloud Folder** where uploaded videos will be saved.
4. Specify your DLE extra field name (default: `peestream`).
5. Configure identity xfields (defaults: `imdb`, `tmdb`, `tmdb_series_id`, `season`, `episode`) and optional player embed xfield. The source code is stored separately in `peestream_code`.
6. Configure responsive fluid 16:9 sizing and autoplay preferences.
7. In the **Webhook Receiver** tab, set the `site_id` issued when registering this site in PeeStream. The value must match the `site_id` sent in webhook payloads.
8. Generate or retain the Webhook Secret Key (minimum 16 characters). Configure the same secret for this site in PeeStream; the sender signs the raw JSON body with HMAC-SHA256 and sends `X-PeeStream-Signature: sha256=<hex>`.
9. Copy the displayed Webhook URL (no `?secret=` parameter) into your PeeStream site settings. `site.test` is used to verify the endpoint; only a validly signed `media.ready` with matching `site_id` is processed.

---

## Auto-Migrator: Queue Management & Server Cron

The Auto-Migrator processes videos asynchronously so large batches do not time out:

1. **Interactive Processing (Admin Panel):**
   - In Admin Panel -> **PeeStream Player -> Competitor Migrator**, click **Check Pending Encodings** to query PeeStream and replace completed players.
2. **Automated Server Cron Worker (Recommended):**
   - Run the dedicated cron worker script every 2 to 5 minutes to verify transcoding status and automatically replace completed players without requiring admin interaction:
   - **CLI (Crontab):**
     ```bash
     */2 * * * * php -f /path/to/engine/modules/peestream_cron.php >/dev/null 2>&1
     ```
   - **HTTP / Webhook Cron (cURL):**
     ```bash
     */2 * * * * curl -s -X POST -d "secret=YOUR_CRON_SECRET" "https://example.com/engine/modules/peestream_cron.php" >/dev/null 2>&1
     ```
   - The worker incorporates process locking, atomic task fencing (`owner_token`), optimistic article updates, strict conflict checks, pre-replacement article backups, and automatic cache clearing.

---

## Template Tags Reference

Insert these tags into `templates/{your_theme}/fullstory.tpl` or `shortstory.tpl`:

| Tag | Output / Description |
| :--- | :--- |
| `{peestream_player}` | Complete responsive 16:9 fluid video player iframe. |
| `[peestream] ... [/peestream]` | Content displayed **only** if article contains a valid video. |
| `[!peestream] ... [/!peestream]` | Content displayed **only** if article has **no** video. |
| `{peestream_code}` | Raw video identifier (e.g. `eK7g1xY9`). Useful for custom player tabs. |
| `{peestream_url}` | Full embed URL (e.g. `https://peeplayer.com/e/eK7g1xY9`). |

Example usage in `fullstory.tpl`:
```html
[xfgiven_peestream]
<div class="peestream-embed-wrapper">
    {peestream_player}
</div>
[/xfgiven_peestream]
```

---

## Verification & Integrity

- **Distribution Archive:** `dle_peestream_v1.0.4.zip` (or `dle_peestream_v1.0.4-RC.zip`)
- **SHA-256 Checksum:** See `SHA256SUMS` file (verification: `sha256sum dle_peestream_v1.0.4.zip`)

---

## Changelog

### Version 1.0.4
- **Binary-Safe Case-Sensitive URL Deduplication:** Enforced SHA-256 identity (`url_hash`) and `BINARY original_url` comparison in database migration deduplication and queue reservation, preventing case-sensitive video URLs from colliding on case-insensitive collations.
- **Fail-Safe Deduplication Archiving:** Upgrades safely archive duplicate queue items to `{PREFIX}_peestream_queue_archive` before adding unique index `uq_post_urlhash`; any SQL error halts migration with zero data loss.
- **Lock Lease Hardening:** Task claim checks verify active lease ownership and stop processing immediately if lease expires.
- **Webhook Transaction Protection:** Ensured atomic compare-and-swap state management for webhook events.

### Version 1.0.3
- **Unified Manual Migration Locking:** AJAX batch processing and server cron worker are fully coordinated with concurrency locks.
- **Multi-Server Post Atomicity:** Posts containing multiple competitor links process each server link individually and atomically.

### Version 1.0.2 (Multi-Video Integrity, Concurrency Fencing & Rollback Hardening)
- **P0 Multi-Video Migration Integrity & Optimistic Locking:** Replaced uncoordinated article updates with `peestream_apply_post_replacement()` incorporating optimistic concurrency verification (`WHERE id = ... AND xfields = ... AND short_story = ... AND full_story = ...`) with automatic retry loop (up to 3 attempts). Multiple concurrent ready videos in the same article serialize safely without overwriting each other.
- **P0 Idempotent Queue Pre-Reservation & Unique Constraint:** Added `UNIQUE KEY uq_post_orig (post_id, original_url(191))` to `{PREFIX}_peestream_queue`. The migrator inserts an atomic reservation before calling `api/upload/url`, eliminating duplicate uploads to PeeStream from concurrent race conditions. Existing installations are automatically deduplicated before applying the unique index.
- **P1 Owner Token Task Fencing:** Added `owner_token VARCHAR(64)` fencing to `peestream_claim_task()`, `peestream_claim_queue_item()`, and `peestream_release_task()`. Tasks can only be released or finalized by the active owner process, preventing expired or timed-out workers from stomping on active worker locks.
- **P1 Targeted Field Surgical Updates & Primary Player Preservation:** Surgical replacement identifies the exact xfield containing the competitor link and updates only that specific field. Preserves existing primary player fields (`peestream`) when migrating secondary links.
- **P1 Field-Specific Rollback with Multi-Link Independence:** Rollback restores original field values from `original_xfields` while verifying the current value still contains the migrated video. For posts with multiple migrated links, rolling back a single link leaves all other migrated links and newer editorial changes 100% intact.
- **P1 Webhook Retry for Ambiguous & Ignored Events:** Webhook receiver allows atomic CAS takeover retry for events in `ambiguous` and `ignored_no_match` states after manual administrative resolution. Prevents duplicate article creation on retry by checking existing post IDs and `file_code` post attachments.
- **P1 Controlled Database Migrations:** Moved duplicate webhook row cleanup out of everyday administrative runtime code into conditional schema migrations executed only when the unique index is missing.

### Version 1.0.1 (Security & Functionality Hardening)
- **P0 Canonical Database Schema & Migrations:** Unified canonical database schema across XML manifest, runtime, and upgrade routines. Added automated `ALTER TABLE` migrations for existing installations (`url` -> `original_url`, `file_code` -> `filecode`, `state`, `original_xfields`, and `UNIQUE KEY uq_fc_event`).
- **P0 Webhook CAS Takeover & Fail-Closed Guard:** Replaced basic update with atomic compare-and-swap (`WHERE id = ... AND status = ... AND received_at = ...`) for timed-out (`>60s`) and failed webhook processing locks. Enforced fail-closed behavior on database errors, marking status as `failed`.
- **P0 Secret Distribution Safeguard:** Stripped hardcoded secret from repository and distribution packages. Each installation generates a unique 48-hex cryptographic secret via `random_bytes(24)`, while preserving existing user keys during upgrades.
- **P0 PeeStream API `/api/file/info` Array Payload:** Added `peestream_extract_file_info()` to properly handle the PeeStream API array response `[{filecode, canplay, ...}]` across all polling and verification paths. Removed loose fallback to first array element.
- **P0 Atomic Webhook Idempotency:** Implemented atomic event pre-reservation before modifying post content, preventing TOCTOU race conditions from concurrent duplicate webhook deliveries.
- **P0 Strict IMDb Identifier Restriction:** Restricted IMDb matching to dedicated identifier extra fields (`imdb`, `imdb_id`, `imdbid`), rejecting general body or note fields, and rejecting ambiguous multiple matches with `409 Conflict`.
- **P1 Multi-URL Post Scanning & Deduplication:** Migrator scans and queues all candidate competitor URLs across extra fields and stories as independent tasks with `(post_id, original_url)` deduplication.
- **P1 Editor Conflict Detection:** Verifies that the original competitor URL still exists in the article before applying replacement. If modified or deleted by an editor, flags the task as `conflict` and aborts player insertion.
- **P1 Automated Server Cron Worker:** Included `engine/modules/peestream_cron.php` CLI and HTTP worker for automatic queue processing with concurrency locking.
- **P1 Surgical Rollback:** Rollback engine reverts exclusively the exact embed URL and specific extra field without raw string replace across the article, preserving all subsequent editorial updates.
- **P1 Cryptographic JS Secret Generator:** Removed `Math.random()` fallback; secret generation strictly requires `window.crypto.getRandomValues`.
- **P1 Safe Auto-Migrator & CanPlay Verification:** Migrator schedules remote upload and checks `/api/file/info` for `canplay=1`. The original DLE article is modified ONLY when video playback is 100% verified. If upload is processing or fails, original links remain untouched.
- **P1 Persistent Transcode Queue:** Added persistent `{PREFIX}_peestream_queue` table with "Check Pending Encodings" background queue manager for async processing.
- **P1 Hosters:** Cleaned hoster list to strictly confirmed endpoints (MixDrop, Streamtape, VOE, StreamWish, EarnVids, PixelDrain).
- **P2 Pre-Migration Duplicate Deduplication:** Cleans historical duplicate webhook rows before adding `UNIQUE KEY uq_fc_event` to eliminate MySQL 1062 errors on upgrades.
- **P2 Packaging & Full GPLv2:** Added full GNU GPLv2 license file and clean root ZIP archive structure without `upload/` prefix. Code and manifest reviewed for DLE 13.0 through 17.3+ and verified on PHP 7.4 through 8.3 via automated harness (Live CMS E2E: NOT RUN).

---

## Pricing & Early Adopter Promo

- **Core Player & Shortcodes:** **100% Free Forever.** Embedding video streams on DataLife Engine posts using `{peestream}` template tags will never require any paid license.
- **Early Adopter Promo (Active):** All heavy automation capabilities - including real-time **AutoPublish V2 HMAC-SHA256 webhook synchronization** and **automated competitor link migration with surgical atomic rollback** - are currently **100% free and fully unlocked** for all beta testers.
- **Future Roadmap:** To sustainably support server infrastructure and continuous hoster resolver maintenance, high-volume automation may later be accompanied by an optional, low-cost **VIP Webmaster Addon** (starting at ~$1/month), while standard playback and manual embedding remain permanently free.

---

## License & Support

- **License:** GNU General Public License v2 (GPLv2).
- **Official Support:** Visit `https://peestream.com` or post in our official WJunction thread.
