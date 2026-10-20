# ConnectMeet — Security Notes

ConnectMeet is built to the "security is mandatory" bar: defense-in-depth across SQL, XSS, authorization, CSRF, DoS, and transport. This document summarizes the controls and a hardening checklist.

## 1. Data layer

| Control | Where | Purpose |
|---|---|---|
| **PDO prepared statements** | every `api/*.php` | Prevents SQL injection; `ATTR_EMULATE_PREPARES=false` for native prepares. |
| **utf8mb4** everywhere | `install.sql` | Emoji in chat/reactions round-trip correctly. |
| **Foreign keys + indexes** | `install.sql` | Enforce referential integrity; `idx_*` support polling/cleanup queries. |
| **Hashed credentials** | `cm_hash_token()` | Participant tokens & host tokens are stored as **SHA-256 hashes** (with the app secret), never in plaintext. |
| **Transient signaling not in DB forever** | `meeting_events` + cleanup | SDP/ICE rows are marked `delivered_at` and purged by `scripts/cleanup.php`; no media is stored. |

## 2. Request & input

- **`cm_read_json_body()`** caps the JSON body at **1 MB** to reject oversized payloads.
- **Server-side validation** on every field: display-name sanitization (control chars stripped, length capped), message length capped (`max_chat_len`), meeting-limit bounds, scheduled-time sanity.
- **Output escaping**: the client renders chat with `textContent` (never `innerHTML`); PHP HTML pages use `htmlspecialchars`. No `Access-Control-Allow-Origin: *`.
- **CSRF / replay protection**: state-changing operations require the **participant credential** (`participant_token`) bound to the meeting; host operations additionally require the **host token**. The credential is a per-participant random value issued once at join and verified via its stored hash.
- **Rate limiting**: sliding-window table (`rate_limits`) for `meeting_create`, `join`, `chat`, `reaction`, `signal_send` — with `FOR UPDATE` locking to keep the counter safe under concurrency.
- **Deduplication**: event ids are monotonic; the poller tracks `latest_id` so no event is processed twice.

## 3. Authorization (meeting boundaries)

- **Membership checked on every protected operation.** `cm_get_participant()` resolves the credential and confirms the participant is still in the meeting (not left, not stale).
- **Role enforcement**: `cm_require_role()` — only `host` may lock, manage the waiting room, remove participants, or end-for-everyone. A participant **cannot** remove the host or end someone else's meeting.
- **No arbitrary-room injection**: signaling `to` targets are validated against live participants; a participant cannot push media to a room it is not a member of.
- **No impersonation**: the host role is granted **only** by presenting the host token (or account host_user_id). A normal participant presenting a forged role field in the request is ignored — role comes from the database row, not the client.

## 4. Transport & cookies

- **HTTPS in production**; `cookie_secure=true` when TLS is active. `httponly` + `SameSite=Lax` session cookie.
- **Session ID regeneration after login** (`includes/auth.php`).
- **Security headers** (`.htaccess`): `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-XSS-Protection`.
- **CORS**: same-origin by default; an optional allow-list (`cors.allowed_origins`) echoes only a matched `Origin` — never `*`.

## 5. Secrets & configuration

- `config/database.php` is **gitignored** and `.htaccess`-blocked from the web.
- `config/example.php` is a safe template with placeholder secrets.
- A weak `app_secret` triggers a logged warning (the token hash is salted with it, so secret rotation invalidates existing credential hashes — expected).
- **Logging never includes passwords, tokens, or media** — only error summaries and row counts.

## 6. Media privacy

- **No silent capture.** Camera/mic use `getUserMedia`; screen share uses `getDisplayMedia` (explicit user consent per click).
- **Recording is local-only** and opt-in; a persistent indicator is shown and participants are informed.
- **Captions** use the Web Speech API in-browser; mic audio is **not** uploaded to a third-party service.
- **Active-speaker** estimation uses a local `AnalyserNode` RMS threshold — raw microphone audio is **never** transmitted for speaker detection.

## 7. Hardening checklist (production)

- [ ] `app_secret` is a fresh 32+ byte random value (`openssl rand -hex 32`).
- [ ] `cookie_secure = true`, TLS enabled, HSTS on.
- [ ] TURN credentials are **short-lived and per-user** (not a static shared secret).
- [ ] `config/database.php` is `600`, owner-only.
- [ ] `scripts/cleanup.php` runs on a cron.
- [ ] Shared hosting: confirm no `allow_url_include`, `expose_php=Off`, and a restrictive `open_basedir` where applicable.
- [ ] Monitor `rate_limits` growth; the cleanup script purges stale windows.

## 8. Threat model summary

| Threat | Mitigation |
|---|---|
| SQL injection | prepared statements everywhere |
| Stored/reflected XSS | `textContent` render, escaping, length caps |
| Unauthorized meeting entry | credential + membership check, host token for host ops |
| Host impersonation | role from DB, not client; host token required |
| CSRF / replay | per-participant random credential, host token |
| DoS / signaling storm | sliding-window rate limiting, 1 MB body cap, event dedup |
| Credential leakage | hashed storage, HTTPS, httponly/samesite cookies |
| Silent screen/mic capture | browser consent APIs only |
| Media DoS on mesh | participant limit (configurable, default 12) |
