/* ConnectMeet — settings modal controller.
 * Lives on room.php; opened from lobby or meeting control bar.
 * Handles: device selection, speaker test, caption preference,
 * display-name persistence, and re-acquiring local media after a
 * device change.
 *
 * NOTE: The modal markup is embedded in room.php. This file wires it up.
 */
(function (global) {
  'use strict';

  var modal = document.getElementById('cmSettingsModal');
  if (!modal) return;

  var RTC = global.CMRTC;

  modal.querySelector('#cmSpeakerTest')?.addEventListener('click', function () {
    var AC = global.AudioContext || global.webkitSpeechContext || global.webkitAudioContext;
    if (!AC) { toast('Audio test unavailable in this browser.'); return; }
    var ctx = new AC();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    gain.gain.value = 0.12;
    osc.connect(gain); gain.connect(ctx.destination);
    osc.frequency.value = 523.25; // C5
    osc.start();
    setTimeout(function () {
      osc.stop();
      try { ctx.close(); } catch (e) {}
    }, 400);
  });

  modal.querySelector('[data-close]')?.addEventListener('click', function () {
    modal.setAttribute('hidden', '');
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.setAttribute('hidden', ''); });

  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'cm-toast';
    t.textContent = msg;
    var wrap = document.getElementById('cmMeetingToasts') || document.getElementById('cmToasts');
    if (wrap) { wrap.appendChild(t); setTimeout(function () { t.remove(); }, 3000); }
  }

  // Re-acquire local media after device change and refresh peers.
  document.getElementById('cmSetCam')?.addEventListener('change', function () {
    reAcquire();
  });
  document.getElementById('cmSetMic')?.addEventListener('change', function () {
    reAcquire();
  });
  document.getElementById('cmSetSpk')?.addEventListener('change', function () {
    var out = this.value;
    if (out && HTMLMediaElement.prototype.setSinkId) {
      var vids = document.querySelectorAll('video');
      for (var i = 0; i < vids.length; i++) {
        try { vids[i].setSinkId(out); } catch (e) {}
      }
    }
  });

  function reAcquire() {
    if (!RTC || !RTC.acquireLocal) return;
    var cam = document.getElementById('cmSetCam')?.value;
    var mic = document.getElementById('cmSetMic')?.value;
    var c = { audio: RTC.micOn, video: RTC.camOn };
    if (cam) c.video = { deviceId: { ideal: cam } };
    if (mic) c.audio = { deviceId: { ideal: mic } };
    navigator.mediaDevices.getUserMedia(c).then(function (s) {
      // Swap in the new stream; renegotiate existing peers.
      if (RTC.localStream) RTC.localStream.getTracks().forEach(function (t) { t.stop(); });
      RTC.localStream = s;
      RTC._renegotiateAll && RTC._renegotiateAll();
      var lp = document.getElementById('cmLobbyPreview');
      if (lp) lp.srcObject = s;
      var lv = document.querySelector('.cm-tile[data-pid="me"] video');
      if (lv) lv.srcObject = s;
    }).catch(function (e) {
      toast('Device change failed: ' + (e && e.name || 'unknown'));
    });
  }

  // Keep display name in sync with the settings form.
  modal.addEventListener('change', function () {
    var saveName = modal.querySelector('#cmSetSaveName');
    if (saveName) {
      localStorage.setItem('cm_save_name', saveName.checked ? '1' : '0');
    }
    var capPref = modal.querySelector('#cmSetCaptions');
    if (capPref) {
      localStorage.setItem('cm_captions', capPref.checked ? '1' : '0');
    }
  });

  // On modal open (via meeting.js), populate devices with current labels.
  window.CM_SETTINGS_REFRESH = function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    navigator.mediaDevices.enumerateDevices().then(function (list) {
      fill('#cmSetCam', list.filter(function (d) { return d.kind === 'videoinput'; }), 'Camera');
      fill('#cmSetMic', list.filter(function (d) { return d.kind === 'audioinput'; }), 'Microphone');
      fill('#cmSetSpk', list.filter(function (d) { return d.kind === 'audiooutput'; }), 'Output');
    });
  };

  function fill(sel, items, label) {
    var el = document.querySelector(sel);
    if (!el) return;
    var prev = el.value;
    el.innerHTML = '';
    var d = document.createElement('option');
    d.value = ''; d.textContent = label + ' (default)';
    el.appendChild(d);
    items.forEach(function (dev, i) {
      var o = document.createElement('option');
      o.value = dev.deviceId;
      o.textContent = dev.label || (label + ' ' + (i + 1));
      el.appendChild(o);
    });
    el.value = prev;
  }

  // Open helper (meeting.js calls this).
  window.CM_OPEN_SETTINGS = function () {
    modal.removeAttribute('hidden');
    window.CM_SETTINGS_REFRESH && window.CM_SETTINGS_REFRESH();
    var saveName = modal.querySelector('#cmSetSaveName');
    if (saveName) saveName.checked = localStorage.getItem('cm_save_name') !== '0';
    var capPref = modal.querySelector('#cmSetCaptions');
    if (capPref) capPref.checked = localStorage.getItem('cm_captions') === '1';
  };

  // Expose as a global for meeting.js convenience.
  global.CM_SETTINGS = { open: window.CM_OPEN_SETTINGS };
})(window);
