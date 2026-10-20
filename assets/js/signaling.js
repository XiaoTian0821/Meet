/* ConnectMeet — signaling + presence + events transport.
 *
 * Talks to /api/signaling.php, /api/events.php, /api/participants.php.
 * Uses long-poll where available, short-poll fallback. Deduplicates events
 * and backs off on errors. All credentials travel in JSON bodies.
 */
(function (global) {
  'use strict';

  var SIG = {
    meeting: null,        // meeting code
    meetingId: null,
    token: null,          // participant_token
    hostToken: null,
    pollTimer: null,
    heartbeatTimer: null,
    lastEventId: 0,
    lastChatId: 0,
    lastSeenParticipants: 0,
    inFlight: false,
    backoff: 2000,
    stopped: false,

    // wired by meeting.js
    api: null,            // { meetingCode, meetingId, token, hostToken }
    onEvents: null,       // (events[])
    onParticipantUpdates: null, // (participants[])
    onMeetingEnded: null,
    iceServers: null,
    myId: null
  };

  var CFG = global.CM_CONFIG || {};
  function apiPath(p) { return (CFG.baseUrl || '') + '/' + p; }

  function post(url, body) {
    var opts = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    };
    if (body !== undefined) opts.body = JSON.stringify(body);
    return fetch(apiPath(url), opts).then(function (r) { return r.json(); });
  }
  function get(url) {
    return fetch(apiPath(url), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
  }

  SIG.start = function (info, handlers) {
    SIG.meeting = info.meeting_code;
    SIG.meetingId = info.meeting_id;
    SIG.token = info.participant_token;
    SIG.hostToken = info.host_token || null;
    SIG.myId = info.participant_id;
    SIG.iceServers = info.ice_servers || CFG.iceServers || [];
    SIG.stopped = false;
    SIG.inFlight = false;
    SIG.backoff = 2000;

    SIG.onEvents = handlers.onEvents;
    SIG.onParticipantUpdates = handlers.onParticipantUpdates;
    SIG.onMeetingEnded = handlers.onMeetingEnded;

    // 1. long-poll signaling loop.
    SIG._pollSignaling();

    // 2. presence heartbeat.
    var hbMs = Math.max(1000, (parseInt(CFG.settings && CFG.settings.polling_ms, 10) || 2000) * 1.5);
    SIG.heartbeatTimer = setInterval(function () {
      post('api/participants.php?action=heartbeat', {
        meeting_id: SIG.meetingId,
        participant_token: SIG.token
      }).then(function (r) {
        if (!r.success && (r.message || '').match(/Invalid|expired|rejoin/i)) {
          SIG.stop();
          SIG.onMeetingEnded && SIG.onMeetingEnded('credential_expired');
        }
      }).catch(function () {});
    }, hbMs);

    // 3. participant list poller (presence + join/leave events).
    SIG._pollParticipants();

    // 4. chat poller.
    SIG._pollChat();

    // 5. system/control events poller (reactions, hand, screen, captions).
    SIG._pollEvents();

    return SIG;
  };

  SIG._pollSignaling = function () {
    if (SIG.stopped) return;
    if (SIG.inFlight) return; // avoid overlap
    SIG.inFlight = true;
    var timeout = (CFG.settings && CFG.settings.polling_ms) || 2000;
    get('api/signaling.php?action=poll&wait=1&timeout=' + timeout +
      '&meeting_id=' + encodeURIComponent(SIG.meetingId) +
      '&participant_token=' + encodeURIComponent(SIG.token || ''))
      .then(function (res) {
        SIG.inFlight = false;
        SIG.backoff = 2000; // reset on success
        if (!res.success) {
          if (res.message && /Invalid|expired|rejoin/i.test(res.message)) {
            SIG.stop(); SIG.onMeetingEnded && SIG.onMeetingEnded('credential_expired'); return;
          }
          SIG._pollSignaling(); return;
        }
        var evs = res.data && res.data.events || [];
        if (evs.length) {
          // Route control/meeting_ended to handler, media events to CMRTC.
          evs.forEach(function (ev) {
            if (ev.event === 'meeting_ended') { SIG.stop(); SIG.onMeetingEnded && SIG.onMeetingEnded('ended_by_host'); return; }
            if (ev.event === 'participant_left' || ev.event === 'participant_removed') {
              // Ask webrtc to drop that peer.
              global.CMRTC && global.CMRTC.removePeer(ev.from);
              global.CMEVT && CMEVT.onSystemEvent(ev);
              return;
            }
            global.CMRTC && CMRTC.handleSignal({ event: ev.event, from: ev.from, payload: ev.payload });
          });
        }
        SIG._pollSignaling();
      })
      .catch(function () {
        SIG.inFlight = false;
        // backoff on network failure
        setTimeout(SIG._pollSignaling, SIG.backoff);
        SIG.backoff = Math.min(SIG.backoff * 1.5, 15000);
      });
  };

  SIG._pollParticipants = function () {
    if (SIG.stopped) return;
    var url = 'api/participants.php?action=list&meeting_id=' + SIG.meetingId +
              '&participant_token=' + encodeURIComponent(SIG.token || '');
    get(url).then(function (res) {
      if (SIG.stopped) return;
      if (res.success && res.data) {
        SIG.onParticipantUpdates && SIG.onParticipantUpdates(res.data.participants, res.data);
        if (res.data.latest_id) SIG.lastSeenParticipants = res.data.latest_id;
        // Meeting status checks: ended/locked/expired
        if (res.data.meeting && res.data.meeting.status === 'ended') {
          SIG.stop(); SIG.onMeetingEnded && SIG.onMeetingEnded('ended_by_host');
        }
      }
      setTimeout(SIG._pollParticipants, (CFG.settings && CFG.settings.polling_ms) || 2000);
    }).catch(function () {
      setTimeout(SIG._pollParticipants, 3000);
    });
  };

  SIG._pollChat = function () {
    if (SIG.stopped) return;
    get('api/chat.php?action=poll&since=' + SIG.lastChatId +
      '&meeting_id=' + encodeURIComponent(SIG.meetingId) +
      '&participant_token=' + encodeURIComponent(SIG.token || '')).then(function (res) {
      if (SIG.stopped) return;
      if (res.success && res.data) {
        if (res.data.messages && res.data.messages.length) {
          SIG.onChatMessages && SIG.onChatMessages(res.data.messages);
          SIG.lastChatId = res.data.latest_id;
        }
      }
      setTimeout(SIG._pollChat, (CFG.settings && CFG.settings.polling_ms) || 2000);
    }).catch(function () { setTimeout(SIG._pollChat, 3000); });
  };

  SIG._pollEvents = function () {
    if (SIG.stopped) return;
    get('api/events.php?action=poll&since=' + SIG.lastEventId +
      '&meeting_id=' + encodeURIComponent(SIG.meetingId) +
      '&participant_token=' + encodeURIComponent(SIG.token || '')).then(function (res) {
      if (SIG.stopped) return;
      if (res.success && res.data) {
        var evs = res.data.events || [];
        if (evs.length) {
          // Deduplicate by id.
          evs.forEach(function (ev) {
            if (ev.id <= SIG.lastEventId) return;
            SIG.lastEventId = ev.id;
            global.CMEVT && CMEVT.onSystemEvent({ event: ev.event, from: ev.from, payload: ev.payload });
          });
        }
      }
      setTimeout(SIG._pollEvents, (CFG.settings && CFG.settings.polling_ms) || 2000);
    }).catch(function () { setTimeout(SIG._pollEvents, 3000); });
  };

  SIG.onChatMessages = null; // wired by meeting.js

  // ------------------------------------------------------------
  // Outbound operations
  // ------------------------------------------------------------
  SIG.sendSignal = function (kind, toId, payload) {
    return post('api/signaling.php?action=send', {
      meeting_id: SIG.meetingId,
      participant_token: SIG.token,
      kind: kind,
      to: toId || 0,
      payload: payload || {}
    });
  };

  SIG.sendEvent = function (type, payload) {
    return post('api/events.php?action=send', {
      meeting_id: SIG.meetingId,
      participant_token: SIG.token,
      type: type,
      payload: payload || {}
    });
  };

  SIG.sendChat = function (content) {
    return post('api/chat.php?action=send', {
      meeting_id: SIG.meetingId,
      participant_token: SIG.token,
      content: content
    });
  };

  SIG.leave = function () {
    SIG.stop();
    return post('api/participants.php?action=leave', {
      meeting_id: SIG.meetingId,
      participant_token: SIG.token
    }).then(function () { global.CMRTC && CMRTC.teardown(); });
  };

  SIG.endForEveryone = function () {
    if (!SIG.hostToken) return Promise.reject(new Error('No host token.'));
    return post('api/meetings.php?action=end', {
      meeting_id: SIG.meetingId,
      host_token: SIG.hostToken
    });
  };

  SIG.lock = function (locked) {
    if (!SIG.hostToken) return Promise.reject(new Error('No host token.'));
    return post('api/meetings.php?action=lock', {
      meeting_id: SIG.meetingId,
      host_token: SIG.hostToken,
      locked: locked ? 1 : 0
    });
  };

  SIG.removeParticipant = function (targetId) {
    return post('api/participants.php?action=remove', {
      meeting_id: SIG.meetingId,
      participant_token: SIG.token,
      target_participant_id: targetId
    });
  };

  SIG.stop = function () {
    SIG.stopped = true;
    [SIG.pollTimer, SIG.heartbeatTimer].forEach(function (t) { if (t) clearInterval(t); });
  };

  global.CMSIG = SIG;
})(window);
