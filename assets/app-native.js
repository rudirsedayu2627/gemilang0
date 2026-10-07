/*
 * Jembatan suara untuk aplikasi Android "Gemilang Language Corner".
 *
 * Aplikasi Android-nya (cangkang WebView) menitipkan objek `window.GLCBridge`
 * yang bisa memakai mesin suara bawaan Android:
 *   * SpeechRecognizer -> mengubah suara pengguna menjadi teks;
 *   * TextToSpeech     -> membacakan jawaban AI.
 *
 * Android WebView tidak menyediakan Web Speech API seperti Chrome, jadi tanpa
 * berkas ini tombol "Bicara" dan "Mode Percakapan" tidak akan berfungsi di
 * aplikasi. Berkas ini hanya bertindak bila `window.GLCBridge` ada — dibuka di
 * peramban biasa (Chrome/Firefox/Safari), berkas ini langsung berhenti dan
 * situs berjalan seperti sebelumnya memakai Web Speech API peramban.
 *
 * Kontrak peristiwa dari aplikasi (lihat NativeBridge.java):
 *   {"t":"asr","ev":"mulai"}                          -> onstart
 *   {"t":"asr","ev":"hasil","teks":"…","final":false} -> onresult (sementara)
 *   {"t":"asr","ev":"akhir","teks":"…","final":true}  -> onresult (final)
 *   {"t":"asr","ev":"galat","kode":"…"}               -> onerror
 *   {"t":"asr","ev":"selesai"}                        -> onend
 *   {"t":"tts","ev":"mulai|selesai|galat","id":N}     -> utterance ke-N
 *   {"t":"suara","langs":["id-ID","en-US"]}           -> voiceschanged
 */
