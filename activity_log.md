# MetaPanel — Development & Activity History Log

All major milestones, feature additions, database migrations, and deployment events for **MetaPanel** (Digital Rubix Meta Ads Agency Dashboard) are logged below.

---

## [Phase 1] — 2026-08-11: Foundation & Core Architecture
- **Database Schema**: Authored `db/migrations/001_create_tables.sql` establishing InnoDB tables: `users`, `clients`, `ad_data_cache`, `dashboard_config`, `sync_logs`, `team_client_access`, and `activity_log`.
- **Admin Seeding**: Authored `db/migrations/002_seed_admin.sql` seeding initial super admin user `admin@digitalrubix.com`.
- **Security Headers & Config**: Built `public_html/includes/config.php` parsing `.env` file, setting `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and initiating secure HTTP-only cookies.
- **Database Singleton**: Built `public_html/includes/db.php` wrapping PDO singleton with exception handling.
- **Authentication Engine**: Built `public_html/includes/auth.php` featuring bcrypt verification, session regeneration, role authorization, and brute-force rate-limiting (max 5 attempts per IP per 15 mins).
- **Portal Pages**: Built `public_html/login.php`, `public_html/logout.php`, and gateway router `public_html/index.php`.

---

## [Phase 2] — 2026-08-11: Meta OAuth & Token Security
- **Token Manager**: Built `public_html/includes/token_manager.php` using AES-256-CBC encryption with binary SHA-256 derived keys for securing Meta OAuth access tokens at rest.
- **Meta API Wrapper**: Built `public_html/includes/meta_api.php` interfacing with Meta Graph API v21.0. Implemented `MOCK_META_API` mode for offline testing and developer previews.
- **OAuth Callback**: Built `public_html/oauth_callback.php` handling short-to-long-lived (60-day) token exchanges and CSRF state validation.
- **Client Configuration Admin**: Built `public_html/admin/client_edit.php` providing client logo uploads, brand color selection, custom report title settings, and Meta OAuth triggers.

---

## [Phase 3] — 2026-08-11: Data Synchronization Engine
- **Cron Engine**: Built `cron/sync_all.php` for Hostinger hPanel 6-hour cron execution using `ON DUPLICATE KEY UPDATE` upsert statements on `ad_data_cache`.
- **AJAX Sync Endpoint**: Built `public_html/api/sync.php` enabling ad-hoc client sync triggers from Admin UI.
- **Sync Console**: Built `public_html/admin/sync_status.php` rendering historical sync logs and manual refresh triggers.

---

## [Phase 4] — 2026-08-11: Client Dashboard UI
- **Dashboard View**: Built `public_html/dashboard.php` featuring white-labeled headers, customizable CSS brand variables, date range presets, and responsive metric cards.
- **Frontend Analytics**: Built `public_html/assets/js/dashboard.js` rendering Chart.js Spend Line Charts, Campaign Bar Charts, Flatpickr date range selection, and breakdown tables.
- **Stylesheet**: Built `public_html/assets/css/style.css` delivering a glassmorphic layout.
- **Analytics Endpoint**: Built `public_html/api/dashboard_data.php` aggregating accounts, campaigns, ad sets, and ad metrics.

---

## [Phase 5] — 2026-08-11: Admin Consoles, Exports & Future-Proofing
- **Admin Overview**: Built `public_html/admin/index.php` showing aggregate client stats, total ad spend managed, and activity audit trails.
- **Client & Team Management**: Built `public_html/admin/clients.php` and `public_html/admin/team.php`.
- **Export Engines**: Built `public_html/api/export_csv.php` and `public_html/api/export_pdf.php`.
- **Agent Knowledge Base**: Authored `.agents/AGENTS.md` to ensure complete portable awareness for any AI agent.

---

## [Phase 6] — 2026-08-11: Multi-Client Account Isolation, Global Currencies & Audit Fixes
- **Strict Ad Account Validation**: Enforced strict validation in `cron/sync_all.php` requiring each client to have an explicit `meta_ad_account_id` set before sync. Prevents new/unconfigured clients from defaulting to or cross-fetching another client's ad data.
- **Configured Client Ad Accounts**:
  - **Bagnomy** (ID: 2): `act_221342178972532` (INR)
  - **Sky Line Crest** (ID: 3): `act_1568346498205053` (AED)
  - **J Square** (ID: 4): `act_1520125500129977` (INR/AED)
- **Global Currency Engine**: Expanded `formatCurrency()` in `includes/helpers.php` and `getCurrencySymbol()` in `assets/js/dashboard.js` to support all international currencies (`AED`, `INR`, `USD`, `EUR`, `GBP`, `SAR`, `QAR`, `KWD`, `OMR`, `BHD`, `CAD`, `AUD`, `SGD`, `JPY`, `ZAR`).
- **Date Filter & Level Scoping Fixes**: Updated `api/dashboard_data.php` to query `level = 'campaign'` with strict `$from` to `$to` parameters, eliminating double-counting and enabling dynamic date window recalculations.
- **Admin Managed Spend**: Filtered `admin/index.php` Total Managed Spend to the active 30-day window (`date_start >= DATE_SUB(NOW(), INTERVAL 30 DAYS)`).

---

## [Phase 7] — 2026-08-11: 3-Tier ROAS Engine, Meta Metadata Auto-Detection & Multi-Currency Overhaul
- **3-Tier ROAS Engine**: Integrated Tier 1 (Purchase ROAS), Tier 2 (Custom Conversion Action Values), and Tier 3 (Target Lead Value ROAS) into `api/dashboard_data.php`. Added per-client target lead value configuration.
- **Automated Meta Metadata Detection**: Built `getAccountMetadata()` in `includes/meta_api.php` querying `/v21.0/act_<ID>?fields=currency,business_country_code,timezone_name`. Integrated auto-detection in `cron/sync_all.php`, `oauth_callback.php`, and `admin/client_edit.php`.
- **IST System Timezone Sync**: Configured `Asia/Kolkata` (+05:30) globally across backend and frontend date helpers.
- **Separate Multi-Currency Admin Spend**: Updated `admin/index.php` to render managed ad spend grouped natively by currency badges (`₹30,496.71 (INR)` • `AED 887.70 (AED)`), ensuring 100% financial accuracy without conversion rate distortion.

---

## [Phase 9] — 2026-08-11: Uniform Design System, Transparent Vector Branding & Universal High-Contrast Badges
- **Transparent Vector Logo**: Replaced old sidebar logo with a sleek SVG vector logo (`assets/logos/digital_rubix_logo.svg`) with glowing cyan `#` icon and transparent background.
- **Universal High-Contrast Badges**: Updated `.badge-success-subtle`, `.badge-danger-subtle`, and `.badge-secondary-subtle` in `assets/css/style.css` for crystal-clear readability across Light and Dark themes.
- **Single-Line Sidebar Navigation**: Enforced `width: 275px` and `white-space: nowrap !important` across all admin pages (`index.php`, `clients.php`, `client_edit.php`, `team.php`, `settings.php`, `sync_status.php`).
- **50+ Yr Expert Responsive UX**: Standardized glass cards, form inputs, and mobile navigation drawers for mobile ($375\text{px}$+), tablet ($768\text{px}$+), and desktop viewports.

