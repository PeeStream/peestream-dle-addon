# PeeStream Video Player, Inserter & Safe Auto-Migrator for DataLife Engine (DLE)

Official, production-ready DataLife Engine addon for **PeeStream** (`https://peestream.com` / `https://peeplayer.com`). Seamlessly embed high-performance HLS video streams, upload remote videos directly from your article editor, synchronize TV episodes automatically via webhooks, and safely migrate links from failing third-party hosters.

---

## Technical Specifications & Requirements

- **Supported DLE Versions:** 13.0, 13.1, 13.2, 13.3, 14.0, 14.1, 14.2, 14.3, 15.0, 15.1, 15.2, 16.0, 16.1, 17.0, 17.1, 17.2, 17.3+
- **PHP Compatibility:** PHP 7.4, 8.0, 8.1, 8.2, 8.3
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

4. **Hardened Webhook Receiver (`engine/ajax/peestream_webhook.php`):**
   - **POST-Only Enforcement:** Rejects non-POST methods.
   - **Cryptographic Authentication:** Requires a secret token of at least 16 characters (`hash_equals`).
   - **Rate Limiting:** Protects against flooding with a 60 requests/minute per-IP limit.
   - **Replay Protection:** Deduplication index ensures the same video ready event is never applied twice.
   - **Strict IMDb Matching:** Matches articles strictly by verified IMDb ID in extra fields (never by loose title matching).
   - **Moderation Queue:** Option to place newly auto-created articles in the moderation queue (`approve=0`).

---

## Installation Guide

### Method A: DLE Plugin Manager (Recommended)
1. Download `dle_peestream_v1.0.1.zip` and extract the files.
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
       INDEX idx_fc_event (file_code, event),
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
   ```

---

## Configuration

1. In DLE Admin Panel, go to **Other Sections -> PeeStream Player** (`?mod=peestream`).
2. Enter your **PeeStream API Key** (obtainable under *Settings > API* at `https://peestream.com`).
3. Select your **Default Cloud Folder** where uploaded videos will be saved.
4. Specify your DLE extra field name (default: `peestream`).
5. Configure responsive fluid 16:9 sizing and autoplay preferences.
6. Under the **Webhook Receiver** tab, click **Generate** to create a cryptographically secure 32-character secret key.
7. Copy the Webhook URL into your PeeStream account to enable real-time TV and movie synchronization.

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

- **Distribution Archive:** `dle_peestream_v1.0.1.zip`
- **SHA-256 Checksum:** See `SHA256SUMS` file (verification: `sha256sum dle_peestream_v1.0.1.zip`)

---

## Changelog

### Version 1.0.1 (Security & Functionality Hardening)
- **P0 Security:** Strict webhook rejection if secret is unconfigured or < 16 characters. Added rate limiting (60 req/min) and replay attack protection table (`dle_peestream_webhooks`).
- **P0 XSS Protection:** Complete refactor of editor modal JS to use DOM `textContent` and `createElement`, removing unescaped HTML injections.
- **P0 CSRF:** Mandatory `dle_login_hash` verification for all state-changing AJAX requests.
- **P0 Permissions:** Removed insecure 0666/0777 advice; enforced strict 0644 config permissions.
- **P0 Matching:** Webhook strictly requires verified IMDb ID matching; eliminated arbitrary title LIKE updates.
- **P1 API Alignment:** Fully aligned with official PeeStream API endpoints (`/api/account/info`, `/api/folder/list`, `/api/file/list`).
- **P1 Safe Auto-Migrator:** Added Dry-Run mode, automated pre-migration DB backups (`dle_peestream_backups`), 1-click rollback, and safe multi-link article handling.
- **P1 Hosters:** Cleaned hoster list to strictly confirmed endpoints (MixDrop, Streamtape, VOE, StreamWish, EarnVids, PixelDrain).
- **P2 Packaging:** Clean root archive structure without `upload/` prefix for direct FTP extraction and Plugin Manager compatibility.

---

## License & Support

- **License:** GNU General Public License v2 (GPLv2).
- **Official Support:** Visit `https://peestream.com` or post in our official WJunction thread.
