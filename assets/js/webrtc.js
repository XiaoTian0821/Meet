/* ConnectMeet — WebRTC media engine (native RTCPeerConnection)
 *
 * Responsibilities:
 *   - acquire/tear down local media (audio/video/screen)
 *   - manage a mesh of RTCPeerConnections keyed by participant id
 *   - SDP offer/answer negotiation (perceivable unified plan)
 *   - ICE candidate exchange (queued when connection not yet set)
 *   - active-speaker estimation via AnalyserNode
 *   - screen-share track broadcast with proper renegotiation
 *
 * This module is transport-agnostic: it emits events (onSignal, etc.)
 * that the signaling module wires to the PHP API. No fake streams —
 * every remote tile is a real MediaStream from a peer connection.
 */
(function (global) {
  'use strict';

  var RTC = {
    localStream: null,          // current local MediaStream (camera or screen)
    screenStream: null,        // separate screen stream while presenting
    isSharing: false,
    audioOnly: false,

    peers: {},                 // participantId -> { pc, pendingIce, stream, el }
    myId: null,
    myToken: null,

    // ---- callbacks wired by meeting.js / signaling.js ----
    onRemoteStream: null,      // (participantId, mediaStream) => void
    onPeerGone: null,         // (participantId) => void
    onPeerState: null,        // (participantId, state) => void
    onSignalSend: null,       // (kind, toId, payload) => void
    onSignalPoll: null,       // (events) => void
    onActiveSpeaker: null,    // (participantId|null) => void
    onShareState: null,       // (sharing:boolean) => void
    onLocalState: null,       // ({audioEnabled, videoEnabled}) => void
    onMediaError: null,       // (message:string) => void

    // ---- local media ----
    micOn: true,
    camOn: true,

    _analyser: null,
    _audioCtx: null,
    _analyserTimer: null,

    _init: false
  };

  // ------------------------------------------------------------
  // Media acquisition
  // ------------------------------------------------------------
  RTC.init = function (cfg) {
    RTC.onSignalSend = cfg.onSignalSend;
    RTC.onSignalPoll = cfg.onSignalPoll;
    RTC.onRemoteStream = cfg.onRemoteStream;
    RTC.onPeerGone = cfg.onPeerGone;
    RTC.onPeerState = cfg.onPeerState;
    RTC.onActiveSpeaker = cfg.onActiveSpeaker;
    RTC.onShareState = cfg.onShareState;
    RTC.onLocalState = cfg.onLocalState;
    RTC.onLocalLevel = cfg.onLocalLevel;
    RTC.onMediaError = cfg.onMediaError;
    RTC.iceServers = cfg.iceServers || [{ urls: ['stun:stun.l.google.com:19302'] }];
    RTC.myId = cfg.myId;
    RTC.myToken = cfg.myToken;
    RTC.micOn = !!cfg.micOn;
    RTC.camOn = !!cfg.camOn;
    RTC.audioOnly = !RTC.camOn;
    RTC._init = true;
  };

  /**
   * Acquire local camera+mic per current toggle state.
   * @returns {Promise<MediaStream>}
   */
  RTC.acquireLocal = function () {
    var constraints = { audio: false, video: false };
    if (RTC.micOn) constraints.audio = true;
    if (RTC.camOn) constraints.video = { width: { ideal: 1280 }, height: { ideal: 720 } };
    if (!constraints.audio && !constraints.video) {
      return Promise.resolve(new MediaStream()); // audio/video both off
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (RTC.camOn) RTC.camOn = false;
      RTC.onMediaError && RTC.onMediaError('Camera and microphone access is not supported in this browser or page context.');
      return Promise.resolve(new MediaStream());
    }
    return navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
      RTC.localStream = stream;
      RTC._setupAnalyser(stream);
      return stream;
    }).catch(function (err) {
      // Degrade: try audio-only, then nothing.
      if (err && (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError')) {
        console.warn('[CMRTC] Media permission denied:', err.name);
        if (RTC.camOn) RTC.camOn = false;
        RTC.onMediaError && RTC.onMediaError('Camera access was blocked. Allow camera permission for localhost, then try again.');
        if (RTC.micOn && !RTC.camOn) {
          return navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (s) {
            RTC.localStream = s; RTC._setupAnalyser(s); return s;
          }).catch(function () { RTC.localStream = new MediaStream(); return RTC.localStream; });
        }
        RTC.localStream = new MediaStream();
        return RTC.localStream;
      }
      // DeviceInUse / NotFound / NotFoundError -> audio-only fallback
      if (RTC.camOn) {
        RTC.camOn = false;
        RTC.onMediaError && RTC.onMediaError(
          err && err.name === 'NotFoundError'
            ? 'No camera was found. Connect a camera and try again.'
            : 'The camera could not be started. Check that it is not being used by another app.'
        );
      }
      if (RTC.micOn) {
        return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
          RTC.localStream = s; RTC._setupAnalyser(s); return s;
        }).catch(function () { RTC.localStream = new MediaStream(); return RTC.localStream; });
      }
      RTC.localStream = new MediaStream();
      return RTC.localStream;
    });
  };

  RTC._setupAnalyser = function (stream) {
    try {
      var AC = global.AudioContext || global.webkitAudioContext;
      if (!AC || !stream.getAudioTracks().length) return;
      RTC._audioCtx = new AC();
      var src = RTC._audioCtx.createMediaStreamSource(stream);
      RTC._analyser = RTC._audioCtx.createAnalyser();
      RTC._analyser.fftSize = 512;
      src.connect(RTC._analyser);
      var data = new Float32Array(RTC._analyser.fftSize);
      RTC._analyserTimer = setInterval(function () {
        RTC._analyser.getFloatTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) sum += data[i] * data[i];
        var rms = Math.sqrt(sum / data.length);
        if (rms > 0.05) {
          RTC.onActiveSpeaker && RTC.onActiveSpeaker(RTC.myId);
          clearTimeout(RTC._lastSpeaker);
          RTC._lastSpeaker = setTimeout(function () { RTC.onActiveSpeaker && RTC.onActiveSpeaker(null); }, 1500);
        }
        RTC.onLocalLevel && RTC.onLocalLevel(rms);
      }, 200);
    } catch (e) { /* no analyser support */ }
  };

  // ------------------------------------------------------------
  // Toggles
  // ------------------------------------------------------------
  RTC.toggleMic = function (on) {
    RTC.micOn = !!on;
    if (RTC.localStream) {
      RTC.localStream.getAudioTracks().forEach(function (t) { t.enabled = on; });
    }
    RTC.onLocalState && RTC.onLocalState({ audioEnabled: on, videoEnabled: RTC.camOn });
    return on;
  };

  RTC.toggleCam = function (on) {
    RTC.camOn = !!on;
    RTC.audioOnly = !on;
    if (RTC.localStream) {
      RTC.localStream.getVideoTracks().forEach(function (t) { t.enabled = on; });
    }
    // Re-acquire to add/remove video if needed.
    RTC.acquireLocal().then(function () {
      if (RTC.camOn && (!RTC.localStream || !RTC.localStream.getVideoTracks().length)) {
        RTC.camOn = false;
        RTC.audioOnly = true;
      }
      RTC.onLocalState && RTC.onLocalState({ audioEnabled: RTC.micOn, videoEnabled: RTC.camOn });
      // Push the updated local stream to existing peers via renegotiation.
      RTC._renegotiateAll();
    });
    RTC.onLocalState && RTC.onLocalState({ audioEnabled: RTC.micOn, videoEnabled: on });
    return on;
  };

  RTC._renegotiateAll = function () {
    // For each peer, if we now have a track the peer doesn't (or vice-versa),
    // re-add local tracks and run a negotiation round.
    Object.keys(RTC.peers).forEach(function (pid) {
      var peer = RTC.peers[pid];
      if (!peer || !peer.pc) return;
      var replaced = false;
      // Ensure current local tracks are on the pc.
      if (RTC.localStream) {
        RTC.localStream.getTracks().forEach(function (t) {
          var has = peer.pc.getSenders().some(function (s) { return s.track === t; });
          if (!has) { peer.pc.addTrack(t, RTC.localStream); replaced = true; }
        });
      }
      // Remove senders for tracks no longer in the local stream.
      peer.pc.getSenders().forEach(function (s) {
        if (s.track && RTC.localStream && !RTC.localStream.getTracks().some(function (t) { return t === s.track; })) {
          s.replaceTrack(null); replaced = true;
        }
      });
      if (replaced) RTC._negotiate(peer, pid, true);
    });
  };

  // ------------------------------------------------------------
  // Screen sharing
  // ------------------------------------------------------------
  RTC.startShare = function () {
    if (!navigator.mediaDevices.getDisplayMedia) {
      return Promise.reject(new Error('Screen sharing is not supported in this browser.'));
    }
    return navigator.mediaDevices.getDisplayMedia({ video: { cursor: 'all' }, audio: true }).then(function (stream) {
      RTC.screenStream = stream;
      RTC.isSharing = true;

      var videoTrack = stream.getVideoTracks()[0];
      if (videoTrack) {
        // Replace the camera track on the local stream with the screen track.
        RTC.localStream.getVideoTracks().forEach(function (t) {
          if (t.readyState !== 'ended') t.stop();
        });
        RTC.localStream.addTrack(videoTrack);
        RTC._renegotiateAll();
      }

      // Detect browser-initiated stop (e.g. user clicked "stop sharing").
      var onEnded = function () { RTC.stopShare(true); };
      stream.getVideoTracks().forEach(function (t) { t.addEventListener('ended', onEnded); });

      RTC.onShareState && RTC.onShareState(true);
      return true;
    });
  };

  RTC.stopShare = function (fromBrowser) {
    if (!RTC.isSharing) return Promise.resolve(false);
    RTC.isSharing = false;
    if (RTC.screenStream) {
      RTC.screenStream.getTracks().forEach(function (t) { t.stop(); });
      RTC.screenStream = null;
    }
    // Restore normal camera stream.
    var p;
    if (RTC.camOn && !RTC.audioOnly) {
      p = RTC.acquireLocal(); // brings camera back
    } else {
      p = RTC.acquireLocal(); // audio-only or empty
    }
    return p.then(function () {
      RTC._renegotiateAll();
      RTC.onShareState && RTC.onShareState(false);
      return false;
    });
  };

  // ------------------------------------------------------------
  // Peer connections (mesh)
  // ------------------------------------------------------------

  /**
   * Ensure a peer connection exists for a remote participant and add their
   * tracks locally. Creates an SDP offer for the *lower-id* participant to
   * avoid glare (both sides offering at once).
   */
  RTC.ensurePeer = function (remoteId, remoteName) {
    if (String(RTC.myId) === String(remoteId)) return;
    if (RTC.peers[remoteId]) return RTC.peers[remoteId];

    var pc = new RTCPeerConnection({
      iceServers: RTC.iceServers,
      iceCandidatePoolSize: 2
    });
    var peer = { pc: pc, pendingIce: [], stream: null, el: null, state: 'new', name: remoteName };
    RTC.peers[remoteId] = peer;

    // When remote media arrives, surface it.
    pc.ontrack = function (e) {
      peer.stream = e.streams[0];
      RTC.onRemoteStream && RTC.onRemoteStream(remoteId, peer.stream, peer.name);
    };

    pc.onicecandidate = function (e) {
      if (e.candidate) {
        RTC.onSignalSend && RTC.onSignalSend('ice', remoteId, { candidate: e.candidate });
      }
    };

    pc.onconnectionstatechange = function () {
      peer.state = pc.connectionState;
      RTC.onPeerState && RTC.onPeerState(remoteId, pc.connectionState);
      if (pc.connectionState === 'failed') {
        // Attempt recovery: close and re-negotiate.
        try { pc.close(); } catch (e2) {}
        delete RTC.peers[remoteId];
        RTC.onPeerGone && RTC.onPeerGone(remoteId);
      }
    };

    // Add our local tracks now (may be an empty stream).
    if (RTC.localStream) {
      RTC.localStream.getTracks().forEach(function (t) {
        pc.addTrack(t, RTC.localStream);
      });
    }

    // Who offers? The smaller numeric id offers. This avoids glare in a mesh.
    var iOffer = Number(RTC.myId) < Number(remoteId);
    if (iOffer) {
      RTC._negotiate(peer, remoteId, true);
    }
    // else: wait for the remote to offer; we answer in onSignalPoll handling.

    return peer;
  };

  RTC._negotiate = function (peer, remoteId, offer) {
    if (!offer) {
      // Answer: remote offers, we respond.
      return;
    }
    peer.pc.createOffer().then(function (offer2) {
      peer.pc.setLocalDescription(offer2).then(function () {
        RTC.onSignalSend && RTC.onSignalSend('offer', remoteId, { sdp: peer.pc.localDescription });
      }).catch(function (e) { console.warn('[CMRTC] setLocalDescription(offer) failed', e); });
    }).catch(function (e) { console.warn('[CMRTC] createOffer failed', e); });
  };

  /**
   * Handle a signaling event coming from the poll loop.
   * @param {object} ev {event:'offer'|'answer'|'ice', from, payload}
   */
  RTC.handleSignal = function (ev) {
    var remoteId = ev.from;
    if (!remoteId) return;
    var peer = RTC.ensurePeer(remoteId);
    var pc = peer.pc;

    switch (ev.event) {
      case 'offer':
        // Ensure our local tracks are attached before answering.
        if (RTC.localStream) {
          RTC.localStream.getTracks().forEach(function (t) {
            var has = pc.getSenders().some(function (s) { return s.track === t; });
            if (!has) pc.addTrack(t, RTC.localStream);
          });
        }
        pc.setRemoteDescription(new RTCSessionDescription(ev.payload.sdp)).then(function () {
          RTC.flushPendingIce(peer);
          return pc.createAnswer();
        }).then(function (ans) {
          return pc.setLocalDescription(ans).then(function () {
            RTC.onSignalSend && RTC.onSignalSend('answer', remoteId, { sdp: pc.localDescription });
          });
        }).catch(function (e) { console.warn('[CMRTC] answer failed', e); });
        break;

      case 'answer':
        pc.setRemoteDescription(new RTCSessionDescription(ev.payload.sdp)).then(function () {
          RTC.flushPendingIce(peer);
        })
          .catch(function (e) { console.warn('[CMRTC] setRemoteDescription(answer) failed', e); });
        break;

      case 'ice':
        if (pc.iceGatheringState === 'new' || pc.iceGatheringState === 'gathering') {
          try {
            pc.addIceCandidate(new RTCIceCandidate(ev.payload.candidate)).catch(function (e) {
              // Candidate may arrive before the remote description is set;
              // queue it.
              peer.pendingIce.push(ev.payload.candidate);
            });
          } catch (e) {
            peer.pendingIce.push(ev.payload.candidate);
          }
        } else {
          peer.pendingIce.push(ev.payload.candidate);
        }
        break;
    }
  };

  /**
   * Flush any ICE candidates that arrived before the remote description.
   */
  RTC.flushPendingIce = function (peer) {
    if (!peer || !peer.pendingIce.length) return;
    peer.pendingIce.forEach(function (cand) {
      try { peer.pc.addIceCandidate(new RTCIceCandidate(cand)); } catch (e) {}
    });
    peer.pendingIce = [];
  };

  // ------------------------------------------------------------
  // Cleanup
  // ------------------------------------------------------------
  RTC.removePeer = function (remoteId) {
    var peer = RTC.peers[remoteId];
    if (peer) {
      try { peer.pc.close(); } catch (e) {}
      delete RTC.peers[remoteId];
      RTC.onPeerGone && RTC.onPeerGone(remoteId);
    }
  };

  RTC.teardown = function () {
    // Close all peers.
    Object.keys(RTC.peers).forEach(function (pid) {
      try { RTC.peers[pid].pc.close(); } catch (e) {}
    });
    RTC.peers = {};
    // Stop local tracks.
    if (RTC.localStream) {
      RTC.localStream.getTracks().forEach(function (t) { t.stop(); });
    }
    RTC.localStream = null;
    if (RTC.screenStream) {
      RTC.screenStream.getTracks().forEach(function (t) { t.stop(); });
      RTC.screenStream = null;
    }
    if (RTC._analyserTimer) { clearInterval(RTC._analyserTimer); RTC._analyserTimer = null; }
    if (RTC._audioCtx) { try { RTC._audioCtx.close(); } catch (e) {} RTC._audioCtx = null; }
    RTC.isSharing = false;
  };

  global.CMRTC = RTC;
  global.flushPeerPendingIce = function (remoteId) {
    var peer = RTC.peers[remoteId];
    if (peer) RTC.flushPendingIce(peer);
  };
})(window);
