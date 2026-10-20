# ConnectMeet

A complete, functional, responsive **web-based video conferencing application** built with **PHP 8.3+ (XAMPP), MySQL/MariaDB, and native WebRTC** — no frameworks, no build step.

ConnectMeet is an original project. It has its own name, branding, and styling, and is not affiliated with any other video-conferencing vendor. It is intended for **learning and small-scale prototyping** (≤ ~12 participants in a mesh).

## What it does

- Create an **instant** meeting or a meeting **for later** (with a shareable link + unique code).
- Join by **code or link** (server-validated; unknown codes are rejected, never auto-created).
- **Pre-join lobby** with live camera preview, mic activity meter, device selection, and toggles.
- **Real peer-to-peer audio/video** via `RTCPeerConnection` (mesh) — no fake streams.
- **Screen sharing** with automatic track renegotiation and browser-initiated-stop handling.
- **Chat**, **emoji reactions**, **raise/lower hand**, and **active-speaker** highlighting.
- **Participant management** (list, mute/camera state, host-removal, lock, waiting room, end-for-everyone).
- **Live captions** (Web Speech API, best-effort, local microphone) and **local browser recording** (MediaRecorder).
- **Settings** (camera / mic / audio output, captions, display name) with device-change handling.
- **Signaling** over PHP JSON endpoints using short/long polling; presence heartbeats; stale cleanup.
- **Security**: PDO prepared statements, CSRF/participant-credential protection, server-side validation, rate limiting, restrictive CORS, escaped output.

## Quick start (XAMPP)

1. Put the project at `C:\xampp\htdocs\Meet\` (already there in this workspace).
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Import the schema:
   ```
   mysql -uroot < C:\xampp\htdocs\Meet\database\install.sql
   ```
   (or import `database/install.sql` via phpMyAdmin.)
4. Create the app DB user and set the credentials in `config/database.php`:
   ```sql
   CREATE USER 'connectmeet'@'localhost' IDENTIFIED BY 'connectmeet';
   GRANT ALL PRIVILEGES ON connectmeet.* TO 'connectmeet'@'localhost';
   FLUSH PRIVILEGES;
   ```
5. Open **http://localhost/Meet/** in a recent Chrome/Edge/Firefox.
6. Create a meeting, copy the link, open it in a second browser → two-participant video.

> **Media requires a secure context.** `http://localhost` is treated as secure by browsers, so camera/mic work locally. Accessing the app from *another* machine over plain `http://` will have camera/mic blocked — use **HTTPS** for remote access (see `INSTALLATION.md`).

## Directory layout

```
Meet/
├── index.php            landing page (create / join)
├── room.php             lobby + in-meeting interface
├── config/
│   ├── example.php      documented config template (safe to commit)
│   └── database.php     LOCAL credentials (gitignored) — edit for your host
├── includes/
│   ├── bootstrap.php    config + PDO + session + helpers (top of every entry point)
│   ├── functions.php    JSON body, rate limiting, token hashing, cleanup helpers
│   ├── response.php     standard JSON envelope + status helpers
│   ├── csrf.php         participant-credential + role authorization
│   └── auth.php         optional account auth (scaffold)
├── api/
│   ├── meetings.php     create / validate / lock / waiting / end
│   ├── participants.php join / leave / heartbeat / remove / list / state
│   ├── signaling.php    SDP offer/answer + ICE (long-poll)
│   ├── events.php       reactions / hand / screen / captions (poll)
│   ├── chat.php         send / poll (rate-limited, length-capped)
│   └── health.php       diagnostics
├── assets/
│   ├── css/ style.css, responsive.css
│   ├── js/  app.js, webrtc.js, signaling.js, meeting.js, settings.js
│   └── images/
├── database/install.sql schema + default app_settings
├── scripts/cleanup.php  cron maintenance (stale/expired/purge)
├── docs/                TESTING.md, WebRTC & STUN/TURN guide, security notes
├── .htaccess            blocks config/scripts/includes/database/tests, security headers
└── .gitignore
```

## Documentation index

- **`docs/INSTALLATION.md`** — XAMPP (local) + cPanel (production) step-by-step.
- **`docs/WEBRTC_STUN_TURN.md`** — how the mesh works, STUN/TURN setup, NAT notes, SFU upgrade path.
- **`docs/SECURITY.md`** — threat model, controls, and hardening checklist.
- **`docs/ARCHITECTURE.md`** — data flow, polling model, and why choices were made.
- **`docs/TESTING.md`** — test checklist + results + known limitations.

## Limitations & upgrade path

- **Mesh ceiling ~12 participants** (bandwidth O(N²)). For more, add an **SFU** (mediasoup/Janus) — documented in the WebRTC guide; PHP is not suitable as a media relay.
- **Local-only recording** (browser side). No server-composite recording.
- **Long-poll signaling** (shared-cPanel friendly). Upgrade to WebSocket for lower latency.
- **Captions** caption the local mic only in the mesh prototype.

See `docs/TESTING.md` §3 for the full limitation list.