---

## [Phase 10] — 2026-08-12: Client Paused/Active Access Control, Rate-Limited Manual Sync & Indian Standard Time (IST)
- **Client Active / Paused Access Control**: Configured `attemptLogin()` and `isLoggedIn()` in `includes/auth.php`. Toggling a client to **Paused** (`active = 0`) immediately blocks login attempts and kicks out active client sessions.
- **Client Rate-Limited Manual Refresh**: Updated `api/sync.php` allowing `client` role users to trigger manual refreshes capped at a maximum of **5 manual syncs per day**, while Super Admins and Team Members maintain default unlimited access.
- **System-Wide Indian Standard Time (IST / Asia/Kolkata)**: Enforced `date_default_timezone_set('Asia/Kolkata')` and PDO `SET time_zone = '+05:30'`, standardizing all backend logs, MySQL `NOW()` timestamps, and frontend consoles to IST.
- **OAuth 2.0 Error Safeguard**: Resolved `$adAccountId` variable initialization in `oauth_callback.php`, eliminating HTTP 500 errors during Meta account authorization redirects.
- **Logo Restoration**: Reverted `digital_rubix_logo.svg` to the original, high-contrast branded banner style (grey banner, black footer, serif fonts) to restore proper logo visibility on white dashboard headers.
- **Admin Alert Pop-up Fix**: Added a Client ID check in `assets/js/dashboard.js` to guard `fetchDashboardData()` from executing on admin overview pages where no client reporting is active, resolving the "Client ID is required" alert pop-up.
