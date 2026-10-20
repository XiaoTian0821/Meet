# ConnectMeet — Architecture

## 1. Component overview

```
┌─────────────── Browser ───────────────┐        ┌────────── PHP (Apache) ──────────┐
│  index.php (landing)                  │        │  api/meetings.php     create/end  │
│  room.php (lobby + meeting)           │  HTTP   │  api/participants.php join/leave  │
│   ├─ app.js / meeting.js  (UI)        │◄──────►│  api/signaling.php    sdp/ice     │
│   ├─ webrtc.js    (RTCPeerConnection) │  JSON   │  api/events.php       poll/send   │
│   ├─ signaling.js (polling transport) │         │  api/chat.php         poll/send   │
│   └─ settings.js  (devices/captions)   │        │  api/health.php       diagnostics │
└───────────────────────────────────────┘         └───────────────┬──────────────────┘
        │  ─────── UDP media (WebRTC, mesh) ───────►               │
        │   between peers (PHP is NOT in the media path)          │
        └──────────────────────────────────────────────────────────┘
                                                             ┌──────────────────┐
                                                             │  MySQL / MariaDB  │
                                                             │  meetings,        │
                                                             │  participants,    │
                                                             │  messages, events │
                                                             └──────────────────┘
```

## 2. Why this split

- **PHP does signaling, not media.** Real-time media must be direct peer-to-peer (or via an SFU). PHP cannot hold UDP streams. So the app stores transient SDP/ICE payloads in `meeting_events` and lets browsers poll them; media flows peer-to-peer.
- **No build step.** The frontend is plain HTML/CSS/JS so the project runs on shared cPanel PHP hosts. No npm, no bundler.
- **Long-poll signaling** is deliberately chosen over WebSockets: shared PHP hosting cannot reliably run a persistent WebSocket server. Long-poll is the pragmatic latency/cost compromise; a WebSocket/SFU upgrade is documented, not implemented.

## 3. Data flow for a two-participant meeting

1. **A** clicks *Start instant meeting* → `api/meetings.php?action=create` → server issues a random 3-4-4 **code** + a **host token** (returned once, stored in `sessionStorage`).
2. **A** and **B** open the join URL → `api/participants.php?action=join` → each gets a **participant token** (hashed server-side) and their `participant_id`.
3. Each browser calls `webrtc.js → acquireLocal()` (camera/mic) and, for every other participant, `ensurePeer()` creates an `RTCPeerConnection`.
4. The **lower id** sends an SDP **offer** via `api/signaling.php?action=send` → the **higher id** answers. ICE candidates flow both ways through the same queue.
5. On `ontrack`, each side renders the remote `MediaStream` into a tile. Media now streams **directly** between A and B over UDP.
6. Presence: each browser heartbeats `participants.php?action=heartbeat` every ~1.5× the polling interval; the participant poller reads the roster and reconciles join/leave/mute/hand state.
7. Chat/reactions/hand/screen go through `api/chat.php` and `api/events.php` (rate-limited, membership-checked).
8. **Leave** marks `left_at`, broadcasts a control event, and `webrtc.js.teardown()` closes every peer + media track.

## 4. Polling & concurrency model

- **Four independent pollers** on the client: signaling (long-poll), participants, chat, events. Each tracks its own `latest_id` to avoid duplicate processing.
- **No overlapping long-polls**: `signaling.js` uses an `inFlight` guard and **exponential backoff** on errors; all timers stop when the participant leaves or the credential expires.
- **Server-side concurrency**: rate limiting uses `SELECT ... FOR UPDATE` inside a transaction; event claim uses `UPDATE ... WHERE delivered_at IS NULL` so a payload is claimed exactly once even under concurrent pollers.
- **Stale cleanup**: `cm_prune_stale()` (per-meeting) and `scripts/cleanup.php` (global cron) mark participants left when `last_seen_at` falls behind `stale_participant_sec` (default 30s).

## 5. Credential & role model

- **Guest host mode (default):** a meeting is hosted by a **host token** (random, issued once at creation, stored as a hash). Holding the token grants host privileges.
- **Participant credential:** a per-participant random `participant_token`, stored as a **hash**. It is the CSRF token for all member operations.
- **Account mode (optional):** `host_user_id` + `users.password_hash` scaffold exists in `includes/auth.php`; the prototype UI uses guest mode.
- **Role comes from the DB row, never from the request.** A client-sent `role` field is ignored; the DB decides who is host.

## 6. Key design decisions & trade-offs

| Decision | Reason | Trade-off |
|---|---|---|
| Mesh P2P | No media server dependency; runs on shared hosting | O(N²) bandwidth; ~12-participant ceiling |
| HTTP long-poll signaling | Works on shared PHP | 1–3s latency vs. WebSocket |
| Hashed tokens in DB | No plaintext credential leak | Rotation invalidates sessions (intended) |
| Rate-limit table + `FOR UPDATE` | Concurrency-safe counter without Redis | A DB round-trip per limited op |
| Local-only recording | No media server; respects privacy | Not a server composite |
| `textContent` chat render | Kills stored XSS | No rich text |

## 7. Upgrade roadmap (documented, not shipped)

1. **SFU media path** (mediasoup/Janus) for 12+ participants — see `docs/WEBRTC_STUN_TURN.md` §5.
2. **WebSocket signaling** (Ratchet/Swoole/Socket.IO) for sub-second latency.
3. **Per-user TURN credentials** and **server-side STT** for reliable remote captions.
4. **Server recording** via a capture node, and **account-based rooms** with waiting-room approval UI.