(function () {
  'use strict';

  var B = window.GLCBridge;
  if (!B) { return; }
  if (window.__glcNativeSiap) { return; }

  var info = {};
  try {
    var mentah = B.info();
    info = mentah ? JSON.parse(mentah) : {};
  } catch (e) {
    info = {};
  }
  if (!info || (!info.asr && !info.tts)) { return; }
  window.__glcNativeSiap = true;

  var LANGS = (info.langs && info.langs.length) ? info.langs.slice(0) : ['id-ID', 'en-US'];

  function catat(e) {
    try {
      if (window.console && console.warn) { console.warn('[app-native]', e); }
    } catch (x) { /* diabaikan */ }
  }

  function panggil(fn, arg) {
    if (typeof fn !== 'function') { return; }
    try {
      fn(arg);
    } catch (e) {
      catat(e); // kesalahan di pengendali halaman tidak boleh memutus jembatan
    }
  }

  function pasangGlobal(nama, nilai) {
    try {
      window[nama] = nilai;
    } catch (e) { /* diabaikan */ }
    try {
      if (window[nama] !== nilai) {
        Object.defineProperty(window, nama, { value: nilai, configurable: true, writable: true });
      }
    } catch (e) { /* diabaikan */ }
    return window[nama] === nilai;
  }

  /* ================= pembacaan jawaban (TextToSpeech) ================= */

  var ucapan = {};            // id -> utterance
  var urutUcap = 0;
  var idAktif = 0;
  var vokal = [];             // daftar "voice" tiruan untuk halaman
  var pendengarVokal = [];

  function bangunVokal() {
    vokal = [];
    for (var i = 0; i < LANGS.length; i++) {
      vokal.push({
        lang: LANGS[i],
        name: 'Suara Android ' + LANGS[i],
        voiceURI: 'android-' + LANGS[i],
        localService: false,
        'default': i === 0
      });
    }
  }
  bangunVokal();

  function kabariVokal() {
    for (var i = 0; i < pendengarVokal.length; i++) {
      panggil(pendengarVokal[i], { type: 'voiceschanged' });
    }
  }

  function Ucapan(teks) {
    this.text = String(teks == null ? '' : teks);
    this.lang = '';
    this.rate = 1;
    this.pitch = 1;
    this.volume = 1;
    this.voice = null;
    this.onstart = null;
    this.onend = null;
    this.onerror = null;
    this.onboundary = null;
    this.onmark = null;
    this.onpause = null;
    this.onresume = null;
  }
  if (typeof window.SpeechSynthesisUtterance !== 'function') {
    pasangGlobal('SpeechSynthesisUtterance', Ucapan);
  }

  function adaUcapan() {
    for (var k in ucapan) {
      if (Object.prototype.hasOwnProperty.call(ucapan, k)) { return true; }
    }
    return false;
  }

  var synth = {
    speaking: false,
    pending: false,
    paused: false,
    speak: function (u) {
      if (!u) { return; }
      var id = ++urutUcap;
      idAktif = id;
      ucapan[id] = u;
      synth.speaking = true;
      var lang = String(u.lang || (LANGS[0] || 'id-ID'));
      try {
        B.ucap(String(u.text == null ? '' : u.text), lang, id);
      } catch (e) {
        synth.speaking = false;
        delete ucapan[id];
        panggil(u.onerror, { type: 'error', error: 'synthesis-failed', utterance: u });
        panggil(u.onend, { type: 'end', utterance: u });
      }
    },
    cancel: function () {
      idAktif = 0;
      ucapan = {};
      synth.speaking = false;
      synth.pending = false;
      try {
        B.diam();
      } catch (e) { /* diabaikan */ }
    },
    pause: function () { synth.paused = true; },
    resume: function () { synth.paused = false; },
    getVoices: function () { return vokal.slice(0); },
    addEventListener: function (nama, fn) {
      if (nama === 'voiceschanged' && typeof fn === 'function') { pendengarVokal.push(fn); }
    },
    removeEventListener: function (nama, fn) {
      var i = pendengarVokal.indexOf(fn);
      if (i >= 0) { pendengarVokal.splice(i, 1); }
    },
    dispatchEvent: function () { kabariVokal(); return true; }
  };

  function ttsKeJs(d) {
    var id = Number(d.id || 0);
    var u = ucapan[id];
    if (!u) { return; }
    if (d.ev === 'mulai') {
      panggil(u.onstart, { type: 'start', utterance: u, charIndex: 0 });
      return;
    }
    if (d.ev === 'selesai') {
      delete ucapan[id];
      if (!adaUcapan()) { synth.speaking = false; }
      panggil(u.onend, { type: 'end', utterance: u, charIndex: String(u.text == null ? '' : u.text).length });
      return;
    }
    delete ucapan[id];
    synth.speaking = false;
    panggil(u.onerror, { type: 'error', error: 'synthesis-failed', utterance: u });
    panggil(u.onend, { type: 'end', utterance: u });
  }

  function pasangTts() {
    if (!info.tts) { return; }
    var asli = null;
    try {
      asli = window.speechSynthesis || null;
    } catch (e) {
      asli = null;
    }
    if (asli) {
      try {
        asli.speak = synth.speak;
        asli.cancel = synth.cancel;
        asli.pause = synth.pause;
        asli.resume = synth.resume;
        asli.getVoices = synth.getVoices;
        asli.addEventListener = synth.addEventListener;
        asli.removeEventListener = synth.removeEventListener;
      } catch (e) {
        catat(e);
      }
      if (asli.speak === synth.speak) { return; }
    }
    // Tidak ada speechSynthesis, atau metodenya tidak bisa diganti:
    // pasang objek kita sendiri sebagai window.speechSynthesis.
    pasangGlobal('speechSynthesis', synth);
  }

  /* ================= pengenalan suara (SpeechRecognizer) ================= */

  var rekamAktif = null;

  function bikinHasil(teks, final_) {
    var r = [{ transcript: String(teks == null ? '' : teks), confidence: 0.9 }];
    r.isFinal = !!final_;
    return { results: [r], resultIndex: 0 };
  }

  function Rekam() {
    this.lang = '';
    this.continuous = false;
    this.interimResults = false;
    this.maxAlternatives = 1;
    this.onstart = null;
    this.onresult = null;
    this.onerror = null;
    this.onend = null;
    this.onnomatch = null;
    this.onaudiostart = null;
    this.onaudioend = null;
    this.onsoundstart = null;
    this.onsoundend = null;
    this.onspeechstart = null;
    this.onspeechend = null;
    this._jalan = false;
  }

  Rekam.prototype.start = function () {
    if (this._jalan) { throw new Error('InvalidStateError'); }
    // Hanya satu pengenal suara yang boleh memakai mikrofon.
    if (rekamAktif && rekamAktif !== this) {
      rekamAktif._jalan = false;
      rekamAktif = null;
      try { B.dengarBatal(); } catch (e) { /* diabaikan */ }
    }
    this._jalan = true;
    rekamAktif = this;
    try {
      B.dengar(String(this.lang || 'id-ID'));
    } catch (e) {
      rekamAktif = null;
      this._jalan = false;
      panggil(this.onerror, { error: 'service-not-allowed', message: '' });
    }
  };

  Rekam.prototype.stop = function () {
    if (this._jalan) {
      try { B.dengarStop(); } catch (e) { /* diabaikan */ }
    }
  };

  Rekam.prototype.abort = function () {
    this._jalan = false;
    if (rekamAktif === this) { rekamAktif = null; }
    try { B.dengarBatal(); } catch (e) { /* diabaikan */ }
  };

  function asrKeJs(d) {
    var ev = d.ev;
    if (ev === 'mulai') {
      if (rekamAktif) { panggil(rekamAktif.onstart, { type: 'start' }); }
      return;
    }
    if (ev === 'hasil' || ev === 'akhir') {
      if (!rekamAktif) { return; }
      panggil(rekamAktif.onresult, bikinHasil(d.teks, ev === 'akhir' || d.final === true));
      return;
    }
    if (ev === 'galat') {
      var r = rekamAktif;
      if (!r) { return; }
      rekamAktif = null;
      r._jalan = false;
      panggil(r.onerror, { error: d.kode || 'gagal', message: d.kode || '' });
      return;
    }
    if (ev === 'selesai') {
      var r2 = rekamAktif;
      if (!r2) { return; }
      rekamAktif = null;
      r2._jalan = false;
      panggil(r2.onend, { type: 'end' });
    }
  }

  function pasangAsr() {
    if (!info.asr) { return; }
    pasangGlobal('SpeechRecognition', Rekam);
    pasangGlobal('webkitSpeechRecognition', Rekam);
  }

  /* ================= kanal peristiwa dari aplikasi ================= */

  window.__glcNative = {
    terima: function (json) {
      var d;
      try {
        d = JSON.parse(json);
      } catch (e) {
        return;
      }
      if (!d || !d.t) { return; }
      if (d.t === 'asr') { asrKeJs(d); return; }
      if (d.t === 'tts') { ttsKeJs(d); return; }
      if (d.t === 'suara') {
        if (d.langs && d.langs.length) {
          LANGS = d.langs.slice(0);
          bangunVokal();
          kabariVokal();
        }
      }
    }
  };

  pasangTts();
  pasangAsr();
})();
