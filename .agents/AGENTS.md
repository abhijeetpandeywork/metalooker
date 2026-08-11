# MetaPanel Agency Dashboard — Agent Architecture Guide & Context

Welcome to the **MetaPanel** codebase. This file provides complete architecture rules, database specifications, security guidelines, and deployment protocols to ensure any AI agent (or developer) working on this repository has full awareness.

---

## 1. Executive Summary & Tech Stack

- **Project Name**: MetaPanel (Digital Rubix Meta Ads Agency Dashboard)
- **Domain Target**: `metalooker.digitalrubix.site` (Production)
- **Tech Stack**:
  - **Language**: Pure PHP 8.2 (Zero Node.js runtime required on server)
  - **Database**: MySQL 8 via PDO (Prepared statements only, no raw interpolation)
  - **Frontend UI**: Bootstrap 5.3 (CDN), Chart.js 4.x (CDN), Flatpickr (CDN), FontAwesome 6 (CDN)
  - **PDF Engine**: mPDF (via Composer autoloader or fallback printable HTML)
  - **Hosting Target**: Hostinger Shared Hosting / cPanel / hPanel (`147.93.23.184:65002`)
- **Key Capabilities**:
  - Multi-client isolation (50+ active client accounts on a single deployment).
  - Meta Business Login OAuth 2.0 (Short-to-long-lived 60-day token exchange).
  - AES-256-CBC token encryption at rest (`TokenManager`).
  - Automated 6-hour cron data pull cached in `ad_data_cache`.
  - Configurable per-client widget visibility, brand colors, custom logos, and currencies.
  - CSV & PDF performance exports.

---

## 2. Directory Layout

```
/
├── .env                       ← Environment variables (DB creds, Meta App ID, AES Key)
├── .env.example               ← Template config
├── .gitignore                 ← Git exclusion rules
├── .htaccess                  ← Denies direct web access to db, cron, .env
├── composer.json              ← Defines mpdf/mpdf dependency
├── deploy.js                  ← Automated SSH/SFTP deployment script
├── activity_log.md            ← Comprehensive chronological project audit log
├── PRD.txt                    ← Product Requirements Document text
├── MetaPanel_PRD_Antigravity.docx ← Original PRD document
├── .agents/
│   └── AGENTS.md              ← Portable agent instructions & rules (this file)
├── cron/
│   └── sync_all.php           ← Standalone Hostinger cron worker script
├── db/
│   └── migrations/
│       ├── 001_create_tables.sql ← MySQL schema creation
│       └── 002_seed_admin.sql    ← Initial admin seed user
└── public_html/
    ├── index.php              ← Role-based router (Admin vs Client)
    ├── login.php              ← Authentication portal with brute-force rate limit
    ├── logout.php             ← Session termination
    ├── dashboard.php          ← White-labeled client dashboard
    ├── oauth_callback.php     ← Meta OAuth authorization code callback handler
    ├── admin/
    │   ├── index.php          ← Super Admin operations overview
    │   ├── clients.php        ← Client directory & CRUD creation
    │   ├── client_edit.php    ← Client logo upload, brand color picker, Meta OAuth
    │   ├── team.php           ← Team member access control
    │   └── sync_status.php    ← Sync log console & manual triggers
    ├── api/
    │   ├── sync.php           ← Manual client sync AJAX endpoint
    │   ├── dashboard_data.php ← Analytics JSON data provider
    │   ├── export_csv.php     ← CSV generator
    │   └── export_pdf.php     ← PDF generator
    ├── assets/
    │   ├── css/style.css      ← Custom brand color variables & glassmorphic styling
    │   ├── js/dashboard.js    ← Chart.js line/bar charts & Flatpickr handlers
    │   └── logos/             ← Uploaded client logo assets
    └── includes/
        ├── .htaccess          ← Blocks web access to PHP include files
        ├── config.php         ← Environment loader & security headers
        ├── db.php             ← PDO singleton helper
        ├── auth.php           ← Session, role checks & audit logging
        ├── meta_api.php       ← Meta Graph API v21 wrapper & mock data mode
        ├── token_manager.php  ← AES-256-CBC token encryption/decryption
        └── helpers.php        ← Formatting (Currency, Numbers, Dates, e() XSS helper)
```

---

## 3. Database Schema Overview (MySQL 8)

1. **`users`**: User login accounts. Roles: `super_admin`, `team_member`, `client`.
2. **`clients`**: Client business entities, encrypted tokens (`meta_access_token`), token expiry, brand color, currency, and logo paths.
3. **`ad_data_cache`**: Primary cached insights table. Unique key `(client_id, level, object_id, date_start, date_stop)` prevents duplicate entries during syncs.
4. **`dashboard_config`**: Per-client widget toggles (`show_spend`, `show_roas`, `show_cpc`, etc.) and default date range.
5. **`sync_logs`**: History of 6-hour cron sync runs (status, rows updated, error messages).
6. **`team_client_access`**: Mapping table assigning team members to specific client IDs.
7. **`activity_log`**: System security audit trail.

---

## 4. Crucial Behavioral Rules for AI Agents

1. **Prepared Statements Only**: Every database query must use PDO prepared statements. Never concatenate user input directly into SQL strings.
2. **XSS Protection**: All output rendered to HTML must be wrapped in `e()` or `htmlspecialchars()`.
3. **CSRF Tokens**: All POST forms must include a CSRF token input validated via `verifyCsrfToken()`.
4. **Token Encryption**: Access tokens must be encrypted with `TokenManager::encrypt()` before saving to `clients.meta_access_token` and decrypted with `TokenManager::decrypt()`.
5. **Mock API Toggle**: The system includes a `MOCK_META_API=true` setting in `.env`. When active, `MetaAPI` generates realistic mock advertising metrics without making live Graph API network requests.
6. **Activity Log Updates**: Any future architectural changes, migrations, or feature additions MUST be logged chronologically in [activity_log.md](file:///d:/Antigravity/metalooker/activity_log.md).

---

## 5. Production SSH Deployment Protocol

To deploy updates to `metalooker.digitalrubix.site`:
- SSH Server: `147.93.23.184:65002`
- User: `u406313474`
- Remote Directory: `domains/metalooker.digitalrubix.site/public_html` or `public_html/metalooker.digitalrubix.site`
- Run `node deploy.js` locally to push updates to both production host and GitHub repository.
