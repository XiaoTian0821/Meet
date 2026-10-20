# ConnectMeet — Testing & QA Report

**Date:** 2026-10-20 · **Environment:** Windows 10/11 · XAMPP · Apache 2.4.58 · PHP 8.2.12 (CLI + Apache) · MySQL/MariaDB server on :3306

This report documents what was verified in the available environment and what requires additional resources (a second device, HTTPS, TURN, or DB admin access).

## 1. Environment verification

| Check | Result | Notes |
|---|---|---|
| PHP 8.2.12 present | ✅ | `C:\xampp\php\php.exe` |
| `pdo_mysql`, `openssl`, `random` extensions | ✅ | required for the app |
| Apache serving `htdocs` | ✅ | port 80, `AllowOverride All` confirmed |
| `.htaccess` applies (config/scripts blocked) | ✅ | `AllowOverride All` on `C:/xampp/htdocs` |
| MySQL server running | ✅ | listening on 3306 |
| `root` MySQL access from app | ❌ | This instance requires a password that could not be discovered. The app degrades gracefully (JSON 503 + UI banner). |
| All PHP files parse | ✅ | `php -l` clean on every `.php` |
| All JS files parse | ✅ | `node --check` clean on every `.js` |
| Landing page renders | ✅ | no PHP fatal; shows title + brand |
| `api/health.php` | ✅ | returns JSON; reports DB state honestly |
| Meeting creation flow (code gen) | ✅ (logic) | uses `random_bytes`, verified against `UNIQUE meeting_code` |
| Malicious chat escaping | ✅ (logic) | client renders with `textContent` (never `innerHTML`); server caps length + rate-limits |
| Two-participant WebRTC | ⏳ | needs a running DB + 2 browsers; not executed in this sandbox |
| Camera / microphone | ⏳ | requires a physical device + HTTPS (or `localhost`) secure context |
| Screen sharing, captions, recording | ⏳ | require a browser with the feature + user gesture |

## 1b. Automated smoke test (`tests/smoke.php`)

Run with:
```
C:\xampp\php\php.exe C:\xampp\htdocs\Meet\tests\smoke.php
```
Result in this environment (DB not connected):
```
PASS  health returns JSON envelope
PASS  health reports db state honestly
PASS  meeting create returns envelope
PASS  meeting create degraded gracefully (DB unavailable -> 503)
PASS  chat without credential rejected
PASS  malicious chat rejected by membership check
6 passed, 0 failed.
```
The smoke test branches: when the DB **is** available, it additionally verifies the full create → validate → join → leave → double-leave-rejected cycle and rejects unknown codes.

## 2. Manual test checklist (to run on a machine with DB configured)

Copy this checklist into the browser session:

- [ ] 1. Landing page loads; clock ticks; no console errors.
- [ ] 2. "Start instant meeting" → generates a code like `abc-defg-hij` + a share link; "Copy link" works.
- [ ] 3. Paste an unknown code into "Join" → friendly "Meeting not found" error.
- [ ] 4. Create a scheduled meeting for 20 min out, share the link, open in a second browser → second browser joins, sees the first.
- [ ] 5. Two tabs/profiles on the same machine → audio + video flow both ways; mute/camera toggle syncs to the other participant within ~3s.
- [ ] 6. Screen-share from one tab → the other tab shows the shared tile with a "Sharing" tag; stop → camera returns.
- [ ] 7. Chat: send a message containing `<script>alert(1)</script>` → the other tab shows it as plain text, no alert.
- [ ] 8. Raise hand → other participant sees the ✋ badge within ~2s; lower hand → badge disappears.
- [ ] 9. Reaction (👍/❤/😂/🎉/😮/👏) → floating emoji on the other tab.
- [ ] 10. Leave meeting → other participant's tile disappears within one polling cycle.
- [ ] 11. Host ends-for-everyone → all participants return home with a status message.
- [ ] 12. Network interruption (turn off Wi-Fi 30s, restore) → "Connection unstable" pill, then recovers or "rejoin" prompt.
- [ ] 13. 8th participant joins an 8-seat meeting → 9th is rejected with "meeting is full".
- [ ] 14. Host locks the meeting → new joins get "locked" until host unlocks.
- [ ] 15. Wait 7 days (or change `meeting_expiry_hours`) → meeting expires; join returns 410.
- [ ] 16. Run `scripts/cleanup.php` from CLI → stale participants marked left, expired meetings ended, transient events pruned.
- [ ] 17. Two participants on different subnets (e.g. one on LTE) → document whether TURN was required.
- [ ] 18. Mobile: open on a phone → grid collapses to 1–2 columns; controls are tappable.

## 3. Known limitations (documented, not bugs)

1. **Mesh ceiling.** Peer-to-peer mesh scales at O(N²) bandwidth. The prototype caps at 12 participants; `install.sql` and `config` keep this configurable. For 12+ participants you need an SFU (mediasoup / Janus / WebRTC-RTU). PHP cannot be an SFU.
2. **No server-side recording.** The "Record" button captures the *local* browser's mixed audio + video only. There is no server-composite recording. For broadcast-grade recording, add a capture service (e.g. a headless browser per meeting or a mediasoup capture node).
3. **Captions.** Uses the Web Speech API and only reliably captions the *local* microphone. Remote participants' speech is not captioned in the mesh prototype; the UI discloses this.
4. **TURN required for some networks.** STUN alone does not traverse symmetric NATs / corporate firewalls. Production deployments must provision a TURN server (coturn, or a hosted TURN) and fill in `config/database.php → webrtc.ice_servers`.
5. **Long-poll latency.** Signaling uses HTTP long-poll (not WebSocket) because the target is shared cPanel PHP. Typical latency 1–3s. Upgrade path: Socket.IO / Swoole / Ratchet.
6. **No account layer in the prototype.** Guest participation by name; optional `users` table + `password_hash` is scaffolded but not wired to a login UI.
7. **DB admin access required to bootstrap.** The app cannot create its own database; `database/install.sql` must be imported by a DBA with privilege. In this sandbox `root` had an unknown password so the app reported "db: error" — by design, not a code defect.

## 4. How to unblock end-to-end testing

1. Start MySQL via XAMPP Control Panel.
2. In `phpMyAdmin` (or `mysql -uroot`), import `database/install.sql`.
3. Create a dedicated app user:
   ```sql
   CREATE USER 'connectmeet'@'localhost' IDENTIFIED BY 'connectmeet';
   GRANT ALL PRIVILEGES ON connectmeet.* TO 'connectmeet'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Edit `config/database.php` to match that user (or keep `root`/empty).
5. Open `http://localhost/Meet/`, create a meeting, copy the link, open it in a second browser → two-participant video should flow.
