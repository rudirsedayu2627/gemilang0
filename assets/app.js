/* Marelan English Course — UI behaviours */
(function () {
  'use strict';

  /* ---------------- theme ---------------- */
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('lms-theme', t); } catch (e) {}
  }
  /* ---------------- sidebar (drawer di layar kecil) ---------------- */
  function sidebarEl() { return document.getElementById('sidebar'); }
  function sidebarOpen() { return !!sidebarEl() && sidebarEl().classList.contains('open'); }
  function sidebarClose() {
    var s = sidebarEl(), o = document.querySelector('.sidebar-overlay');
    if (s) { s.classList.remove('open'); }
    if (o) { o.classList.remove('open'); }
  }
  function sidebarToggle() {
    var s = sidebarEl(), o = document.querySelector('.sidebar-overlay');
    if (!s) { return; }
    s.classList.toggle('open');
    if (o) { o.classList.toggle('open'); }
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-toggle-theme]');
    if (btn) {
      ev.preventDefault();
      applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    }
    var sb = ev.target.closest('[data-toggle-sidebar]');
    if (sb) {
      ev.preventDefault();
      sidebarToggle();
    }
    if (ev.target.closest('[data-close-sidebar]')) {
      sidebarClose();
    }
    var ub = ev.target.closest('[data-toggle-user]');
    if (ub) {
      ev.preventDefault();
      document.querySelector('.user-drop').classList.toggle('open');
    } else if (!ev.target.closest('.user-drop')) {
      var d = document.querySelector('.user-drop');
      if (d) { d.classList.remove('open'); }
    }
    var tab = ev.target.closest('[data-tab]');
    if (tab) {
      ev.preventDefault();
      var scope = tab.closest('.tabs').parentElement;
      tab.closest('.tabs').querySelectorAll('[data-tab]').forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      scope.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
      var target = scope.querySelector('#panel-' + tab.getAttribute('data-tab'));
      if (target) { target.classList.add('active'); }
      /* tabel di dalam tab yang baru terbuka belum bisa diukur saat halaman
         dimuat (lebarnya 0) — pasang ulang lebar kolomnya sekarang. */
      if (typeof tabelPasIsiUlang === 'function') { tabelPasIsiUlang(); }
    }
    var opener = ev.target.closest('[data-modal-open]');
    if (opener) {
      ev.preventDefault();
      var m = document.getElementById(opener.getAttribute('data-modal-open'));
      if (m) { m.classList.add('open'); }
    }
    if (ev.target.classList && ev.target.classList.contains('modal-back')) { ev.target.classList.remove('open'); }
    var closer = ev.target.closest('[data-modal-close]');
    if (closer) { closer.closest('.modal-back').classList.remove('open'); }
    var copy = ev.target.closest('[data-copy]');
    if (copy) {
      ev.preventDefault();
      navigator.clipboard.writeText(copy.getAttribute('data-copy')).then(function () {
        var old = copy.textContent;
        copy.textContent = 'Tersalin!';
        setTimeout(function () { copy.textContent = old; }, 1600);
      });
    }
    var conf = ev.target.closest('[data-confirm]');
    if (conf && !window.confirm(conf.getAttribute('data-confirm'))) { ev.preventDefault(); }
    var print = ev.target.closest('[data-print]');
    if (print) { ev.preventDefault(); window.print(); }
  });

  /* ---------------- drawer: geser layar ke kiri untuk menutup ----------------
     Hanya berlaku di mode layar kecil (<=980px, sama dengan titik henti CSS
     drawer) dan saat drawer memang sedang terbuka. Ambang 60px dan harus lebih
     mendatar daripada menurun, supaya sentuhan menggulir/sekadar menekan tidak
     ikut menutup drawer. */
  (function () {
    var x0 = 0, y0 = 0, track = false;
    function aktif() {
      return sidebarOpen() && window.matchMedia('(max-width:980px)').matches;
    }
    document.addEventListener('touchstart', function (ev) {
      track = aktif() && ev.touches.length === 1;
      if (track) { x0 = ev.touches[0].clientX; y0 = ev.touches[0].clientY; }
    }, { passive: true });
    document.addEventListener('touchend', function (ev) {
      if (!track) { return; }
      track = false;
      var t = ev.changedTouches[0];
      if (!t) { return; }
      var dx = t.clientX - x0, dy = t.clientY - y0;
      if (dx > -60 || Math.abs(dx) <= Math.abs(dy)) { return; }
      sidebarClose();
      // setelah geser, browser masih bisa mengirim 'click' ke elemen di bawah
      // jari (mis. tautan menu) — tahan sekali saja supaya tidak ikut pindah.
      var tahan = function (e) { e.preventDefault(); e.stopPropagation(); };
      document.addEventListener('click', tahan, true);
      setTimeout(function () { document.removeEventListener('click', tahan, true); }, 400);
    }, { passive: true });
  })();

  /* ---------------- table filter ---------------- */
  document.querySelectorAll('[data-filter-input]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      var table = document.querySelector(inp.getAttribute('data-filter-input'));
      if (!table) { return; }
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
      });
    });
  });

  /* ---------------- tabel: lebar kolom mengikuti isi ----------------
     Kolom pendek (angka, tanggal, status, tombol) dipasangkan lebarnya dengan
     isinya, lalu satu kolom teks terpanjang menyerap sisa lebar kartu.
     Kolomnya dipilih dengan MENGUKUR isi tabel (lewat klon di luar layar), jadi
     berlaku untuk semua tabel .data tanpa mengubah halaman satu per satu.
     Tabel yang isinya terlalu padat untuk dipaskan dibiarkan seperti semula
     (kolomnya dirapatkan browser) supaya tidak muncul geser samping di desktop.
     Gaya kelasnya ada di assets/app.css "tabel: lebar kolom mengikuti isi". */
  var TBL_KAKU = 40;      /* selisih lebar alami-minimum yang masih dianggap kaku */
  var TBL_PENDEK = 160;   /* kolom sesempit ini selalu dipaskan dengan isinya */
  var TBL_SEDANG = 120;   /* batas bawah kolom teks yang isinya bisa dilipat */
  var TBL_SISA_MIN = 130; /* sisa minimum untuk kolom teks terpanjang */

  /* Lebar kolom "alami" (selebar isinya) & "minimum" (isinya dilipat). */
  function tabelUkurKolom(tbl) {
    var gaya = window.getComputedStyle(tbl);
    /* sifat warisan yang mempengaruhi pembungkusan teks harus ikut ke klon */
    var waris = ['font', 'letterSpacing', 'wordSpacing', 'lineHeight', 'overflowWrap', 'wordBreak', 'textTransform'];
    var klon = function (lebar) {
      var box = document.createElement('div');
      box.setAttribute('aria-hidden', 'true');
      box.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden';
      var t = tbl.cloneNode(true);
      t.removeAttribute('id');
      t.classList.remove('pas-isi');
      /* buang sisa pengaturan lebar sebelumnya (bukan gaya bawaan halaman) */
      Array.prototype.forEach.call(t.querySelectorAll('th, td'), function (c) {
        c.classList.remove('col-pas');
        c.classList.remove('col-sedang');
        c.classList.remove('col-utama');
        c.style.removeProperty('--tbl-w');
      });
      Array.prototype.forEach.call(t.querySelectorAll('[id]'), function (el) { el.removeAttribute('id'); });
      waris.forEach(function (p) { try { t.style[p] = gaya[p]; } catch (e) {} });
      t.style.tableLayout = 'auto';
      t.style.width = lebar;
      t.style.minWidth = '0';
      t.style.maxWidth = 'none';
      box.appendChild(t);
      document.body.appendChild(box);
      /* baris dengan sel terbanyak = baris terwakili (kepala tabel ikut) */
      var baris = null;
      Array.prototype.forEach.call(t.tBodies, function (tb) {
        Array.prototype.forEach.call(tb.rows, function (tr) {
          if (tr.cells.length > 1 && (!baris || tr.cells.length > baris.cells.length)) { baris = tr; }
        });
      });
      if (t.tHead && t.tHead.rows.length && (!baris || t.tHead.rows[0].cells.length > baris.cells.length)) {
        baris = t.tHead.rows[0];
      }
      var w = [];
      if (baris) {
        Array.prototype.forEach.call(baris.cells, function (c) {
          w.push(Math.ceil(c.getBoundingClientRect().width));
        });
      }
      document.body.removeChild(box);
      return w;
    };
    return { alami: klon('max-content'), minimum: klon('min-content') };
  }

  function tabelPasIsi() {
    Array.prototype.forEach.call(document.querySelectorAll('table.data'), function (tbl) {
      var rows = [];
      Array.prototype.forEach.call(tbl.tBodies, function (tb) {
        Array.prototype.forEach.call(tb.rows, function (tr) { if (tr.cells.length > 1) { rows.push(tr); } });
      });
      if (!rows.length) { return; }
      var jml = 0;
      rows.forEach(function (tr) { if (tr.cells.length > jml) { jml = tr.cells.length; } });
      if (jml < 3) { return; }   /* tabel 2 kolom tidak perlu diatur */

      /* Tabel berisi kolom isian (teks/pilihan) = formulir, bukan tabel data:
         lebarnya dibiarkan seperti semula supaya isiannya tetap lega.
         Kotak centang/radio/tombol tidak dihitung — itu bukan isian lebar. */
      var lentur = [], kendali = [], formulir = false;
      rows.forEach(function (tr) {
        Array.prototype.forEach.call(tr.cells, function (td, idx) {
          if (td.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=image]), select, textarea')) {
            formulir = true;
          }
          if (td.querySelector('.progress, .img-slot')) { lentur[idx] = true; }
          /* kolom tombol/tautan: tombolnya tidak boleh terlipat ke baris kedua */
          if (td.classList.contains('actions') || td.querySelector('.btn, button')) { kendali[idx] = true; }
        });
      });
      if (formulir) { return; }

      var ukur = tabelUkurKolom(tbl);
      var alami = ukur.alami, minim = ukur.minimum;
      jml = Math.min(jml, alami.length);
      if (jml < 3) { return; }

      /* kolom teks terpanjang = yang paling banyak "melipat" saat ruang menyempit */
      var utama = -1, i, best = -1;
      for (i = 0; i < jml; i++) {
        if (lentur[i] || !alami[i]) { continue; }
        var slack = Math.max(0, alami[i] - (minim[i] || 0));
        if (utama === -1 || slack > best || (slack === best && alami[i] > alami[utama])) {
          utama = i;
          best = slack;
        }
      }
      if (utama === -1) { return; }

      /* Semua kolom lain dipaskan dengan isinya; kolom teks terpanjang (utama)
         menyerap sisa lebar kartu. Kalau sisanya jadi terlalu sempit, kolom teks
         paling lebar satu per satu dilepas batasnya (boleh dilipat) sampai muat;
         kalau tetap tidak muat, tabel dibiarkan seperti semula. */
      var pas = [], sedang = [], totalPas = 0, totalLentur = 0;
      for (i = 0; i < jml; i++) {
        if (i === utama || lentur[i] || i >= alami.length) { continue; }
        var a = Math.ceil(alami[i] || 0);
        if (a <= 0) { continue; }
        pas[i] = a;
        totalPas += a;
      }
      for (i = 0; i < jml; i++) {
        if (i !== utama && lentur[i]) { totalLentur += (minim[i] || 0); }
      }

      var wadah = tbl.closest('.table-wrap');
      var tersedia = Math.round((wadah ? wadah.clientWidth : tbl.clientWidth) || 0);
      var longgar = function (idx) {
        return (alami[idx] || 0) - (minim[idx] || 0) > TBL_KAKU && (alami[idx] || 0) > TBL_PENDEK && !kendali[idx];
      };
      while (tersedia > 0 && tersedia - totalPas - totalLentur < TBL_SISA_MIN) {
        var lepas = -1;
        for (i = 0; i < jml; i++) {
          if (!pas[i] || !longgar(i)) { continue; }
          if (lepas === -1 || pas[i] > pas[lepas]) { lepas = i; }
        }
        if (lepas === -1) { return; }   /* tetap tidak muat — biarkan apa adanya */
        totalPas -= pas[lepas];
        sedang[lepas] = Math.min(pas[lepas], TBL_SEDANG);
        totalPas += sedang[lepas];
        pas[lepas] = 0;
      }

      var set = function (c, idx) {
        if (!c || c.hasAttribute('colspan')) { return; }
        if (idx === utama) {
          c.classList.add('col-utama');
        } else if (pas[idx]) {
          c.classList.add('col-pas');
          c.style.setProperty('--tbl-w', pas[idx] + 'px');
        } else if (sedang[idx]) {
          c.classList.add('col-sedang');
        }
      };
      if (tbl.tHead && tbl.tHead.rows.length) {
        Array.prototype.forEach.call(tbl.tHead.rows[0].cells, function (th, idx) { set(th, idx); });
      }
      rows.forEach(function (tr) {
        /* baris dengan sel gabungan (colspan) tidak sejajar dengan kepala tabel */
        var gabung = false;
        Array.prototype.forEach.call(tr.cells, function (td) { if (td.hasAttribute('colspan')) { gabung = true; } });
        if (gabung) { return; }
        Array.prototype.forEach.call(tr.cells, function (td, idx) { set(td, idx); });
      });
      tbl.classList.add('pas-isi');
    });
  }
  /* pasang ulang: lebar kartu berubah (jendela diubah ukuran) atau tabel baru
     terlihat (tab dibuka) — ukuran lama tidak dipakai lagi. */
  function tabelPasIsiUlang() {
    Array.prototype.forEach.call(document.querySelectorAll('table.data'), function (tbl) {
      tbl.classList.remove('pas-isi');
      Array.prototype.forEach.call(tbl.querySelectorAll('th, td'), function (c) {
        c.classList.remove('col-pas');
        c.classList.remove('col-sedang');
        c.classList.remove('col-utama');
        c.style.removeProperty('--tbl-w');
      });
    });
    tabelPasIsi();
  }
  tabelPasIsi();
  var tabelTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(tabelTimer);
    tabelTimer = setTimeout(tabelPasIsiUlang, 300);
  });
  /* font web (Inter) bisa datang setelah halaman tampil — ukur ulang setelahnya
     supaya lebar kolomnya pas dengan huruf yang benar-benar dipakai. */
  if (window.document.fonts && document.fonts.ready && document.fonts.ready.then) {
    document.fonts.ready.then(function () { tabelPasIsiUlang(); });
  }
  if (window.document.fonts && document.fonts.addEventListener) {
    document.fonts.addEventListener('loadingdone', function () { tabelPasIsiUlang(); });
  }

  /* ---------------- auto-submit selects ---------------- */
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  /* ---------------- quiz: select option highlight ---------------- */
  document.querySelectorAll('.opt input').forEach(function (r) {
    var sync = function () {
      var name = r.getAttribute('name');
      document.querySelectorAll('input[name="' + name + '"]').forEach(function (x) {
        x.closest('.opt').classList.toggle('sel', x.checked);
      });
    };
    r.addEventListener('change', sync);
    sync();
  });

  /* ==================================================================
   * MODE OFFLINE ("luring") — aplikasi Android
   * ------------------------------------------------------------------
   * Aplikasi Android (glc-android/) menyimpan halaman quiz & materi beserta
   * asetnya di perangkat, sehingga keduanya bisa dibuka tanpa internet. Yang
   * ditangani berkas ini adalah sisi halamannya:
   *   * pita status "Kamu sedang offline" (ada di setiap halaman);
   *   * jawaban ujian yang diantre di perangkat saat offline, lalu dikirim
   *     otomatis begitu online lagi lewat api-autosave.php + ujian.php — jalur
   *     yang sama dengan pengerjaan biasa, jadi penilaian tetap satu pintu;
   *   * tombol unduh/hapus salinan offline di halaman "Materi Offline".
   *
   * Data disimpan di localStorage (per perangkat, bukan di server) dan hanya
   * berisi jawaban milik pengguna yang sedang masuk. Tanpa jembatan aplikasi
   * (dibuka di peramban biasa) semuanya tetap aman: yang jalan hanya pita
   * offline + antrean jawaban; tombol unduh meminta pengguna memakai aplikasi.
   * ================================================================== */
  var LMSLuring = (function () {
    var K_IDX = 'lms-luring-idx';        // daftar attempt yang punya data lokal
    var K_JWB = 'lms-luring-jwb-';       // attempt -> {qid: nilai kolom jawaban}
    var K_KIRIM = 'lms-luring-kirim-';   // attempt -> {auto:0|1} (menunggu dikirim)
    var K_INFO = 'lms-luring-info-';     // attempt -> {quiz,judul,kursus,tipe:{...}}
    var K_MULAI = 'lms-luring-mulai-';   // attempt -> ms timer perangkat dimulai
    var K_UNDUH = 'lms-luring-unduh';    // salinan offline yang tercatat di perangkat

    var online = (typeof navigator.onLine === 'boolean') ? navigator.onLine : true;
    var sibuk = false;
    var pesanSementara = null;
    var acara = {};              // id permintaan -> pengendali peristiwa jembatan

    function ls(kunci, bawaan) {
      try {
        var t = localStorage.getItem(kunci);
        return t === null ? bawaan : JSON.parse(t);
      } catch (e) { return bawaan; }
    }
    function lsSet(kunci, nilai) {
      try { localStorage.setItem(kunci, JSON.stringify(nilai)); } catch (e) {}
    }
    function lsHapus(kunci) {
      try { localStorage.removeItem(kunci); } catch (e) {}
    }
    function csrf() {
      var m = document.querySelector('meta[name="lms-csrf"]');
      return m ? (m.getAttribute('content') || '') : '';
    }

    /**
     * Alamat LENGKAP (mutlak) sebuah halaman/berkas di situs ini.
     *
     * ⚠️ Aplikasi Android mengambil sendiri halaman yang mau disimpan, dan kode
     * Java-nya memakai `new URL(alamat)` — alamat relatif seperti
     * "materi.php?id=2" DITOLAK (MalformedURLException: no protocol), sehingga
     * unduhan selalu gagal walau halaman sudah menekan tombolnya. Karena itu
     * setiap alamat yang dikirim ke jembatan WAJIB sudah mutlak. Kunci catatan
     * salinan (`lms-luring-unduh`) juga memakai bentuk mutlak ini supaya cocok
     * dengan daftar yang dilaporkan aplikasi (`daftarLuring`).
     */
    function urlPenuh(u) {
      u = String(u == null ? '' : u);
      if (/^https?:\/\//i.test(u)) { return u; }
      try { return new URL(u, location.origin + location.pathname).href; } catch (e) { return u; }
    }

    /* -------------------- penanda attempt & jawaban -------------------- */

    function idx() {
      var a = ls(K_IDX, []);
      return Object.prototype.toString.call(a) === '[object Array]' ? a : [];
    }
    function idxTambah(id) {
      var a = idx();
      if (a.indexOf(String(id)) === -1) { a.push(String(id)); lsSet(K_IDX, a); }
      pita(); // jumlah jawaban yang menunggu ikut tampil di pita status
    }
    function idxBuang(id) {
      var a = idx().filter(function (x) { return x !== String(id); });
      lsSet(K_IDX, a);
    }
    function jawaban(id) {
      var m = ls(K_JWB + id, {});
      return (m && typeof m === 'object') ? m : {};
    }
    function jawabanSimpan(id, qid, nilai) {
      var m = jawaban(id);
      if (nilai === null || nilai === undefined || nilai === '') { delete m[qid]; } else { m[qid] = String(nilai); }
      lsSet(K_JWB + id, m);
      idxTambah(id);
    }
    function jawabanGanti(id, map) {
      var m = {};
      Object.keys(map || {}).forEach(function (k) {
        var v = map[k];
        if (v !== null && v !== undefined && v !== '') { m[k] = String(v); }
      });
      lsSet(K_JWB + id, m);
      if (Object.keys(m).length) { idxTambah(id); }
      return m;
    }
    function info(id) {
      var m = ls(K_INFO + id, {});
      return (m && typeof m === 'object') ? m : {};
    }
    function infoSimpan(id, data) {
      var m = info(id);
      Object.keys(data || {}).forEach(function (k) { m[k] = data[k]; });
      lsSet(K_INFO + id, m);
    }
    function kirimAda(id) {
      var m = ls(K_KIRIM + id, null);
      return (m && typeof m === 'object') ? m : null;
    }
    function kirimTandai(id, auto) {
      lsSet(K_KIRIM + id, { auto: auto ? 1 : 0 });
      idxTambah(id);
    }
    function bersihkan(id) {
      lsHapus(K_JWB + id);
      lsHapus(K_KIRIM + id);
      lsHapus(K_INFO + id);
      lsHapus(K_MULAI + id);
      idxBuang(id);
      pita();
    }
    function mulaiTersimpan(id) {
      var t = parseInt(ls(K_MULAI + id, 0), 10) || 0;
      if (!t) {
        t = Date.now();
        lsSet(K_MULAI + id, t);
      }
      return t;
    }
    function hapusMulai(id) { lsHapus(K_MULAI + id); }

    /** attempt yang sedang dibuka di halaman ini (0 bila bukan halaman ujian). */
    function halamanAttempt() {
      var f = document.getElementById('quiz-form');
      return f ? parseInt(f.getAttribute('data-attempt') || '0', 10) : 0;
    }

    /** Ringkasan antrean (dipakai pita & halaman "Materi Offline"). */
    function antre() {
      return idx().map(function (id) {
        var inf = info(id);
        var m = jawaban(id);
        return {
          attempt: id,
          judul: inf.judul || ('Sesi ujian #' + id),
          kursus: inf.kursus || '',
          quiz: inf.quiz || 0,
          terjawab: Object.keys(m).length,
          kirim: !!kirimAda(id)
        };
      });
    }

    /* -------------------- simpan jawaban dari halaman ujian ---------------- */

    /** Nilai satu soal di dalam form, dalam format kolom attempt_answers. */
    function nilaiSoal(form, qid, tipe) {
      // Tipe matching/kategori/mcma tidak punya satu elemen ber-`data-q`:
      // nilainya disusun dari beberapa kontrol di dalam blok soalnya.
      if (tipe === 'mcma') {
        var pilih = [];
        form.querySelectorAll('input[type=checkbox][data-q="' + qid + '"]').forEach(function (c) {
          if (c.checked) { pilih.push(String(c.value)); }
        });
        return JSON.stringify(pilih);
      }
      if (tipe === 'matching') {
        var ps = {};
        form.querySelectorAll('[data-matching="' + qid + '"] select[data-idx]').forEach(function (s) {
          if (s.value !== '') { ps[s.getAttribute('data-idx')] = s.value; }
        });
        return JSON.stringify(ps);
      }
      if (tipe === 'kategori') {
        var peta = {};
        form.querySelectorAll('[data-kategori="' + qid + '"] select[data-idx]').forEach(function (s) {
          if (s.value !== '') { peta[s.getAttribute('data-idx')] = s.value; }
        });
        return JSON.stringify(peta);
      }
      var el = form.querySelector('[data-q="' + qid + '"]');
      if (!el) { return null; }
      if (el.type === 'radio') {
        var r = form.querySelector('input[type=radio][data-q="' + qid + '"]:checked');
        return r ? String(r.value) : '';
      }
      return String(el.value == null ? '' : el.value);
    }

    /** Simpan seluruh jawaban form ke perangkat (dipakai saat offline). */
    function simpanForm(form, tandaiKirimJuga) {
      var id = form.getAttribute('data-attempt');
      var tipe = {};
      form.querySelectorAll('[data-qp][data-qid]').forEach(function (blok) {
        var qid = blok.getAttribute('data-qid');
        var t = blok.getAttribute('data-tipe') || '';
        tipe[qid] = t;
        jawabanSimpan(id, qid, nilaiSoal(form, qid, t));
      });
      infoSimpan(id, {
        quiz: parseInt(form.getAttribute('data-quiz') || '0', 10),
        judul: form.getAttribute('data-judul') || '',
        kursus: form.getAttribute('data-kursus') || '',
        tipe: tipe
      });
      if (tandaiKirimJuga) { kirimTandai(id, false); }
      pita();
      return id;
    }

    /** Kembalikan jawaban tersimpan ke dalam form (dipakai saat halaman offline dibuka). */
    function terapkanJawaban(form, map) {
      Object.keys(map || {}).forEach(function (qid) {
        var v = map[qid];
        var blok = form.querySelector('[data-qp][data-qid="' + qid + '"]');
        if (!blok) { return; }
        var tipe = blok.getAttribute('data-tipe') || '';
        if (tipe === 'mcma') {
          var arr = [];
          try { arr = JSON.parse(v) || []; } catch (e) { arr = []; }
          form.querySelectorAll('input[type=checkbox][data-q="' + qid + '"]').forEach(function (c) {
            c.checked = arr.indexOf(String(c.value)) > -1;
            c.closest('.opt') && c.closest('.opt').classList.toggle('sel', c.checked);
          });
        } else if (tipe === 'matching') {
          var ps = {};
          try { ps = JSON.parse(v) || {}; } catch (e) { ps = {}; }
          form.querySelectorAll('[data-matching="' + qid + '"] select[data-idx]').forEach(function (s) {
            var k = s.getAttribute('data-idx');
            s.value = (ps[k] === undefined || ps[k] === null) ? '' : String(ps[k]);
          });
        } else if (tipe === 'kategori') {
          var peta = {};
          try { peta = JSON.parse(v) || {}; } catch (e) { peta = {}; }
          form.querySelectorAll('[data-kategori="' + qid + '"] select[data-idx]').forEach(function (s) {
            var k = s.getAttribute('data-idx');
            s.value = (peta[k] === undefined || peta[k] === null) ? '' : String(peta[k]);
          });
        } else {
          var el = form.querySelector('[data-q="' + qid + '"]');
          if (!el) { return; }
          if (el.type === 'radio') {
            form.querySelectorAll('input[type=radio][data-q="' + qid + '"]').forEach(function (r) {
              r.checked = String(r.value) === String(v);
              r.closest('.opt') && r.closest('.opt').classList.toggle('sel', r.checked);
            });
          } else {
            el.value = v == null ? '' : String(v);
          }
        }
      });
    }

    /* -------------------- pengiriman ke server -------------------- */

    /**
     * Susun body untuk server dari jawaban yang tersimpan. Bentuk fieldnya sama
     * persis dengan yang dikirim form ujian, jadi api-autosave.php/ujian.php
     * tidak perlu tahu apa-apa soal mode offline.
     */
    function isiPayload(fd, id, aksi) {
      var jwb = jawaban(id);
      var tipe = info(id).tipe || {};
      fd.append('_csrf', csrf());
      fd.append('attempt_id', id);
      if (aksi === 'submit') {
        fd.append('aksi', 'submit');
        fd.append('auto_submit', (kirimAda(id) && kirimAda(id).auto) ? '1' : '0');
      } else {
        fd.append('question_id', 'all');
      }
      Object.keys(jwb).forEach(function (qid) {
        var v = jwb[qid];
        var t = tipe[qid] || '';
        if (t === 'mcma') {
          var arr = [];
          try { arr = JSON.parse(v) || []; } catch (e) { arr = []; }
          arr.forEach(function (ix) { fd.append('q[' + qid + '][]', String(ix)); });
        } else if (t === 'kategori') {
          var peta = {};
          try { peta = JSON.parse(v) || {}; } catch (e) { peta = {}; }
          Object.keys(peta).forEach(function (ix) { fd.append('kat[' + qid + '][' + ix + ']', String(peta[ix])); });
        } else if (t === 'matching') {
          var ps = {};
          try { ps = JSON.parse(v) || {}; } catch (e) { ps = {}; }
          Object.keys(ps).forEach(function (ix) { fd.append('matching[' + qid + '][' + ix + ']', String(ps[ix])); });
        } else {
          fd.append('q[' + qid + ']', v == null ? '' : String(v));
        }
        // Penanda "soal ini memang ikut dikirim" (MCMA yang dikosongkan pun ikut).
        fd.append('q_present[' + qid + ']', '1');
      });
      return fd;
    }

    function kirimKeServer(id) {
      var fd = isiPayload(new FormData(), id, 'submit');
      return fetch('ujian.php', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' })
        .then(function (r) {
          if (!r || !r.ok || /login\.php/.test(r.url || '')) {
            return { attempt: id, gagal: /login\.php/.test((r && r.url) || '') ? 'masuk' : 'server' };
          }
          bersihkan(id);
          return { attempt: id, kirim: true, judul: info(id).judul || '', hasilUrl: 'ujian-hasil.php?attempt=' + id };
        })
        .catch(function () { tandai(false); return null; });
    }

    function simpanKeServer(id) {
      var fd = isiPayload(new FormData(), id, 'simpan');
      return fetch('api-autosave.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { return { attempt: id, gagal: 'server' }; }
          if (j.done) {
            bersihkan(id);
            return { attempt: id, selesai: true, hasilUrl: 'ujian-hasil.php?attempt=' + id };
          }
          // Jawaban sudah ada di server. Salinan lokal hanya dibuang bila
          // halaman ujiannya tidak sedang dibuka (kalau dibuka, auto-save
          // biasa melanjutkan dari form yang sama).
          if (halamanAttempt() !== parseInt(id, 10)) { bersihkan(id); }
          return { attempt: id, simpan: true };
        })
        .catch(function () { tandai(false); return null; });
    }

    /** Setelah sesi terkirim: tampilkan hasilnya (pindah halaman bila sedang
        membuka halaman ujian itu, atau beri tahu lewat pita status). */
    function tampilkanHasil(r) {
      if (!r || !r.hasilUrl) { return false; }
      if (halamanAttempt() === parseInt(r.attempt, 10)) {
        location.href = r.hasilUrl;
        return true;
      }
      pesan('&#10003; ' + (r.judul ? '"' + r.judul + '" — ' : '') + 'jawabanmu sudah terkirim ke server.', r.hasilUrl, 12000);
      return false;
    }

    /** Kirim semua yang mengantre. cb(hasil[]) dipanggil setelah selesai. */
    function flush(diam, cb) {
      if (!online || sibuk) { if (cb) { cb([]); } return; }
      var daftar = idx();
      if (!daftar.length) { if (cb) { cb([]); } return; }
      sibuk = true;
      pita();
      var hasil = [];
      var n = 0;
      function lanjut() {
        if (n >= daftar.length) {
          sibuk = false;
          pita();
          if (cb) { cb(hasil); }
          return;
        }
        var id = daftar[n++];
        var tugas = kirimAda(id) ? kirimKeServer(id) : simpanKeServer(id);
        tugas.then(function (r) {
          if (r) {
            hasil.push(r);
            if (r.gagal === 'masuk') {
              pesan('Sesi masukmu berakhir — jawabanmu tetap tersimpan di perangkat. Masuk lagi untuk mengirimnya.', '', 12000);
            }
            if (tampilkanHasil(r)) { return; } // halaman sedang pindah: hentikan
          }
          if (!online) { // koneksi putus lagi di tengah jalan
            sibuk = false;
            pita();
            if (cb) { cb(hasil); }
            return;
          }
          lanjut();
        });
      }
      lanjut();
    }

    /* -------------------- pita status -------------------- */

    function pesan(html, tautan, ms, salah) {
      pesanSementara = { html: html, tautan: tautan || '', sampai: Date.now() + (ms || 6000), salah: !!salah };
      pita();
      setTimeout(function () {
        if (pesanSementara && Date.now() >= pesanSementara.sampai) {
          pesanSementara = null;
          pita();
        }
      }, (ms || 6000) + 50);
    }

    function pita() {
      var el = document.getElementById('pita-luring');
      if (!el) { return; }
      var teks = el.querySelector('[data-luring-teks]');
      var aksi = el.querySelector('[data-luring-aksi]');
      var jumlah = idx().length;
      el.classList.remove('aktif', 'mengirim', 'berhasil', 'salah');
      if (aksi) { aksi.innerHTML = ''; }
      if (pesanSementara && Date.now() < pesanSementara.sampai) {
        el.classList.add('aktif', pesanSementara.salah ? 'salah' : 'berhasil');
        if (teks) { teks.innerHTML = pesanSementara.html; }
        if (aksi && pesanSementara.tautan) {
          aksi.innerHTML = '<a class="btn btn-ghost btn-sm" href="' + pesanSementara.tautan + '">Lihat hasil</a>';
        }
        return;
      }
      if (!online) {
        el.classList.add('aktif');
        if (teks) {
          teks.innerHTML = jumlah
            ? 'Kamu sedang offline — <strong>' + jumlah + '</strong> jawaban ujian tersimpan di perangkat ini dan akan terkirim otomatis begitu online.'
            : 'Kamu sedang offline — quiz & materi yang sudah diunduh tetap bisa dibuka.';
        }
        return;
      }
      if (jumlah) {
        el.classList.add('aktif', 'mengirim');
        if (teks) { teks.innerHTML = 'Mengirim ' + jumlah + ' jawaban yang tersimpan di perangkat…'; }
      }
    }

    function tandai(on) {
      on = !!on;
      if (on === online) { pita(); return; }
      online = on;
      pita();
      if (on) { setTimeout(function () { flush(true); }, 400); }
    }

    function pantau() {
      window.addEventListener('online', function () { tandai(true); });
      window.addEventListener('offline', function () { tandai(false); });
      // Jembatan aplikasi Android: memastikan keadaan jaringan akurat (WebView
      // kadang masih mengaku online walau tidak ada internet) + saat jaringan
      // kembali, antrean langsung dikirim tanpa menunggu peristiwa browser.
      window.__glcLuring = {
        jaringan: function (on) { tandai(on); },
        terima: function (json) {
          var d;
          try { d = JSON.parse(json); } catch (e) { return; }
          if (!d || !d.t) { return; }
          if (d.t === 'jaringan') { tandai(d.online); return; }
          if (d.t === 'luring' && acara[d.id]) { acara[d.id](d); }
        }
      };
      // Selagi offline & masih ada antrean: coba kirim berkala (20 detik sekali).
      setInterval(function () {
        if (!online && idx().length) {
          online = true; // coba dulu; gagal -> tandai(false) dari fetch
          flush(true);
        }
      }, 20000);
      // Jaring aman: halaman dibuka saat sudah online tapi masih ada antrean.
      if (online && idx().length) { setTimeout(function () { flush(true); }, 1200); }
    }

    /* -------------------- catatan salinan offline (di perangkat) ---------- */

    function unduhDaftar() {
      var m = ls(K_UNDUH, {});
      return (m && typeof m === 'object') ? m : {};
    }
    function unduhCatat(url, data) {
      var m = unduhDaftar();
      m[url] = data;
      lsSet(K_UNDUH, m);
      return m;
    }
    function unduhHapus(url) {
      var m = unduhDaftar();
      delete m[url];
      lsSet(K_UNDUH, m);
      return m;
    }
    function unduhBersihkan() { lsSet(K_UNDUH, {}); }

    /**
     * Jembatan ke aplikasi Android (lihat glc-android/src/.../NativeBridge.java):
     * simpanLuring(url, judul, id, juga) -> peristiwa {t:'luring',ev:'simpan',...}.
     * Tanpa jembatan (peramban biasa) semua fungsi ini mengembalikan false.
     */
    function jembatan() {
      var B = window.GLCBridge;
      return (B && typeof B.simpanLuring === 'function') ? B : null;
    }
    function didukung() { return !!jembatan(); }

    function jembatanSimpan(url, judul, juga, cb) {
      var B = jembatan();
      if (!B) { if (cb) { cb(false, 'Fitur unduh offline hanya ada di aplikasi Android.'); } return false; }
      // Alamat harus mutlak (lihat urlPenuh) — halaman pendamping juga, dan
      // boleh berupa daftar dipisah koma.
      url = urlPenuh(url);
      juga = String(juga == null ? '' : juga).split(',')
        .map(function (x) { return urlPenuh(x.trim()); })
        .filter(function (x) { return x !== ''; })
        .join(',');
      var id = Math.floor(Math.random() * 1e9);
      acara[id] = function (d) {
        if (d.ev === 'simpan') {
          delete acara[id];
          if (cb) { cb(!!d.ok, d.pesan || ''); }
          return;
        }
        if (d.ev === 'progres' && cb) { cb(null, d.pesan || 'Menyimpan…'); }
      };
      try {
        B.simpanLuring(url, judul || '', id, juga || '');
      } catch (e) {
        delete acara[id];
        if (cb) { cb(false, 'Aplikasi tidak bisa menyimpan berkas ini.'); }
        return false;
      }
      return true;
    }

    function jembatanHapus(url, cb) {
      var B = jembatan();
      if (!B || typeof B.hapusLuring !== 'function') { if (cb) { cb(false, ''); } return false; }
      url = urlPenuh(url);
      var id = Math.floor(Math.random() * 1e9);
      acara[id] = function (d) {
        if (d.ev === 'hapus') {
          delete acara[id];
          if (cb) { cb(!!d.ok, d.pesan || ''); }
        }
      };
      try { B.hapusLuring(url, id); } catch (e) { delete acara[id]; if (cb) { cb(false, ''); } }
      return true;
    }

    /** Daftar salinan yang BENAR-BENAR ada di perangkat menurut aplikasi. */
    function jembatanDaftar(cb) {
      var B = jembatan();
      if (!B || typeof B.daftarLuring !== 'function') { if (cb) { cb(null); } return false; }
      var id = Math.floor(Math.random() * 1e9);
      acara[id] = function (d) {
        if (d.ev === 'daftar') {
          delete acara[id];
          if (cb) { cb(d.items || []); }
        }
      };
      try { B.daftarLuring(id); } catch (e) { delete acara[id]; if (cb) { cb(null); } }
      return true;
    }

    /* -------------------- unduh materi (dipakai 2 halaman) ---------------- */

    var IKO_UNDUH = '<svg class="ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7.5 11 4.5 4.5L16.5 11"/><path d="M4 20h16"/></svg>';
    var IKO_APP = '<a href="GemilangLanguageCorner.apk">di sini</a>';

    function escHtml(t) {
      return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /** Kabar lewat pita status — dipakai halaman yang tidak punya kotak pesan. */
    function kabarPita(html, salah) {
      pesan(html, '', salah ? 9000 : 7000, salah);
    }

    /**
     * Tandai tiap [data-luring-tanda] di dalam `root`: "Tersimpan" bila salinan
     * halaman itu memang sudah tercatat di perangkat. Dipakai halaman "Materi
     * Offline" (baris daftar quiz/materi) dan halaman materi (sebelah tombolnya).
     */
    function tandaGambar(root) {
      var el = root || document;
      var m = unduhDaftar();
      el.querySelectorAll('[data-luring-tanda]').forEach(function (t) {
        var kunci = t.getAttribute('data-luring-tanda') || '';
        var ketemu = null;
        if (kunci.indexOf('quiz:') === 0) {
          // Baris quiz: URL salinannya belum tentu diketahui, jadi dicocokkan
          // lewat id quiz yang tersimpan di catatan unduhan.
          var qid = parseInt(kunci.slice(5), 10);
          Object.keys(m).forEach(function (u) {
            if (!ketemu && (parseInt((m[u] || {}).quiz, 10) || 0) === qid) { ketemu = m[u]; }
          });
        } else {
          // Catatan salinan disimpan dengan alamat mutlak, sedangkan penandanya
          // di halaman biasanya relatif ("materi.php?id=2") — samakan dulu.
          ketemu = m[urlPenuh(kunci)] || null;
        }
        t.innerHTML = ketemu ? '<span class="chip solid">' + IKO_UNDUH + ' Tersimpan</span>' : '';
      });
      return m;
    }

    /**
     * Simpan SATU halaman materi (atau apa pun yang bisa dibaca offline) ke
     * perangkat lewat jembatan aplikasi Android. Dipakai halaman "Materi Offline"
     * dan tombol "Unduh Offline" di halaman materi.
     *
     * `kabar(html, salah)` opsional; bawaannya pita status. Mengembalikan false
     * bila tombolnya tidak bisa dipakai (tanpa internet / tanpa aplikasi), jadi
     * pemanggil tahu unduhannya tidak jadi berjalan.
     */
    function unduhMateri(btn, kabar) {
      kabar = kabar || kabarPita;
      var url = urlPenuh(btn.getAttribute('data-luring-unduh'));
      var judul = btn.getAttribute('data-judul') || url;
      if (!online) {
        kabar('Tidak ada internet. Sambungkan dulu, lalu coba lagi.', true);
        return false;
      }
      if (!didukung()) {
        // Diperiksa sebelum apa pun disimpan; tombolnya tetap ditampilkan di
        // peramban supaya pengguna tahu fiturnya ada dan di mana memasangnya.
        kabar('Bisa dibaca offline lewat aplikasi Android <strong>Gemilang Language Corner</strong> versi 1.2 ke atas. '
          + 'Aplikasinya bisa diunduh ' + IKO_APP + '.', true);
        return false;
      }
      var asli = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = 'Menyimpan…';
      jembatanSimpan(url, judul, 'siswa-offline.php', function (ok, pesanApp) {
        btn.disabled = false;
        btn.innerHTML = asli;
        if (!ok) { kabar(escHtml(pesanApp || 'Gagal menyimpan materi ini.'), true); return; }
        var d = unduhDaftar()[url] || {};
        d.judul = judul;
        d.tipe = 'materi';
        d.waktu = Date.now();
        unduhCatat(url, d);
        kabar('Materi "<strong>' + escHtml(judul) + '</strong>" tersimpan di perangkat — bisa dibaca kapan saja, walau tanpa internet. '
          + 'Berkas besar (video/PDF) tetap perlu internet.', false);
        tandaGambar(document);
      });
      return true;
    }

    function siap() {
      pantau();
      pita();
      return online;
    }

    return {
      siap: siap,
      online: function () { return online; },
      tandai: tandai,
      pita: pita,
      pesan: pesan,
      idx: idx,
      antre: antre,
      flush: flush,
      bufferAttempt: halamanAttempt,
      jawaban: jawaban,
      jawabanSimpan: jawabanSimpan,
      jawabanGanti: jawabanGanti,
      terapkanJawaban: terapkanJawaban,
      nilaiSoal: nilaiSoal,
      simpanForm: simpanForm,
      info: info,
      infoSimpan: infoSimpan,
      kirimAda: kirimAda,
      kirimTandai: kirimTandai,
      bersihkan: bersihkan,
      mulaiTersimpan: mulaiTersimpan,
      hapusMulai: hapusMulai,
      csrf: csrf,
      didukung: didukung,
      simpanLuring: jembatanSimpan,
      hapusLuring: jembatanHapus,
      daftarLuring: jembatanDaftar,
      unduhDaftar: unduhDaftar,
      unduhCatat: unduhCatat,
      unduhHapus: unduhHapus,
      unduhBersihkan: unduhBersihkan,
      unduhMateri: unduhMateri,
      tandaGambar: tandaGambar,
      kabarPita: kabarPita,
      urlPenuh: urlPenuh
    };
  })();
  LMSLuring.siap();

  /* ---------------- quiz engine: timer + autosave ---------------- */
  var quizForm = document.getElementById('quiz-form');
  if (quizForm) {
    var attemptId = quizForm.getAttribute('data-attempt');
    var remaining = parseInt(quizForm.getAttribute('data-remaining') || '0', 10);
    var timerEl = document.getElementById('quiz-timer');
    var noteEl = document.getElementById('autosave-note');
    var submitted = false;
    var dirty = {};              // soal yang punya perubahan belum tersimpan
    var perbaruiNav = null;      // diisi oleh mesin "satu soal per halaman"
    // Mode offline: sesi yang disiapkan lewat api-offline.php belum punya batas
    // waktu di server, jadi timer-nya dihitung di perangkat (lihat di bawah).
    var luring = quizForm.getAttribute('data-luring') === '1';
    var durasiLuring = parseInt(quizForm.getAttribute('data-durasi') || '0', 10);

    function fmt(s) {
      if (s < 0) { s = 0; }
      var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
      var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
      return (h > 0 ? pad(h) + ':' : '') + pad(m) + ':' + pad(x);
    }
    function tampilTimer() {
      if (!timerEl) { return; }
      timerEl.querySelector('span').textContent = fmt(remaining);
      timerEl.classList.toggle('warn', remaining <= 300 && remaining > 60);
      timerEl.classList.toggle('danger', remaining <= 60);
    }
    function tick() {
      if (remaining === null || isNaN(remaining)) { return; }
      remaining--;
      tampilTimer();
      if (remaining <= 0 && !submitted) {
        submitted = true;
        noteEl && (noteEl.textContent = 'Waktu habis — jawaban dikirim otomatis...');
        doSubmit(true);
        return;
      }
      setTimeout(tick, 1000);
    }
    if (luring && durasiLuring > 0) {
      // Sesi yang disiapkan untuk dikerjakan offline belum punya batas waktu di
      // server (lihat api-offline.php): timer-nya dihitung di perangkat, mulai
      // saat quiz pertama kali dibuka, dan tidak diulang saat halaman dimuat ulang.
      var mulaiLuring = LMSLuring.mulaiTersimpan(attemptId);
      remaining = Math.max(0, durasiLuring - Math.round((Date.now() - mulaiLuring) / 1000));
      tampilTimer(); // tampilkan sisanya langsung, jangan menunggu detik pertama
    }
    if (remaining > 0) { setTimeout(tick, 1000); }
    else if (luring && durasiLuring > 0) {
      // Waktu sudah habis sewaktu halaman dibuka: jawaban dikirim apa adanya.
      tampilTimer();
      setTimeout(function () { doSubmit(true); }, 600);
    }

    function payload() {
      var fd = new FormData(quizForm);
      fd.append('attempt_id', attemptId);
      return fd;
    }

    /* ---------------- mode offline: jawaban di perangkat ---------------- */
    function tipeSoal(qid) {
      var blok = quizForm.querySelector('[data-qp][data-qid="' + qid + '"]');
      return blok ? (blok.getAttribute('data-tipe') || '') : '';
    }
    /** Salin jawaban satu soal ke penyimpanan perangkat (dipakai saat offline). */
    function simpanLokal(qid) {
      LMSLuring.jawabanSimpan(attemptId, qid, LMSLuring.nilaiSoal(quizForm, qid, tipeSoal(qid)));
    }
    function catatLuring(pesanHtml) {
      if (!noteEl) { return; }
      noteEl.className = 'autosave-note saved-luring';
      noteEl.innerHTML = pesanHtml;
    }

    // Daftarkan tipe tiap soal + kembalikan jawaban yang tersimpan di perangkat.
    // Sesi yang disiapkan untuk dikerjakan offline dibuka dari salinan di
    // perangkat, jadi jawaban yang sudah diisi harus bisa dilanjutkan.
    var tipeMap = {};
    quizForm.querySelectorAll('[data-qp][data-qid]').forEach(function (b) {
      tipeMap[b.getAttribute('data-qid')] = b.getAttribute('data-tipe') || '';
    });
    LMSLuring.infoSimpan(attemptId, {
      quiz: parseInt(quizForm.getAttribute('data-quiz') || '0', 10),
      judul: quizForm.getAttribute('data-judul') || '',
      kursus: quizForm.getAttribute('data-kursus') || '',
      tipe: tipeMap
    });
    var jawabanTersimpan = LMSLuring.jawaban(attemptId);
    if (Object.keys(jawabanTersimpan).length) {
      LMSLuring.terapkanJawaban(quizForm, jawabanTersimpan);
    }
    if (!LMSLuring.online()) {
      catatLuring(LMSLuring.kirimAda(attemptId)
        ? '&#8681; Jawabanmu sudah tersimpan di perangkat — terkirim otomatis saat online lagi.'
        : '&#8681; Mode offline: jawabanmu disimpan di perangkat dan terkirim otomatis saat online lagi.');
    } else if (Object.keys(jawabanTersimpan).length) {
      catatLuring('&#8681; Jawaban yang tersimpan di perangkat sudah dikembalikan ke halaman ini.');
    }
    function saveQuestion(qid, extra) {
      if (!LMSLuring.online()) {
        // Offline: simpan di perangkat dulu; dikirim otomatis saat online lagi.
        if (qid === 'all') { LMSLuring.simpanForm(quizForm, false); } else { simpanLokal(qid); }
        catatLuring('&#8681; Tersimpan di perangkat — dikirim otomatis saat online');
        return Promise.resolve({ ok: true, luring: true });
      }
      var fd = payload();
      fd.append('question_id', qid);
      if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
      return fetch('api-autosave.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (noteEl && j && j.ok) {
            noteEl.className = 'autosave-note saved-yes';
            noteEl.innerHTML = '&#10003; Jawaban tersimpan otomatis ' + (j.jam || '');
            if (typeof j.remaining === 'number') { remaining = j.remaining; }
            if (j.forced) { submitted = true; doSubmit(true); }
          }
          return j;
        })
        .catch(function () {
          // Koneksi putus di tengah jalan: jangan sampai jawaban hilang.
          LMSLuring.tandai(false);
          if (qid === 'all') { LMSLuring.simpanForm(quizForm, false); } else { simpanLokal(qid); }
          catatLuring('&#8681; Tersimpan di perangkat — dikirim otomatis saat online');
          return null;
        });
    }
    function doSubmit(auto) {
      submitted = true;
      if (!LMSLuring.online()) {
        // Offline: seluruh jawaban + niat "kirim" disimpan di perangkat, lalu
        // dikirim otomatis begitu online (lihat LMSLuring.flush).
        LMSLuring.simpanForm(quizForm, true);
        catatLuring('&#8681; Jawaban tersimpan di perangkat — terkirim otomatis begitu online.');
        LMSLuring.pita();
        return;
      }
      if (document.getElementById('auto-submit')) {
        document.getElementById('auto-submit').value = auto ? '1' : '0';
      }
      HTMLFormElement.prototype.submit.call(quizForm);
    }

    function markSaving() {
      if (noteEl) { noteEl.className = 'autosave-note'; noteEl.textContent = 'Menyimpan...'; }
    }

    // autosave on change (radio / checkbox / select / teks)
    quizForm.querySelectorAll('input[type=radio], input[type=checkbox], select, textarea, input[type=text]').forEach(function (el) {
      var qid = el.getAttribute('data-q');
      if (!qid) { return; }
      var ev = el.tagName === 'TEXTAREA' || el.type === 'text' ? 'input' : 'change';
      var t = null;
      el.addEventListener(ev, function () {
        markSaving();
        dirty[qid] = 1;
        if (perbaruiNav) { perbaruiNav(); }
        clearTimeout(t);
        t = setTimeout(function () {
          // Penanda: pilihan ganda kompleks (MCMA) boleh jadi kosong, jadi
          // server perlu tahu bahwa soal ini memang dikirim (bukan tidak ada).
          var extra = {};
          extra['q_present[' + qid + ']'] = '1';
          delete dirty[qid];
          saveQuestion(qid, extra);
        }, 600);
      });
    });

    // autosave untuk tipe kategori (banyak <select> per soal)
    quizForm.querySelectorAll('[data-kategori]').forEach(function (box) {
      var qid = box.getAttribute('data-kategori');
      var t = null;
      box.addEventListener('change', function () {
        markSaving();
        dirty[qid] = 1;
        if (perbaruiNav) { perbaruiNav(); }
        clearTimeout(t);
        t = setTimeout(function () {
          var extra = {};
          extra['q_present[' + qid + ']'] = '1';
          box.querySelectorAll('select[data-idx]').forEach(function (s) {
            extra['kat[' + qid + '][' + s.getAttribute('data-idx') + ']'] = s.value;
          });
          delete dirty[qid];
          saveQuestion(qid, extra);
        }, 600);
      });
    });

    // matching selects: nilainya ikut terkirim lewat name="matching[<qid>][<idx>]",
    // jadi cukup kirim seluruh form (dulu dikirim sebagai satu string JSON yang
    // justru membuat jawaban matching tersimpan kosong).
    quizForm.querySelectorAll('[data-matching]').forEach(function (box) {
      var qid = box.getAttribute('data-matching');
      var t = null;
      box.addEventListener('change', function () {
        markSaving();
        dirty[qid] = 1;
        if (perbaruiNav) { perbaruiNav(); }
        clearTimeout(t);
        t = setTimeout(function () {
          var extra = {};
          extra['q_present[' + qid + ']'] = '1';
          delete dirty[qid];
          saveQuestion(qid, extra);
        }, 500);
      });
    });

    quizForm.addEventListener('submit', function (ev) {
      if (!LMSLuring.online()) {
        // Tanpa internet: tahan pengiriman, simpan dulu di perangkat.
        ev.preventDefault();
        doSubmit(false);
        return;
      }
      submitted = true;
    });
    window.addEventListener('beforeunload', function (e) {
      if (!submitted && quizForm.getAttribute('data-guard') === '1') {
        e.preventDefault();
        e.returnValue = '';
      }
    });
    setInterval(function () { if (!submitted && noteEl) { saveQuestion('all'); } }, 60000);

    /* ---------------- ujian: satu soal per halaman ---------------- */
    // Setiap soal dirender sebagai satu blok [data-qp]; hanya blok aktif yang
    // terlihat. Tombol Lewati/Lanjutkan/Kembali + warna nomor di navigasi:
    // orange = soal yang sedang dikerjakan, hijau = sudah dijawab, merah = dilewati.
    var pages = Array.prototype.slice.call(quizForm.querySelectorAll('[data-qp]'));
    if (pages.length > 1) {
      var navLinks = Array.prototype.slice.call(quizForm.querySelectorAll('[data-qp-link]'));
      var doneEl = document.getElementById('qp-done');
      var barEl = document.getElementById('qp-bar');
      var countEl = document.getElementById('qp-count');
      var total = pages.length;
      var cur = 0;
      var SKIP_KEY = 'lms-ujian-skip-' + attemptId;
      var POS_KEY = 'lms-ujian-pos-' + attemptId;
      var skipped = {};
      try {
        (JSON.parse(localStorage.getItem(SKIP_KEY) || '[]') || []).forEach(function (id) { skipped[id] = 1; });
      } catch (e) {}

      function qidOf(page) { return page.getAttribute('data-qid'); }
      function iOfQid(qid) {
        for (var i = 0; i < total; i++) { if (qidOf(pages[i]) === String(qid)) { return i; } }
        return -1;
      }
      function isAnswered(page) {
        var els = page.querySelectorAll('input, select, textarea');
        for (var i = 0; i < els.length; i++) {
          var el = els[i];
          if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked) { return true; }
          } else if (el.tagName === 'SELECT') {
            if (el.value !== '') { return true; }
          } else if (String(el.value).trim() !== '') { return true; }
        }
        return false;
      }
      function simpanSkip() {
        try { localStorage.setItem(SKIP_KEY, JSON.stringify(Object.keys(skipped))); } catch (e) {}
      }
      function perbarui() {
        var done = 0, lewat = 0;
        pages.forEach(function (page, i) {
          var dijawab = isAnswered(page);
          if (dijawab) { done++; }
          var aktif = i === cur;
          if (!aktif && !dijawab && skipped[qidOf(page)]) { lewat++; }
          var link = navLinks[i];
          if (!link) { return; }
          // hijau = sudah dijawab, merah = dilewati, orange = sedang dikerjakan
          // (halaman aktif). Halaman aktif yang sudah dijawab: hijau + cincin orange.
          link.classList.toggle('q-now', aktif);
          link.classList.toggle('q-done', dijawab);
          link.classList.toggle('q-skip', !dijawab && !aktif && !!skipped[qidOf(page)]);
        });
        if (doneEl) { doneEl.textContent = done + '/' + total; }
        if (barEl) { barEl.style.width = Math.round(done / total * 100) + '%'; }
        if (countEl) {
          countEl.textContent = done + ' terjawab · ' + lewat + ' dilewati · ' + (total - done) + ' belum dijawab';
        }
      }
      function tampilkan(i, gulir) {
        if (i < 0) { i = 0; }
        if (i > total - 1) { i = total - 1; }
        pages.forEach(function (page, k) {
          if (k === i) { page.removeAttribute('hidden'); } else { page.setAttribute('hidden', ''); }
        });
        cur = i;
        try { localStorage.setItem(POS_KEY, String(i)); } catch (e) {}
        perbarui();
        var hash = '#soal-' + qidOf(pages[i]);
        if (location.hash !== hash) {
          if (window.history && history.replaceState) { history.replaceState(null, '', hash); } else { location.hash = hash; }
        }
        if (gulir) {
          var top = pages[i].getBoundingClientRect().top + window.pageYOffset - 96;
          window.scrollTo({ top: top < 0 ? 0 : top, behavior: 'smooth' });
        }
      }
      // Simpan perubahan yang masih menunggu debounce sebelum pindah soal.
      function simpanPending() {
        for (var qid in dirty) {
          if (dirty.hasOwnProperty(qid)) {
            var extra = {};
            extra['q_present[' + qid + ']'] = '1';
            saveQuestion(qid, extra);
          }
        }
        dirty = {};
      }

      quizForm.addEventListener('click', function (ev) {
        var el = ev.target.closest ? ev.target.closest('[data-qp-prev],[data-qp-next],[data-qp-skip],[data-qp-link]') : null;
        if (!el) { return; }
        ev.preventDefault();
        if (el.hasAttribute('data-qp-skip')) {
          // Dilewati hanya bila memang belum dijawab; soal yang sudah dijawab
          // tidak perlu ditandai merah meski tombol Lewati diklik.
          if (!isAnswered(pages[cur])) {
            skipped[qidOf(pages[cur])] = 1;
            simpanSkip();
          }
          simpanPending();
          if (cur >= total - 1) {
            // Di halaman terakhir "Lewati" berarti mencari soal yang masih
            // kosong (termasuk yang tadi dilewati).
            var target = -1;
            for (var i = 0; i < total; i++) {
              if (i !== cur && !isAnswered(pages[i])) { target = i; break; }
            }
            if (target > -1) {
              tampilkan(target, true);
            } else if (noteEl) {
              noteEl.className = 'autosave-note';
              noteEl.textContent = 'Semua soal sudah dijawab — silakan tekan Selesai & Kirim Jawaban.';
            }
          } else {
            tampilkan(cur + 1, true);
          }
          return;
        }
        simpanPending();
        if (el.hasAttribute('data-qp-prev')) { tampilkan(cur - 1, true); }
        else if (el.hasAttribute('data-qp-next')) { tampilkan(cur + 1, true); }
        else { tampilkan(parseInt(el.getAttribute('data-qp-link'), 10), true); }
      });

      window.addEventListener('hashchange', function () {
        var i = iOfQid((location.hash || '').replace('#soal-', ''));
        if (i > -1 && i !== cur) { tampilkan(i, true); }
      });

      var mulai = 0;
      if (location.hash && iOfQid(location.hash.replace('#soal-', '')) > -1) {
        mulai = iOfQid(location.hash.replace('#soal-', ''));
      } else {
        var pos = parseInt(localStorage.getItem(POS_KEY) || '0', 10);
        if (!isNaN(pos) && pos > 0 && pos < total) { mulai = pos; }
      }
      perbaruiNav = perbarui;
      tampilkan(mulai, false);
    }
  }

  /* ---------------- question builder ---------------- */
  var optWrap = document.getElementById('opsi-wrap');
  if (optWrap) {
    var tipeSel = document.getElementById('tipe-soal');
    var esc = function (t) {
      return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    };
    function opsiRows() {
      var keys = ['A', 'B', 'C', 'D', 'E'];
      var html = '';
      for (var i = 0; i < 5; i++) {
        var g = window.OLD_OPSI_GAMBAR || {};
        var gambar = g[String(i)] || g[i] || '';
        html += '<div class="q-row"><span class="opt-key">' + keys[i] + '</span>'
          + '<div class="opt-cell"><input type="text" name="opsi[' + i + ']" placeholder="Teks pilihan ' + keys[i] + '" value="' + esc((window.OLD_OPSI && window.OLD_OPSI[i]) || '') + '">'
          + imgSlotHtml('opsi_gambar[' + i + ']', gambar) + '</div></div>';
      }
      return html;
    }
    function tampilkan(id, on) {
      var el = document.getElementById(id);
      if (el) { el.style.display = on ? '' : 'none'; }
    }
    function syncWrap(tipe) {
      tampilkan('wrap-jawaban-mc', tipe === 'mc');
      tampilkan('wrap-mcma', tipe === 'mcma');
      tampilkan('wrap-penilaian', tipe === 'mcma');
      tampilkan('wrap-kategori', tipe === 'kategori');
      tampilkan('wrap-tf', tipe === 'tf');
      tampilkan('wrap-fill', tipe === 'fill');
    }
    // Nama kategori di editor langsung tercermin pada pilihan "kategori benar".
    function syncLabelKategori() {
      var nama = [];
      document.querySelectorAll('.kat-nama').forEach(function (inp, i) {
        nama[i] = inp.value.trim();
      });
      document.querySelectorAll('[data-kat-select] option').forEach(function (op) {
        var v = op.value;
        if (v === '') { return; }
        var label = nama[parseInt(v, 10)];
        op.textContent = label ? ('Kategori ' + String.fromCharCode(65 + parseInt(v, 10)) + ' — ' + label) : ('Kategori ' + String.fromCharCode(65 + parseInt(v, 10)));
      });
    }
    function renderOpts() {
      var tipe = tipeSel.value;
      var keys = ['A', 'B', 'C', 'D', 'E'];
      var html = '';
      if (tipe === 'mc') {
        html = opsiRows();
        html += '<div class="form-hint">Tandai jawaban benar dengan memilih huruf di kolom "Jawaban Benar" di bawah. Gambar tiap pilihan boleh diunggah atau ditempel sebagai URL (opsional).</div>';
      } else if (tipe === 'mcma') {
        html = opsiRows();
        html += '<div class="form-hint">Pilihan ganda kompleks: siswa boleh memilih lebih dari satu jawaban. Centang huruf yang benar pada kolom "Kunci MCMA" di bawah. Gambar tiap pilihan opsional.</div>';
      } else if (tipe === 'kategori') {
        html = '<div class="form-hint">Tipe kategori tidak memakai opsi A-E. Isi daftar kategori dan daftar item pada bagian <strong>Kategori</strong> di bawah.</div>';
      } else if (tipe === 'matching') {
        for (var j = 0; j < 5; j++) {
          html += '<div class="q-row"><span class="opt-key">' + (j + 1) + '</span>'
            + '<input type="text" name="pasangan[' + j + '][kiri]" placeholder="Kiri (mis. Book)" value="' + esc((window.OLD_PASANGAN && window.OLD_PASANGAN[j] ? window.OLD_PASANGAN[j].kiri : '') || '') + '">'
            + '<input type="text" name="pasangan[' + j + '][kanan]" placeholder="Kanan (mis. Buku)" value="' + esc((window.OLD_PASANGAN && window.OLD_PASANGAN[j] ? window.OLD_PASANGAN[j].kanan : '') || '') + '"></div>';
        }
      } else if (tipe === 'tf') {
        html = '<div class="form-hint">Soal benar/salah. Jawaban benar dipilih pada kolom di bawah (Benar / Salah).</div>';
      } else if (tipe === 'fill') {
        html = '<div class="form-hint">Tulis jawaban benar di kolom "Jawaban Benar". Bila ada beberapa varian jawaban, pisahkan dengan tanda | (contoh: are|is).</div>';
      } else {
        html = '<div class="form-hint">Soal essay dikoreksi manual oleh guru.</div>';
      }
      optWrap.innerHTML = html;
      syncWrap(tipe);
    }
    tipeSel.addEventListener('change', renderOpts);
    document.querySelectorAll('.kat-nama').forEach(function (inp) {
      inp.addEventListener('input', syncLabelKategori);
    });
    if (!optWrap.innerHTML.trim()) { renderOpts(); }
    syncLabelKategori();
    syncWrap(tipeSel.value);
  }

  /* ---------------- gambar soal: slot pemilih + lightbox ---------------- */
  // Markup slot harus sama dengan gambar_slot() di includes/helpers.php.
  function escH(t) {
    return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }
  function imgSlotHtml(name, value) {
    value = value || '';
    var id = 'gsl-js-' + name.replace(/[^A-Za-z0-9]+/g, '-');
    var prev = value !== ''
      ? '<img src="' + escH(value) + '" alt="Pratinjau gambar" data-lightbox="">'
      : '<span class="img-slot-empty"><em>Belum ada gambar</em></span>';
    return '<div class="img-slot' + (value !== '' ? ' has-img' : '') + '" data-img-slot>'
      + '<input type="hidden" name="' + escH(name) + '" value="' + escH(value) + '" data-img-value>'
      + '<div class="img-slot-prev" data-img-preview>' + prev + '</div>'
      + '<div class="row-gap img-slot-actions">'
      + '<label class="btn btn-ghost btn-sm" for="' + id + '">Upload</label>'
      + '<input type="file" id="' + id + '" class="img-file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" data-img-file>'
      + '<button type="button" class="btn btn-ghost btn-sm" data-img-url-toggle>URL</button>'
      + '<button type="button" class="btn btn-danger btn-sm" data-img-clear' + (value === '' ? ' hidden' : '') + '>Hapus</button>'
      + '</div>'
      + '<input type="text" class="img-url-input" placeholder="https://contoh.com/gambar.png" data-img-url value="' + (/^https?:\/\//i.test(value) ? escH(value) : '') + '" hidden>'
      + '<div class="form-hint img-slot-status" data-img-status hidden></div>'
      + '</div>';
  }
  function imgStatus(slot, pesan, err) {
    var st = slot.querySelector('[data-img-status]');
    if (!st) { return; }
    if (!pesan) { st.hidden = true; st.textContent = ''; st.classList.remove('err'); return; }
    st.hidden = false;
    st.textContent = pesan;
    st.classList.toggle('err', !!err);
  }
  function imgSet(slot, url) {
    url = url || '';
    var val = slot.querySelector('[data-img-value]');
    var prev = slot.querySelector('[data-img-preview]');
    var urlInput = slot.querySelector('[data-img-url]');
    var clear = slot.querySelector('[data-img-clear]');
    if (val && val.value === url) { return; } // tidak ada perubahan: hindari render ulang
    if (val) { val.value = url; }
    if (prev) {
      prev.innerHTML = url !== ''
        ? '<img src="' + escH(url) + '" alt="Pratinjau gambar" data-lightbox="">'
        : '<span class="img-slot-empty"><em>Belum ada gambar</em></span>';
    }
    if (urlInput) { urlInput.value = url; }
    slot.classList.toggle('has-img', url !== '');
    if (clear) { clear.hidden = url === ''; }
  }
  function imgUpload(slot, file) {
    if (!file) { return; }
    var form = slot.closest('form');
    var csrf = form ? form.querySelector('input[name="_csrf"]') : document.querySelector('input[name="_csrf"]');
    var fd = new FormData();
    fd.append('_csrf', csrf ? csrf.value : '');
    fd.append('file', file);
    slot.classList.add('proses');
    imgStatus(slot, 'Mengunggah ' + file.name + '...', false);
    fetch('api-media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Balasan server tidak dikenali.' }; }); })
      .then(function (d) {
        slot.classList.remove('proses');
        if (!d || !d.ok) {
          imgStatus(slot, (d && d.error) || 'Upload gagal.', true);
          return;
        }
        imgSet(slot, d.url);
        imgStatus(slot, 'Gambar siap. Klik "Simpan Soal" untuk menerapkan.', false);
      })
      .catch(function () {
        slot.classList.remove('proses');
        imgStatus(slot, 'Upload gagal: koneksi terputus.', true);
      });
  }
  document.addEventListener('change', function (ev) {
    var f = ev.target.closest('[data-img-file]');
    if (f && f.files && f.files[0]) {
      imgUpload(f.closest('[data-img-slot]'), f.files[0]);
      f.value = '';
    }
    var u = ev.target.closest('[data-img-url]');
    if (u) {
      var slot = u.closest('[data-img-slot]');
      imgSet(slot, u.value.trim());
      imgStatus(slot, u.value.trim() ? 'URL gambar dipakai.' : '', false);
    }
  });
  document.addEventListener('input', function (ev) {
    var u = ev.target.closest('[data-img-url]');
    if (u) { imgSet(u.closest('[data-img-slot]'), u.value.trim()); }
  });
  document.addEventListener('click', function (ev) {
    var tog = ev.target.closest('[data-img-url-toggle]');
    if (tog) {
      var inp = tog.closest('[data-img-slot]').querySelector('[data-img-url]');
      if (inp) {
        inp.hidden = !inp.hidden;
        if (!inp.hidden) { inp.focus(); }
      }
      return;
    }
    var clr = ev.target.closest('[data-img-clear]');
    if (clr) {
      var slot = clr.closest('[data-img-slot]');
      imgSet(slot, '');
      imgStatus(slot, 'Gambar dihapus. Klik "Simpan Soal" untuk menerapkan.', false);
      return;
    }
    // lightbox: klik gambar ber-atribut data-lightbox untuk memperbesar
    var thumb = ev.target.closest('img[data-lightbox]');
    if (thumb) {
      ev.preventDefault();
      var lb = document.getElementById('lightbox');
      if (lb) {
        lb.querySelector('img').src = thumb.getAttribute('src');
        lb.classList.add('open');
      }
      return;
    }
    if (ev.target.closest('#lightbox')) {
      document.getElementById('lightbox').classList.remove('open');
    }
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      var lb = document.getElementById('lightbox');
      if (lb) { lb.classList.remove('open'); }
    }
  });

  /* ---------------- Tanya AI di halaman materi ----------------
     Kartu chat dirender oleh ai_kartu_html() (includes/ai.php) saat fitur
     dinyalakan admin DAN kunci API sudah diisi. Semua panggilan AI lewat
     api-ai-chat.php — kunci API tidak pernah sampai ke browser. */
  var AI_SPARKLE = '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M11 3.5 12.7 8l4.5 1.7-4.5 1.7L11 15.9 9.3 11.4 4.8 9.7 9.3 8z"/>'
    + '<path d="M17.8 14.4l.8 2.1 2.1.8-2.1.8-.8 2.1-.8-2.1-2.1-.8 2.1-.8z"/></svg>';

  function aiEsc(t) {
    return String(t == null ? '' : t)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  // Markdown ringan — harus sama dengan ai_teks_html() di includes/ai.php.
  /**
   * Rapikan teks jawaban AI: buang penanda format markdown (tanda bintang dll)
   * supaya yang tampil/dibacakan hanya kalimat biasa. Permintaan pemilik:
   * "Rapikan balasan dari AI, jangan pakai *".
   *
   * Padanannya di server: ai_rapikan_teks() (includes/ai.php) — KEDUANYA harus
   * menghasilkan teks yang sama (kalau salah satu diubah, ubah yang lain juga).
   * Jawaban dari server sudah dirapikan di sana; fungsi ini jaring pengaman
   * untuk teks yang datang dari jalur lain (dan untuk riwayat lama).
   */
  function aiRapi(t) {
    var s = String(t == null ? '' : t).replace(/\r\n?/g, '\n');
    s = s.replace(/^[ \t]*```[^\n]*$/gm, '');                     // pagar kode
    s = s.replace(/^[ \t]*([-*_])[ \t]*(\1[ \t]*){2,}$/gm, '');   // garis pemisah
    // WAJIB sebelum penekanan: pada "* *contoh*" bintang pertama adalah penanda
    // butir, dan kalau penekanan jalan lebih dulu penanda itu ikut termakan.
    s = s.replace(/^[ \t]*[*\-+][ \t]+/gm, '• ');                 // butir daftar
    for (var i = 0; i < 4; i++) {
      var sebelum = s;
      s = s.replace(/\*{1,3}([^*\n]+?)\*{1,3}/g, '$1');
      s = s.replace(/_{2,3}([^_\n]+?)_{2,3}/g, '$1');
      if (s === sebelum) { break; }
    }
    s = s.replace(/^[ \t]*#{1,6}[ \t]*/gm, '');                   // judul markdown
    s = s.replace(/[*`]/g, '');                                   // sisa bintang/backtick
    s = s.replace(/[ \t]+$/gm, '');
    s = s.replace(/[ \t]{2,}/g, ' ');
    s = s.replace(/\n{3,}/g, '\n\n');
    s = s.replace(/^[ \t]+/gm, '');
    return s.replace(/^\s+|\s+$/g, '');
  }
  /**
   * Teks -> HTML. $rapi = true (bawaan) untuk jawaban AI; $rapi = false untuk
   * pesan siswa, yang ditampilkan APA ADANYA (hanya di-escape) karena itu tulisan
   * mereka sendiri — tanda bintangnya tidak diubah menjadi huruf miring.
   */
  function aiFmt(t, rapi) {
    if (rapi === false) {
      return aiEsc(String(t == null ? '' : t)).replace(/\n/g, '<br>');
    }
    var s = aiEsc(aiRapi(t));
    s = s.replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    s = s.replace(/^#{1,4}\s*(.+)$/gm, '<strong>$1</strong>');
    s = s.replace(/^[ \t]*[-*]\s+/gm, '• ');
    return s.replace(/\n/g, '<br>');
  }
  function aiJam() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* ---- suara: bicara ke AI & jawaban AI dibacakan ----
     Memakai kemampuan peramban (Web Speech API) — SpeechRecognition mengubah
     suara pengguna menjadi teks, speechSynthesis membacakan jawaban AI. Tidak
     ada kunci API/layanan berbayar tambahan, dan tidak ada rekaman suara yang
     dikirim ke server kita. Bahasa suara bisa dipilih pengguna (ID <-> EN) dan
     pilihannya disimpan di peramban. Pengendali suara dirender PHP
     (ai_suara_kontrol_html di includes/ai.php) hanya bila admin menyalakannya. */
  var AI_VOLUME = '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M4 9.5h3L11 6v12l-4-3.5H4z"/><path d="M15 9.2a4 4 0 0 1 0 5.6"/><path d="M17.9 6.6a8 8 0 0 1 0 10.8"/></svg>';
  var AI_VOLUME_OFF = '<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M4 9.5h3L11 6v12l-4-3.5H4z"/><path d="m15.8 9.6 4.4 4.8"/><path d="m20.2 9.6-4.4 4.8"/></svg>';
  var AI_TAG = { id: 'id-ID', en: 'en-US' };
  /* Mode percakapan (live) — angka-angka penyetelannya, semuanya di sini:
     - AI_JEDA_DIAM   : berapa lama keheningan menandai "kalimat selesai" (bawaan;
                        nilai sebenarnya dari pengaturan admin di data-ai-jeda);
     - AI_MUNDUR_AWAL : hitungan mundur SEBELUM mikrofon dianggap mendengarkan
                       pada giliran pertama (memberi waktu pengguna bersiap,
                       termasuk saat peramban meminta izin mikrofon);
     - AI_MUNDUR_LANJUT: idem untuk giliran-giliran berikutnya;
     - AI_MIN_KATA / AI_MIN_HURUF / AI_KATA_BERISIK: penyaring suara berisik —
       kalimat yang terlalu pendek atau berupa gumaman tidak akan dikirim;
     - AI_TUNGGU_BUNYI: batas menunggu suara AI benar-benar mulai terdengar
       (bila peramban menahannya, mode percakapan dihentikan dengan pesan, bukan
       menunggu tanpa suara);
     - AI_MAKS_KOSONG : berapa giliran tanpa suara jelas sebelum sesi dijeda. */
  var AI_JEDA_DIAM = 3000;
  var AI_MUNDUR_AWAL = 3;
  var AI_MUNDUR_LANJUT = 2;
  var AI_MIN_KATA = 2;
  var AI_MIN_HURUF = 5;
  var AI_KATA_BERISIK = ['hmm', 'hm', 'mmm', 'mm', 'eh', 'oh', 'uh', 'um', 'uhm', 'ah', 'aaa', 'tes', 'test', 'testing', '123'];
  var AI_TUNGGU_BUNYI = 2500;
  var AI_MAKS_KOSONG = 3;
  var AI_KUNCI_BAHASA = 'lms-ai-suara-bahasa';
  var AI_KUNCI_BUNYI = 'lms-ai-suara';
  var aiBunyiDukung = ('speechSynthesis' in window) && (typeof window.SpeechSynthesisUtterance === 'function');
  var aiRekamDukung = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
  // true bila kartu memang punya pengendali suara (dipakai aiMsgHtml untuk
  // menambahkan tombol "bacakan" pada jawaban AI yang baru masuk).
  var aiSuaraTampil = false;
  var aiVokal = [];

  function aiLS(kunci, nilai) {
    try {
      if (nilai === undefined) { return window.localStorage.getItem(kunci); }
      window.localStorage.setItem(kunci, nilai);
    } catch (e) { /* mode privat / localStorage penuh: pakai bawaan */ }
    return null;
  }
  function aiMuatVokal() {
    if (!aiBunyiDukung) { return; }
    try { aiVokal = window.speechSynthesis.getVoices() || []; } catch (e) { aiVokal = []; }
  }
  if (aiBunyiDukung) {
    aiMuatVokal(); // daftar suara kadang baru terisi setelah event voiceschanged
    if (typeof window.speechSynthesis.addEventListener === 'function') {
      window.speechSynthesis.addEventListener('voiceschanged', aiMuatVokal);
    }
  }
  // Teks yang dibacakan tidak perlu penanda markdown/simbol apa pun — pakai
  // perapian yang sama dengan yang dipakai menampilkan jawaban (aiRapi).
  function aiBacaBersih(t) {
    var s = aiRapi(t);
    s = s.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1');
    s = s.replace(/\s+/g, ' ').trim();
    return s.slice(0, 3000);
  }
  function aiVokalPilih(tag) {
    if (!aiVokal.length) { aiMuatVokal(); }
    var dasar = tag.slice(0, 2).toLowerCase(), samaTag = null, samaBahasa = null, bagus = null;
    for (var i = 0; i < aiVokal.length; i++) {
      var v = aiVokal[i];
      var l = String(v.lang || '').toLowerCase().replace('_', '-');
      if (l.indexOf(dasar) !== 0) { continue; }
      var awal = l === tag.toLowerCase();
      // Suara "jaringan" (mis. Google) biasanya jauh lebih enak didengar
      // daripada suara lokal yang serak/berisik — dahulukan bila ada.
      var jernih = !v.localService || /google/i.test(String(v.name || ''));
      if (jernih && !bagus) { bagus = v; }
      if (awal && !samaTag) { samaTag = v; }
      if (!samaBahasa) { samaBahasa = v; }
    }
    return bagus || samaTag || samaBahasa;
  }
  /**
   * Perangkat ini punya suara untuk bahasa tsb? Bila tidak, teks dibacakan dengan
   * suara bahasa lain sehingga terdengar kacau ("bunyi berisik") — pemanggil
   * memakainya untuk memberi tahu pengguna. Daftar suara yang belum termuat
   * dianggap "ada" supaya tidak menuduh tanpa bukti.
   */
  function aiVokalAda(kode) {
    if (!aiVokal.length) { aiMuatVokal(); }
    if (!aiVokal.length) { return true; }
    return !!aiVokalPilih(AI_TAG[kode] || AI_TAG.id);
  }
  function aiDiam() {
    aiUcapGen++; // batalkan juga rantai potongan kalimat yang masih menunggu
    if (aiBunyiDukung) { try { window.speechSynthesis.cancel(); } catch (e) { /* diabaikan */ } }
  }
  // Nomor urut bacaan: dipakai supaya rantai potongan kalimat lama tidak
  // "hidup lagi" setelah dibatalkan atau digantikan bacaan baru.
  var aiUcapGen = 0;
  // Pecah jadi potongan pendek (peramban, khususnya Chrome, kadang berhenti di
  // tengah kalimat yang sangat panjang; ini juga membuat mode percakapan terasa
  // lebih hidup karena AI "berbicara" bertahap).
  function aiPotong(teks, maks) {
    maks = maks || 220;
    var kalimat = String(teks).match(/[^.!?…]+[.!?…]*\s*/g) || [String(teks)];
    var hasil = [], kini = '';
    for (var i = 0; i < kalimat.length; i++) {
      var k = kalimat[i];
      if (kini && (kini + k).length > maks) { hasil.push(kini.replace(/\s+/g, ' ').trim()); kini = ''; }
      if (k.length > maks) {
        var sisa = k;
        while (sisa.length > maks) {
          var pos = sisa.lastIndexOf(' ', maks);
          if (pos < maks * 0.5) { pos = maks; }
          hasil.push(sisa.slice(0, pos).replace(/\s+/g, ' ').trim());
          sisa = sisa.slice(pos);
        }
        kini = sisa;
      } else {
        kini += k;
      }
    }
    if (kini.replace(/\s+/g, ' ').trim() !== '') { hasil.push(kini.replace(/\s+/g, ' ').trim()); }
    return hasil;
  }
  // Bacakan potongan ke-i; setelah potongan terakhir, panggil opsi.selesai.
  // $st = status bersama seluruh potongan ({mulai:false}); bila peramban tidak
  // juga mulai bersuara dalam AI_TUNGGU_BUNYI, opsi.gagalMulai dipanggil supaya
  // pemanggil bisa berhenti daripada menunggu tanpa bunyi.
  function aiUcapBagian(list, i, kode, gen, opsi, st) {
    if (gen !== aiUcapGen) { return false; } // sudah dibatalkan/diganti
    if (i >= list.length) { if (opsi.selesai) { opsi.selesai(); } return true; }
    var tag = AI_TAG[kode] || AI_TAG.id;
    var timerMulai = null;
    if (!st.mulai) {
      timerMulai = setTimeout(function () {
        if (gen !== aiUcapGen || st.mulai) { return; }
        aiUcapGen++; // hentikan rantai ini
        try { window.speechSynthesis.cancel(); } catch (e) { /* diabaikan */ }
        if (opsi.gagalMulai) { opsi.gagalMulai(); }
      }, AI_TUNGGU_BUNYI);
    }
    try {
      var u = new window.SpeechSynthesisUtterance(list[i]);
      u.lang = tag;
      u.rate = 1; u.pitch = 1; u.volume = 1;
      var v = aiVokalPilih(tag);
      if (v) { u.voice = v; }
      var lanjut = false;
      function sekali() {
        if (lanjut || gen !== aiUcapGen) { return; }
        lanjut = true;
        aiUcapBagian(list, i + 1, kode, gen, opsi, st);
      }
      u.onstart = function () {
        st.mulai = true;
        if (timerMulai) { clearTimeout(timerMulai); timerMulai = null; }
      };
      u.onend = sekali;
      u.onerror = sekali;
      window.speechSynthesis.speak(u);
      // Jaring aman: sebagian peramban tidak memicu onend (mis. tab berpindah
      // atau teks sangat panjang) sehingga mode percakapan bisa "macet".
      setTimeout(sekali, Math.min(30000, list[i].length * 110 + 4000));
    } catch (e) {
      if (opsi.selesai) { opsi.selesai(); }
      return false;
    }
    return true;
  }
  /**
   * Bacakan teks. $opsi (opsional):
   *   selesai   — dipanggil setelah seluruh bacaan habis;
   *   gagalMulai — dipanggil bila peramban tidak juga mulai bersuara.
   * Argumen lama (fungsi langsung) tetap diterima sebagai $opsi.selesai.
   */
  function aiUcap(teks, kode, opsi) {
    if (typeof opsi === 'function') { opsi = { selesai: opsi }; }
    opsi = opsi || {};
    if (!aiBunyiDukung) { if (opsi.selesai) { opsi.selesai(); } return false; }
    var bersih = aiBacaBersih(teks);
    if (!bersih) { if (opsi.selesai) { opsi.selesai(); } return false; }
    try { window.speechSynthesis.cancel(); } catch (e) { /* diabaikan */ }
    aiUcapGen++;
    return aiUcapBagian(aiPotong(bersih), 0, kode, aiUcapGen, opsi, { mulai: false });
  }
  /**
   * Gabungkan teks final + teks sementara TANPA pengulangan.
   * Banyak peramban mengirim ulang hasil yang sama (baik hasil final maupun
   * potongan sementara yang isinya sudah termasuk kalimat final), sehingga
   * kalau ditumpuk begitu saja kata-katanya terlihat terketik berulang-ulang.
   * Fungsi ini membuang tumpang-tindih kata di sambungannya.
   */
  function aiGabungTeks(teksFinal, teksBaru) {
    var f = String(teksFinal == null ? '' : teksFinal).replace(/\s+/g, ' ').trim();
    var b = String(teksBaru == null ? '' : teksBaru).replace(/\s+/g, ' ').trim();
    if (b === '') { return f; }
    if (f === '') { return b; }
    if (f === b) { return f; } // peramban mengirim ulang hal yang sama
    var kataF = f.toLowerCase().split(' ');
    var kataB = b.toLowerCase().split(' ');
    var maks = Math.min(kataF.length, kataB.length);
    for (var n = maks; n > 0; n--) {
      var cocok = true;
      for (var i = 0; i < n; i++) {
        if (kataF[kataF.length - n + i] !== kataB[i]) { cocok = false; break; }
      }
      if (cocok) {
        // Potongan awal teks baru sudah ada di ekor teks final: sisanya saja.
        var sisa = b.split(' ').slice(n).join(' ').trim();
        return sisa === '' ? f : (f + ' ' + sisa);
      }
    }
    return f + ' ' + b;
  }
  /**
   * Kalimat dari pengenal suara layak dikirim sebagai pertanyaan?
   * Menyaring suara berisik: gumaman ("hmm", "eh"), dengung, angka sendirian,
   * atau satu kata yang terlalu pendek. Dipakai mode percakapan supaya tidak ada
   * pertanyaan sampah yang terkirim sebelum pengguna benar-benar bicara.
   */
  function aiLayakKirim(teks) {
    var bersih = String(teks == null ? '' : teks).toLowerCase()
      .replace(/[^\p{L}\p{N}\s]/gu, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (bersih === '') { return false; }
    var kata = bersih.split(' ');
    if (AI_KATA_BERISIK.indexOf(bersih) !== -1) { return false; }
    if (kata.length >= AI_MIN_KATA && !(kata.length === 1 && AI_KATA_BERISIK.indexOf(kata[0]) !== -1)) { return true; }
    return kata[0].length >= AI_MIN_HURUF;
  }

  /* Pengendali suara satu kartu. Mengembalikan {ucap, diam, otomatis, kode}
     atau null bila kartu tidak punya pengendali suara sama sekali. */
  function aiSuaraInit(card, kirim, status) {
    var bar = card.querySelector('[data-ai-suara]');
    if (!bar) { return null; }
    var micBtn = bar.querySelector('[data-ai-mic]');
    var micLabel = bar.querySelector('[data-ai-mic-label]');
    var dengarBtn = bar.querySelector('[data-ai-dengar]');
    var dengarIkon = bar.querySelector('[data-ai-dengar-ikon]');
    var dengarLabel = bar.querySelector('[data-ai-dengar-label]');
    var chip = bar.querySelectorAll('[data-ai-lang]');
    var ta = card.querySelector('[data-ai-form] textarea[name="pesan"]');
    var thread = card.querySelector('[data-ai-thread]');
    var kode = card.getAttribute('data-ai-bahasa') === 'en' ? 'en' : 'id';
    var simpanBahasa = aiLS(AI_KUNCI_BAHASA);
    if (simpanBahasa === 'id' || simpanBahasa === 'en') { kode = simpanBahasa; }
    var simpanBunyi = aiLS(AI_KUNCI_BUNYI);
    var otomatis = (simpanBunyi === '1' || simpanBunyi === '0')
      ? simpanBunyi === '1'
      : card.getAttribute('data-ai-suara-otomatis') === '1';
    var mendengar = false, dibatalkan = false, akhir = '', gagal = false, terkirim = false, rec = null;

    function tandaMic(aktif) {
      if (!micBtn) { return; }
      micBtn.classList.toggle('listening', !!aktif);
      micBtn.setAttribute('aria-pressed', aktif ? 'true' : 'false');
      if (micLabel) { micLabel.textContent = aktif ? 'Berhenti' : 'Bicara'; }
    }
    function tandaBunyi() {
      if (dengarIkon) { dengarIkon.innerHTML = otomatis ? AI_VOLUME : AI_VOLUME_OFF; }
      if (dengarLabel) { dengarLabel.textContent = 'Suara AI: ' + (otomatis ? 'Nyala' : 'Mati'); }
      if (dengarBtn) {
        dengarBtn.setAttribute('aria-pressed', otomatis ? 'true' : 'false');
        dengarBtn.classList.toggle('nyala', otomatis);
      }
    }
    function tandaBahasa() {
      for (var i = 0; i < chip.length; i++) {
        var aktif = chip[i].getAttribute('data-ai-lang') === kode;
        chip[i].classList.toggle('solid', aktif);
        chip[i].setAttribute('aria-pressed', aktif ? 'true' : 'false');
      }
    }
    tandaBunyi();
    tandaBahasa();

    if (!aiBunyiDukung) {
      if (dengarBtn) { dengarBtn.hidden = true; }
      var tombolBaca = card.querySelectorAll('[data-ai-speak]');
      for (var q = 0; q < tombolBaca.length; q++) { tombolBaca[q].hidden = true; }
    }
    if (aiRekamDukung && micBtn) {
      micBtn.hidden = false;
      micBtn.addEventListener('click', function () { if (mendengar) { micBerhenti(); } else { micMulai(); } });
    }
    if (dengarBtn) {
      dengarBtn.addEventListener('click', function () {
        otomatis = !otomatis;
        aiLS(AI_KUNCI_BUNYI, otomatis ? '1' : '0');
        tandaBunyi();
        if (otomatis) {
          status('Setiap jawaban AI akan dibacakan.', false);
        } else {
          aiDiam();
          if (live) {
            // Mode percakapan memang bertumpu pada jawaban bersuara.
            liveHenti('Mode percakapan diakhiri karena suara AI dimatikan.');
          } else {
            status('Jawaban AI tidak lagi dibacakan.', false);
          }
        }
      });
    }
    for (var c = 0; c < chip.length; c++) {
      chip[c].addEventListener('click', function (ev) {
        kode = ev.currentTarget.getAttribute('data-ai-lang') === 'en' ? 'en' : 'id';
        aiLS(AI_KUNCI_BAHASA, kode);
        tandaBahasa();
        aiDiam();
        if (live) {
          // Ganti bahasa: pengenal suara dihidupkan ulang dengan bahasa baru
          // (giliran yang sama, tanpa hitungan mundur ulang).
          liveTutupRekam();
          if (!liveSibuk) {
            liveFase = 'dengar';
            liveMulaiDengar();
          }
          status('Bahasa suara: ' + (kode === 'en' ? 'English' : 'Indonesia') + '.', false);
          return;
        }
        status('Bahasa suara: ' + (kode === 'en' ? 'English' : 'Indonesia') + '.', false);
      });
    }
    // Tombol "bacakan" pada tiap jawaban AI (riwayat lama maupun baru).
    if (thread) {
      thread.addEventListener('click', function (ev) {
        var el = ev.target;
        if (!el || typeof el.closest !== 'function') { return; }
        var btn = el.closest('[data-ai-speak]');
        if (!btn || btn.hidden) { return; }
        ev.preventDefault();
        var bubble = btn.closest('.ai-bubble');
        var teksEl = bubble ? bubble.querySelector('.ai-text') : null;
        if (aiUcap(teksEl ? teksEl.innerText : '', kode)) {
          status('Membacakan jawaban AI...', false);
        } else {
          status('Peramban ini belum bisa membacakan teks.', true);
        }
      });
    }

    /* ---- Mode Percakapan (live): seperti menelepon AI ----
       Giliran percakapan: hitungan mundur dulu ("mulai bicara dalam 3...") supaya
       TIDAK ADA yang terkirim sebelum pengguna siap, lalu kalimatnya otomatis
       dikirim, jawabannya dibacakan, dan giliran berikutnya dimulai lagi —
       sampai pengguna menekan "Akhiri".

       Aturan yang menjaga percakapannya tetap enak dipakai (konstanta di atas):
       - suara yang masuk SEBELUM giliran dimulai diabaikan (fase izin/siap);
       - suara berisik/gumaman tidak dikirim (aiLayakKirim());
       - hanya hasil FINAL yang dikirim; teks sementara cuma ditampilkan;
       - mikrofon DIMATIKAN selama AI menjawab & berbicara (mencegah gema);
       - bila suara AI tidak juga terdengar, atau pengirimannya gagal, sesi
         dihentikan dengan pesan — bukan diulang terus tanpa arti.
       Server tetap menerima satu pertanyaan per giliran (mode=suara). */
    var liveBtn = bar.querySelector('[data-ai-live]');
    var liveBilah = card.querySelector('[data-ai-live-bar]');
    var liveTitik = card.querySelector('[data-ai-live-dot]');
    var liveStatusEl = card.querySelector('[data-ai-live-status]');
    var liveKataEl = card.querySelector('[data-ai-live-kata]');
    var liveStopBtn = card.querySelector('[data-ai-live-stop]');
    var live = false;           // sesi hidup?
    var liveSibuk = false;      // sedang menunggu jawaban AI / membacakan
    var liveFase = 'istirahat'; // istirahat | izin | siap | dengar | jawab | baca
    var liveRekam = null;
    var liveJeda = null;        // timer keheningan
    var liveMundur = null;      // interval hitungan mundur
    var liveTeks = '', liveAntara = '', liveAntaraSebelum = '';
    var liveSesiFinal = 0;      // indeks hasil final terakhir yang sudah direkam
                                // (mencegah kata yang sama masuk dua kali)
    var liveJedaMs = AI_JEDA_DIAM; // = data-ai-jeda (pengaturan admin)
    var liveKosong = 0;         // giliran berturut-turut tanpa suara jelas
    var livePeriksa = 0;        // berapa kali menunggu teks sementara jadi final

    function liveBilahTeks(teks, nada) {
      if (liveStatusEl) { liveStatusEl.textContent = teks; }
      if (liveTitik) { liveTitik.className = 'ai-live-dot' + (nada ? ' ' + nada : ''); }
    }
    function liveKata(t) {
      if (!liveKataEl) { return; }
      liveKataEl.textContent = t || '';
      liveKataEl.hidden = !t;
    }
    /**
     * Lupakan teks giliran yang sedang berjalan. liveSesiFinal direset karena
     * indeks hasil pengenalan suara mulai dari nol lagi untuk kalimat berikutnya
     * (dan sebagian peramban memang mengirim ulang dari indeks 0).
     */
    function liveLupakanTeks() {
      liveTeks = ''; liveAntara = ''; liveAntaraSebelum = ''; livePeriksa = 0;
      liveSesiFinal = 0;
      liveKata('');
    }
    function liveTanda(on) {
      live = !!on;
      if (liveBtn) {
        liveBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        liveBtn.classList.toggle('aktif', !!on);
        var lbl = liveBtn.querySelector('[data-ai-live-label]');
        if (lbl) { lbl.textContent = on ? 'Percakapan Aktif' : 'Mode Percakapan'; }
      }
      if (liveBilah) { liveBilah.hidden = !on; }
      // Tombol "Bicara" satu kali disembunyikan selama mode percakapan aktif
      // supaya tidak ada dua pengenal suara yang berebut mikrofon.
      if (micBtn) { micBtn.hidden = !!on || !aiRekamDukung; }
      if (!on) { liveKata(''); }
    }
    function liveTutupRekam() {
      if (liveJeda) { clearTimeout(liveJeda); liveJeda = null; }
      if (liveMundur) { clearInterval(liveMundur); liveMundur = null; }
      if (liveRekam) {
        try {
          // Lepas semua penangan supaya hasil suara yang telat datang tidak
          // memicu pengiriman setelah giliran/sesi berakhir.
          liveRekam.onend = null;
          liveRekam.onresult = null;
          liveRekam.onerror = null;
          liveRekam.onstart = null;
          liveRekam.abort();
        } catch (e) { /* diabaikan */ }
        liveRekam = null;
      }
    }
    // $lanjut = melanjutkan giliran yang sama (mikrofon tertutup sendiri oleh
    // peramban): teks yang sudah terdengar tidak direset dan tidak ada hitungan
    // mundur ulang.
    function liveBukaMikrofon(mundurAwal, lanjut) {
      if (!live || liveSibuk) { return; }
      if (!window.isSecureContext) { liveHenti('Mode percakapan butuh alamat aman (https).', true); return; }
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      try { liveRekam = new SR(); } catch (e) { liveHenti('Mikrofon tidak bisa dibuka di peramban ini.', true); return; }
      liveRekam.lang = AI_TAG[kode] || AI_TAG.id;
      liveRekam.interimResults = true;
      liveRekam.continuous = true;
      liveRekam.maxAlternatives = 1;
      if (!lanjut) {
        liveLupakanTeks();
        liveFase = 'izin'; // menunggu mikrofon benar-benar hidup (termasuk izin peramban)
        liveBilahTeks('Menyiapkan mikrofon...', 'siap');
      }
      // Objek pengenalan suara baru = indeks hasil mulai dari nol lagi (teks yang
      // sudah terdengar di giliran yang sama tetap dipertahankan).
      liveSesiFinal = 0;
      liveRekam.onstart = function () {
        // Hitungan mundur baru mulai setelah mikrofon benar-benar hidup, jadi
        // saat peramban menampilkan permintaan izin pun pengguna tetap punya
        // waktu bersiap dan belum ada suara yang dikirim.
        if (liveFase === 'izin') { liveHitungMundur(mundurAwal); }
      };
      liveRekam.onresult = function (ev) {
        if (liveFase !== 'dengar') { return; } // sebelum giliran dimulai: diabaikan
        var antara = '';
        for (var i = 0; i < ev.results.length; i++) {
          var r = ev.results[i];
          var t = (r && r[0]) ? String(r[0].transcript || '') : '';
          if (r && r.isFinal) {
            // Hanya hasil final yang BELUM pernah direkam yang ditambahkan.
            // Peramban sering mengirim ulang hasil final yang sama (atau mulai
            // lagi dari indeks 0) — tanpa penjaga ini kata yang sama terketik
            // berulang-ulang. aiGabungTeks() jadi pengaman kedua terhadap
            // pengulangan isi di sambungannya.
            if (i >= liveSesiFinal) {
              liveSesiFinal = i + 1;
              if (t.replace(/[\s\p{P}\p{S}]/gu, '') !== '') {
                liveTeks = aiGabungTeks(liveTeks, t);
              }
            }
          } else {
            antara += ' ' + t; // teks sementara DIBANGUN ULANG tiap event (tidak ditumpuk)
          }
        }
        liveAntara = antara.replace(/\s+/g, ' ').trim();
        // Tampilan real-time juga digabung tanpa pengulangan: sebagian peramban
        // menyertakan lagi kalimat yang sudah final di dalam teks sementara.
        liveKata(aiGabungTeks(liveTeks, liveAntara));
        // Beri tahu bahwa pengguna masih boleh melanjutkan bicaranya (pengiriman
        // otomatis baru terjadi setelah benar-benar berhenti bicara).
        liveBilahTeks('Mendengarkan... lanjutkan bicaramu sampai selesai.', 'dengar');
        if (liveJeda) { clearTimeout(liveJeda); }
        // Selama pengguna masih bersuara, timer ini terus ditunda — jadi jeda
        // sejenak di tengah kalimat tidak akan memotong pembicaraannya.
        liveJeda = setTimeout(liveSelesaiBicara, liveJedaMs);
      };
      liveRekam.onerror = function (ev) {
        var k = ev && ev.error ? String(ev.error) : '';
        if (k === 'aborted') { return; }   // ditutup sendiri oleh mode percakapan
        if (k === 'no-speech') { return; } // onend akan menghidupkan lagi
        var pesan = 'Pengenal suara gagal.';
        if (k === 'not-allowed' || k === 'service-not-allowed') { pesan = 'Izin mikrofon ditolak. Izinkan akses mikrofon lalu tekan Mode Percakapan lagi.'; }
        else if (k === 'audio-capture') { pesan = 'Mikrofon tidak ditemukan di perangkat ini.'; }
        else if (k === 'network') { pesan = 'Pengenal suara butuh koneksi internet. Periksa jaringanmu lalu coba lagi.'; }
        liveHenti(pesan, true);
      };
      liveRekam.onend = function () {
        // Peramban menutup sesi pengenalan suara saat hening: hidupkan lagi
        // selama sesi masih hidup dan tidak sedang menunggu AI.
        if (!live || liveSibuk) { return; }
        if (liveFase === 'dengar' || liveFase === 'siap' || liveFase === 'izin') {
          setTimeout(function () {
            if (live && !liveSibuk && (liveFase === 'dengar' || liveFase === 'siap' || liveFase === 'izin')) {
              liveBukaMikrofon(0, true);
            }
          }, 250);
        }
      };
      try { liveRekam.start(); } catch (e) { liveHenti('Mikrofon tidak bisa dibuka. Coba lagi.', true); return; }
    }
    function liveHitungMundur(detik) {
      if (!live) { return; }
      if (liveMundur) { clearInterval(liveMundur); liveMundur = null; }
      liveFase = 'siap';
      liveLupakanTeks();
      var sisa = Math.max(0, Math.round(detik));
      if (sisa <= 0) { liveMulaiDengar(); return; }
      liveBilahTeks('Bersiap... mulai bicara dalam ' + sisa, 'siap');
      liveMundur = setInterval(function () {
        sisa--;
        if (!live) { clearInterval(liveMundur); liveMundur = null; return; }
        if (sisa <= 0) {
          clearInterval(liveMundur); liveMundur = null;
          liveMulaiDengar();
        } else {
          liveBilahTeks('Bersiap... mulai bicara dalam ' + sisa, 'siap');
        }
      }, 1000);
    }
    function liveMulaiDengar() {
      if (!live || liveSibuk) { return; }
      liveFase = 'dengar';
      liveLupakanTeks();
      liveBilahTeks('Mendengarkan... silakan bicara.', 'dengar');
      if (!liveRekam) { liveBukaMikrofon(0, true); } // mikrofon sudah tertutup: buka lagi
    }
    // Dipanggil saat pengguna berhenti bicara (jeda senyap). Di sinilah suara
    // berisik disaring: yang tidak layak TIDAK dikirim sama sekali.
    function liveSelesaiBicara() {
      if (liveJeda) { clearTimeout(liveJeda); liveJeda = null; }
      if (!live || liveSibuk || liveFase !== 'dengar') { return; }
      var hasilFinal = liveTeks.replace(/\s+/g, ' ').trim();
      var antara = liveAntara.replace(/\s+/g, ' ').trim();
      // Masih ada teks sementara? Beri kesempatan menjadi final dulu.
      if (antara !== '' && livePeriksa < 2) {
        livePeriksa++;
        liveAntaraSebelum = antara;
        liveJeda = setTimeout(liveSelesaiBicara, 700);
        return;
      }
      var tanya = hasilFinal;
      if (tanya === '' && antara !== '' && antara === liveAntaraSebelum && aiLayakKirim(antara)) {
        tanya = antara; // pengenal suara tak pernah menutup kalimat: pakai teks sementara yang stabil
      }
      liveLupakanTeks();
      if (!aiLayakKirim(tanya)) {
        liveKosong++;
        if (liveKosong >= AI_MAKS_KOSONG) {
          liveHenti('Belum ada suara yang jelas terdengar, jadi mode percakapan dijeda. Tekan Mode Percakapan lagi bila sudah siap.', true);
          return;
        }
        liveBilahTeks('Belum terdengar jelas — coba bicara lagi.', 'siap');
        return; // tetap mendengarkan; giliran yang sama belum dikirim
      }
      liveKosong = 0;
      liveKirimKeAi(tanya);
    }
    function liveKirimKeAi(tanya) {
      if (!live || liveSibuk) { return; }
      liveSibuk = true;
      liveFase = 'jawab';
      liveTutupRekam(); // mikrofon mati selama AI menjawab (mencegah gema)
      liveKata('');
      liveBilahTeks('AI sedang menyusun jawaban...', 'jawab');
      kirim(tanya, {
        mode: 'suara',
        tanpaBaca: true, // mode percakapan membacakan sendiri (butuh jeda antar giliran)
        onJawab: function (balasan) {
          if (!live) { liveSibuk = false; return; }
          liveFase = 'baca';
          liveBilahTeks('AI menjawab dengan suara...', 'jawab');
          aiUcap(balasan, kode, {
            selesai: function () {
              liveSibuk = false;
              if (!live) { return; }
              liveGiliranBerikut();
            },
            gagalMulai: function () {
              // Suara AI tidak terdengar (mis. ditahan kebijakan peramban):
              // hentikan sesi supaya tidak "menunggu tanpa bunyi" terus-menerus.
              liveSibuk = false;
              liveHenti('Suara AI tidak bisa diputar di perangkat ini, jadi mode percakapan dihentikan. Jawaban AI tetap ada di teks di bawah.', true);
            }
          });
        },
        onGagal: function (pesan) {
          liveSibuk = false;
          if (!live) { return; }
          liveHenti(pesan || 'Pertanyaan tidak terkirim, jadi mode percakapan dihentikan.', true);
        }
      });
    }
    function liveGiliranBerikut() {
      if (!live) { return; }
      liveBilahTeks('Bersiap...', 'siap');
      liveBukaMikrofon(AI_MUNDUR_LANJUT, false);
    }
    function liveMulai() {
      if (live) { return; }
      if (!aiRekamDukung || !aiBunyiDukung) {
        status('Peramban ini belum mendukung mode percakapan suara.', true);
        return;
      }
      if (!window.isSecureContext) {
        status('Mode percakapan suara butuh alamat aman (https).', true);
        return;
      }
      if (!otomatis) { // mode ini memang bertumpu pada jawaban bersuara
        otomatis = true;
        aiLS(AI_KUNCI_BUNYI, '1');
        tandaBunyi();
      }
      liveSibuk = false;
      liveKosong = 0;
      livePeriksa = 0;
      liveTutupRekam();
      aiDiam();
      liveTanda(true);
      liveBilahTeks('Menyiapkan mikrofon...', 'siap');
      var pesanMulai = 'Mode percakapan hidup. Setelah hitungan selesai, bicara seperti biasa — kalimatmu dikirim otomatis dan jawabannya dibacakan. Suara berisik tidak akan terkirim. Tekan Akhiri untuk berhenti.';
      if (!aiVokalAda(kode)) {
        // Perangkat tanpa suara bahasa ini membacakan teks dengan suara bahasa
        // lain sehingga terdengar kacau — beri tahu pengguna (dan tidak dianggap galat).
        pesanMulai += ' Catatan: perangkat ini tidak punya suara ' + (kode === 'en' ? 'bahasa Inggris' : 'bahasa Indonesia')
          + ', jadi bacaan AI bisa terdengar kurang jelas. Coba pilih bahasa suara yang lain (tombol Indonesia/English).';
      }
      status(pesanMulai, false);
      liveBukaMikrofon(AI_MUNDUR_AWAL, false);
    }
    function liveHenti(pesan, galat) {
      if (!live) { return; }
      liveTutupRekam();
      aiDiam();
      liveSibuk = false;
      liveKosong = 0;
      liveLupakanTeks();
      liveFase = 'istirahat';
      liveTanda(false);
      status(pesan || 'Mode percakapan diakhiri. Kamu bisa mengetik atau menekan Mode Percakapan lagi.', !!galat);
    }

    // Tombol Mode Percakapan hanya bisa dipakai bila peramban mendukung DUA API
    // (mendengar + bersuara) — kalau tidak, tombolnya tetap tersembunyi.
    if (liveBtn && aiRekamDukung && aiBunyiDukung) {
      liveBtn.hidden = false;
      liveBtn.addEventListener('click', function () { if (live) { liveHenti(); } else { liveMulai(); } });
    }
    if (liveStopBtn) {
      liveStopBtn.addEventListener('click', function () { liveHenti(); });
    }
    // Lama menunggu pengguna selesai bicara = pengaturan admin (data-ai-jeda).
    (function () {
      var ms = parseInt(card.getAttribute('data-ai-jeda') || '', 10);
      if (!isNaN(ms) && ms >= 1000 && ms <= 10000) { liveJedaMs = ms; }
    })();

    function micBerhenti() {
      dibatalkan = true;
      if (rec) { try { rec.stop(); } catch (e) { /* diabaikan */ } }
    }
    function micMulai() {
      if (!aiRekamDukung || mendengar) { return; }
      if (live) { return; } // mode percakapan sedang memakai mikrofon
      if (!window.isSecureContext) {
        status('Fitur bicara butuh alamat aman (https). Buka situs lewat https lalu coba lagi.', true);
        return;
      }
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      try { rec = new SR(); } catch (e) { status('Mikrofon tidak bisa dibuka di peramban ini.', true); return; }
      rec.lang = AI_TAG[kode] || AI_TAG.id;
      rec.interimResults = true;
      rec.continuous = false;
      rec.maxAlternatives = 1;
      akhir = ''; gagal = false; dibatalkan = false; terkirim = false;
      rec.onresult = function (ev) {
        var antara = '';
        for (var i = ev.resultIndex; i < ev.results.length; i++) {
          var t = ev.results[i][0] ? ev.results[i][0].transcript : '';
          if (ev.results[i].isFinal) { akhir += ' ' + t; } else { antara += ' ' + t; }
        }
        akhir = akhir.replace(/\s+/g, ' ').trim();
        // Teks sementara ditampilkan di kotak pertanyaan supaya pengguna bisa
        // melihat apa yang terdengar sebelum dikirim.
        if (ta && !terkirim) { ta.value = (akhir + ' ' + antara).replace(/\s+/g, ' ').trim(); }
      };
      rec.onerror = function (ev) {
        mendengar = false;
        tandaMic(false);
        var k = ev && ev.error ? String(ev.error) : '';
        var pesan = 'Pengenal suara gagal. Coba lagi.';
        if (k === 'not-allowed' || k === 'service-not-allowed') { pesan = 'Izin mikrofon ditolak. Izinkan akses mikrofon di peramban lalu coba lagi.'; }
        else if (k === 'no-speech') { pesan = 'Tidak ada suara yang terdengar. Coba bicara lebih dekat ke mikrofon.'; }
        else if (k === 'audio-capture') { pesan = 'Mikrofon tidak ditemukan di perangkat ini.'; }
        else if (k === 'network') { pesan = 'Pengenal suara butuh koneksi internet. Periksa jaringanmu lalu coba lagi.'; }
        if (k === 'aborted') { return; } // dihentikan sendiri oleh pengguna
        gagal = true;
        status(pesan, true);
      };
      rec.onend = function () {
        mendengar = false;
        tandaMic(false);
        if (terkirim || gagal) { return; }
        var tanya = (akhir || (ta ? ta.value : '')).replace(/\s+/g, ' ').trim();
        if (!tanya) {
          status('Tidak ada suara yang terdengar. Coba lagi.', true);
          return;
        }
        if (ta) { ta.value = tanya; }
        if (dibatalkan) {
          // Pengguna menekan "Berhenti": teksnya dibiarkan di kotak pertanyaan
          // supaya bisa diperiksa/diperbaiki dulu.
          status('Suara dihentikan — tekan Kirim bila pertanyaannya sudah cocok.', false);
          return;
        }
        terkirim = true;
        status('Pertanyaan dari suaramu dikirim.', false);
        kirim(tanya);
      };
      try { rec.start(); } catch (e) { status('Mikrofon tidak bisa dibuka. Coba lagi.', true); return; }
      mendengar = true;
      tandaMic(true);
      aiDiam();
      status('Mendengarkan... bicara sekarang, lalu pertanyaannya otomatis dikirim.', false);
    }

    if (!aiBunyiDukung && !aiRekamDukung) {
      bar.hidden = true;
      status('Peramban ini belum mendukung fitur suara. Kamu tetap bisa menulis pertanyaan seperti biasa.', false);
      return null;
    }
    aiSuaraTampil = aiBunyiDukung;
    return {
      ucap: function (teks) { return aiUcap(teks, kode); },
      diam: aiDiam,
      otomatis: function () { return otomatis; },
      kode: function () { return kode; },
      liveAktif: function () { return live; },
      liveHenti: function (pesan) { liveHenti(pesan); },
      liveMulai: liveMulai
    };
  }

  function aiMsgHtml(peran, teks, inisial) {
    var ai = peran === 'ai';
    var ava = ai
      ? '<span class="ai-ava ai">' + AI_SPARKLE + '</span>'
      : '<span class="ai-ava">' + aiEsc(inisial || '?') + '</span>';
    var baca = (ai && aiSuaraTampil)
      ? '<button type="button" class="ai-speak" data-ai-speak title="Bacakan jawaban ini" aria-label="Bacakan jawaban ini">' + AI_VOLUME + '</button>'
      : '';
    return '<div class="ai-msg ' + (ai ? 'ai' : 'user') + '">' + ava
      + '<div class="ai-bubble"><div class="ai-text">' + aiFmt(teks, ai) + '</div>'
      + '<div class="ai-jam">' + baca + '<span>' + aiJam() + '</span></div></div></div>';
  }
  function aiInit(card) {
    var form = card.querySelector('[data-ai-form]');
    if (!form) { return; } // kunci API belum diisi atau pengguna belum masuk
    var thread = card.querySelector('[data-ai-thread]');
    var statusEl = card.querySelector('[data-ai-status]');
    var ta = form.querySelector('textarea[name="pesan"]');
    var kirimBtn = form.querySelector('[data-ai-send]');
    var csrf = form.querySelector('input[name="_csrf"]');
    var bersihBtn = card.querySelector('[data-ai-bersihkan]');
    var suara = null; // diisi aiSuaraInit() di bawah (null bila fitur suara mati)
    var sibuk = false;

    function status(pesan, err) {
      if (!statusEl) { return; }
      statusEl.textContent = pesan || '';
      statusEl.classList.toggle('err', !!err);
    }
    function keBawah() { thread.scrollTop = thread.scrollHeight; }
    function tambah(html) {
      var kosong = thread.querySelector('[data-ai-kosong]');
      if (kosong) { kosong.parentNode.removeChild(kosong); }
      thread.insertAdjacentHTML('beforeend', html);
      keBawah();
    }
    function post(aksi, extra) {
      var fd = new FormData();
      fd.append('_csrf', csrf ? csrf.value : '');
      fd.append('lesson_id', card.getAttribute('data-ai-lesson') || '');
      fd.append('aksi', aksi);
      Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
      return fetch(card.getAttribute('data-ai-url') || 'api-ai-chat.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Balasan server tidak dikenali.' }; });
      });
    }
    function kirim(teks, opsi) {
      opsi = opsi || {};
      teks = String(teks || '').trim();
      if (sibuk) {
        if (typeof opsi.onGagal === 'function') { opsi.onGagal('Masih ada pertanyaan yang sedang diproses.'); }
        return;
      }
      if (!teks) {
        status('Tulis pertanyaanmu dulu.', true);
        ta.focus();
        if (typeof opsi.onGagal === 'function') { opsi.onGagal(''); }
        return;
      }
      if (suara) { suara.diam(); } // hentikan bacaan jawaban sebelumnya
      sibuk = true;
      if (kirimBtn) { kirimBtn.disabled = true; }
      status('AI sedang menyusun jawaban...', false);
      var gelembung = aiMsgHtml('user', teks, (card.getAttribute('data-ai-inisial') || '?'));
      tambah(gelembung);
      tambah('<div class="ai-msg ai ai-typing" data-ai-typing><span class="ai-ava ai">' + AI_SPARKLE
        + '</span><div class="ai-bubble"><span class="ai-dots"><i></i><i></i><i></i></span></div></div>');
      ta.value = '';
      var muatan = opsi.mode === 'suara' ? { pesan: teks, mode: 'suara' } : { pesan: teks };
      post('kirim', muatan).then(function (d) {
        var t = thread.querySelector('[data-ai-typing]');
        if (t) { t.parentNode.removeChild(t); }
        sibuk = false;
        if (kirimBtn) { kirimBtn.disabled = false; }
        if (!d || !d.ok) {
          // Pertanyaan TIDAK tersimpan bila gagal — kembalikan teksnya ke kotak.
          var g = thread.querySelectorAll('.ai-msg.user');
          if (g.length) { g[g.length - 1].parentNode.removeChild(g[g.length - 1]); }
          ta.value = teks;
          var pesanErr = (d && d.error) || 'Gagal mengirim pertanyaan.';
          status(pesanErr, true);
          ta.focus();
          if (typeof opsi.onGagal === 'function') { opsi.onGagal(pesanErr); }
          return;
        }
        tambah(d.html || aiMsgHtml('ai', d.balasan || '', ''));
        if (bersihBtn) { bersihBtn.hidden = false; }
        // Obrolan suara penuh: jawaban AI langsung dibacakan bila diaktifkan.
        // (Tanpa pesan status, supaya baris status tetap bersih — keadaan suara
        //  sudah ditandai oleh tombol "Suara AI".)
        // opsi.tanpaBaca dipakai mode percakapan yang membacakan sendiri (butuh
        // jeda antar giliran sebelum mikrofon dihidupkan lagi).
        if (suara && !opsi.tanpaBaca && d.balasan && suara.otomatis()) { suara.ucap(d.balasan); }
        status('', false);
        ta.focus();
        if (typeof opsi.onJawab === 'function') { opsi.onJawab(d.balasan || ''); }
      }).catch(function () {
        var t = thread.querySelector('[data-ai-typing]');
        if (t) { t.parentNode.removeChild(t); }
        sibuk = false;
        if (kirimBtn) { kirimBtn.disabled = false; }
        status('Koneksi terputus. Coba lagi.', true);
        if (typeof opsi.onGagal === 'function') { opsi.onGagal('Koneksi terputus. Coba lagi.'); }
      });
    }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      kirim(ta.value);
    });
    // Enter = kirim, Shift+Enter = baris baru
    ta.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.ctrlKey && !ev.altKey) {
        ev.preventDefault();
        kirim(ta.value);
      }
    });
    if (bersihBtn) {
      // Tanpa konfirmasi (permintaan pemilik): klik langsung menghapus riwayat.
      bersihBtn.addEventListener('click', function () {
        bersihBtn.disabled = true;
        post('bersihkan', {}).then(function (d) {
          bersihBtn.disabled = false;
          if (!d || !d.ok) { status((d && d.error) || 'Gagal menghapus riwayat.', true); return; }
          thread.innerHTML = '<p class="muted small ai-kosong" data-ai-kosong>Riwayat dihapus. Mulai percakapan baru di bawah.</p>';
          bersihBtn.hidden = true;
          if (suara) {
            suara.diam();
            // Riwayat dikosongkan: sesi percakapan juga dihentikan.
            if (suara.liveAktif()) { suara.liveHenti('Mode percakapan diakhiri karena riwayat dibersihkan.'); }
          }
          status('Riwayat percakapan dihapus.', false);
        });
      });
    }
    // Pengendali suara (tombol bicara, pembacaan jawaban, pilihan bahasa).
    suara = aiSuaraInit(card, kirim, status);
    keBawah();
  }
  document.querySelectorAll('[data-ai]').forEach(aiInit);

  /* ---------------- zoom gambar materi (scan/foto) ----------------
     Hanya GAMBAR materinya yang diperbesar/diperkecil — teks materi tidak ikut
     (permintaan pemilik: "hanya gambar/scan materinya"). Dua cara pakai:
       * tombol − / + di atas gambar (plus tombol persen untuk kembali ke 100%);
       * cubit dua jari di layar sentuh (juga di dalam aplikasi Android).
     Saat diperbesar, gambar digeser dengan satu jari (HP) atau seret mouse
     (desktop). Markup-nya dirender materi.php ([data-zoom-alat]). */
  (function () {
    var TANGGA = [0.5, 0.75, 1, 1.25, 1.5, 2, 2.5, 3, 4]; // langkah tombol
    var MIN = TANGGA[0];
    var MAKS = TANGGA[TANGGA.length - 1];

    function jarakJari(a, b) {
      var dx = a.clientX - b.clientX, dy = a.clientY - b.clientY;
      return Math.sqrt(dx * dx + dy * dy) || 1;
    }

    document.querySelectorAll('[data-zoom-alat]').forEach(function (alat) {
      var frame = alat.querySelector('[data-zoom]');
      var img = frame ? frame.querySelector('img.media-img') : null;
      if (!frame || !img) { return; }
      var btnMasuk = alat.querySelector('[data-zoom-in]');
      var btnKeluar = alat.querySelector('[data-zoom-out]');
      var btnReset = alat.querySelector('[data-zoom-reset]');
      var label = alat.querySelector('[data-zoom-nilai]');

      var skala = 1, geserX = 0, geserY = 0;

      function ukur() {
        var r = frame.getBoundingClientRect();
        return { w: r.width, h: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 };
      }
      // Seberapa jauh gambar boleh digeser dari tengah: separuh kelebihannya.
      function batas(m, s) {
        return {
          x: Math.max(0, (m.w * s - m.w) / 2),
          y: Math.max(0, (m.h * s - m.h) / 2)
        };
      }
      function jepit(m) {
        var b = batas(m || ukur(), skala);
        geserX = Math.max(-b.x, Math.min(b.x, geserX));
        geserY = Math.max(-b.y, Math.min(b.y, geserY));
      }
      function gambar() {
        img.style.transform = 'translate(' + geserX.toFixed(1) + 'px,' + geserY.toFixed(1) + 'px) scale(' + skala + ')';
        frame.classList.toggle('zoom-aktif', skala !== 1);
        if (label) { label.textContent = Math.round(skala * 100) + '%'; }
        if (btnMasuk) { btnMasuk.disabled = skala >= MAKS - 0.001; }
        if (btnKeluar) { btnKeluar.disabled = skala <= MIN + 0.001; }
      }
      function kembaliKeAwal() {
        skala = 1; geserX = 0; geserY = 0; gambar();
      }
      /**
       * Ubah skala sambil menjaga titik (x,y) di layar tetap di atas isi gambar
       * yang sama — inilah yang membuat cubit terasa alami dan tombol tidak
       * melompat. x/y koordinat layar; kosong = titik tengah gambar.
       */
      function setSkala(baru, x, y) {
        var m = ukur();
        var lama = skala;
        baru = Math.max(MIN, Math.min(MAKS, baru));
        if (Math.abs(baru - lama) < 0.001) { return; }
        var ax = (typeof x === 'number' ? x : m.cx) - m.cx;
        var ay = (typeof y === 'number' ? y : m.cy) - m.cy;
        geserX = ax - (baru / lama) * (ax - geserX);
        geserY = ay - (baru / lama) * (ay - geserY);
        skala = baru;
        jepit(m);
        gambar();
      }
      function langkah(arah) {
        var i;
        if (arah > 0) {
          for (i = 0; i < TANGGA.length; i++) {
            if (TANGGA[i] > skala + 0.001) { return TANGGA[i]; }
          }
          return MAKS;
        }
        for (i = TANGGA.length - 1; i >= 0; i--) {
          if (TANGGA[i] < skala - 0.001) { return TANGGA[i]; }
        }
        return MIN;
      }

      if (btnMasuk) { btnMasuk.addEventListener('click', function () { setSkala(langkah(1)); }); }
      if (btnKeluar) { btnKeluar.addEventListener('click', function () { setSkala(langkah(-1)); }); }
      if (btnReset) { btnReset.addEventListener('click', kembaliKeAwal); }
      // Gambar tidak boleh ikut "diseret" sebagai berkas oleh peramban.
      img.addEventListener('dragstart', function (ev) { ev.preventDefault(); });

      /* --- sentuh: cubit dua jari & geser satu jari --- */
      var cubit = null, seret = null;

      frame.addEventListener('touchstart', function (ev) {
        if (ev.touches.length >= 2) {
          var m = ukur();
          cubit = {
            d0: jarakJari(ev.touches[0], ev.touches[1]),
            s0: skala,
            // titik isi gambar yang berada tepat di bawah titik tengah jari
            ux: ((ev.touches[0].clientX + ev.touches[1].clientX) / 2 - m.cx - geserX) / skala,
            uy: ((ev.touches[0].clientY + ev.touches[1].clientY) / 2 - m.cy - geserY) / skala
          };
          seret = null;
          frame.classList.add('zoom-segera');
        } else if (ev.touches.length === 1 && skala > 1) {
          seret = { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
          frame.classList.add('zoom-segera');
        }
      }, { passive: true });

      frame.addEventListener('touchmove', function (ev) {
        if (cubit && ev.touches.length >= 2) {
          ev.preventDefault(); // jangan biarkan peramban menggulir/mem-zoom halaman
          var m = ukur();
          var d = jarakJari(ev.touches[0], ev.touches[1]);
          var mx = (ev.touches[0].clientX + ev.touches[1].clientX) / 2;
          var my = (ev.touches[0].clientY + ev.touches[1].clientY) / 2;
          skala = Math.max(MIN, Math.min(MAKS, cubit.s0 * (d / cubit.d0)));
          geserX = (mx - m.cx) - skala * cubit.ux;
          geserY = (my - m.cy) - skala * cubit.uy;
          jepit(m);
          gambar();
          return;
        }
        if (seret && ev.touches.length === 1) {
          // Delta bertahap: tiap peristiwa menggeser dari posisi jari sebelumnya,
          // jadi pembatasan & pengalihan ke gulir halaman mudah dihitung.
          var dx = ev.touches[0].clientX - seret.x;
          var dy = ev.touches[0].clientY - seret.y;
          seret.x = ev.touches[0].clientX;
          seret.y = ev.touches[0].clientY;
          var mm = ukur();
          var b = batas(mm, skala);
          var tyBaru = geserY + dy;
          var tyJepit = Math.max(-b.y, Math.min(b.y, tyBaru));
          var sisaY = tyBaru - tyJepit; // tarikan yang tidak terpakai menggeser gambar
          ev.preventDefault(); // menggeser gambar, bukan menggulir halaman
          geserX = Math.max(-b.x, Math.min(b.x, geserX + dx));
          geserY = tyJepit;
          gambar();
          // Sudah mentok ke atas/bawah: sisa tarikan (yang arahnya benar-benar
          // menurun/menaik) dipakai menggulir halaman supaya pengguna tidak
          // terjebak di dalam gambar yang sangat panjang.
          if (Math.abs(sisaY) > 0.5 && Math.abs(dy) > Math.abs(dx)) {
            window.scrollBy(0, -sisaY);
          }
        }
      }, { passive: false });

      function akhiriSentuh(ev) {
        if (ev && ev.touches && ev.touches.length === 1 && cubit) {
          // Satu jari terangkat saat mencubit: lanjutkan sebagai geser.
          cubit = null;
          seret = { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
          return;
        }
        if (ev && ev.touches && ev.touches.length >= 2) { return; }
        cubit = null;
        seret = null;
        frame.classList.remove('zoom-segera');
      }
      frame.addEventListener('touchend', akhiriSentuh);
      frame.addEventListener('touchcancel', akhiriSentuh);

      /* --- mouse (desktop): seret untuk menggeser saat diperbesar --- */
      frame.addEventListener('mousedown', function (ev) {
        if (ev.button !== 0 || skala <= 1) { return; }
        ev.preventDefault();
        var awalX = ev.clientX, awalY = ev.clientY, gx = geserX, gy = geserY;
        frame.classList.add('zoom-segera', 'menggeser');
        function gerak(e2) {
          geserX = gx + (e2.clientX - awalX);
          geserY = gy + (e2.clientY - awalY);
          jepit();
          gambar();
        }
        function lepas() {
          document.removeEventListener('mousemove', gerak);
          document.removeEventListener('mouseup', lepas);
          frame.classList.remove('zoom-segera', 'menggeser');
        }
        document.addEventListener('mousemove', gerak);
        document.addEventListener('mouseup', lepas);
      });

      // Ukuran berubah (mis. HP diputar): rapikan lagi posisinya.
      window.addEventListener('resize', function () { jepit(); gambar(); });
      gambar();
    });
  })();

  /* ==================================================================
   * Halaman "Materi Offline" (siswa-offline.php)
   * ------------------------------------------------------------------
   * Daftar quiz/materi dirender server (PHP); bagian ini yang mengurus
   * interaksinya: menyiapkan sesi ujian offline (api-offline.php), meminta
   * aplikasi Android menyimpan halamannya, menampilkan salinan yang sudah ada
   * di perangkat, dan mengirim jawaban yang masih mengantre.
   * ================================================================== */
  var luringApp = document.getElementById('luring-app');
  if (luringApp) {
    var csrfLuring = luringApp.getAttribute('data-csrf') || LMSLuring.csrf();
    var pesanEl = luringApp.querySelector('[data-luring-pesan]');
    var statusEl = luringApp.querySelector('[data-luring-status]');
    var simpanEl = luringApp.querySelector('[data-luring-tersimpan]');
    var antreEl = luringApp.querySelector('[data-luring-antre]');
    var adaJembatan = LMSLuring.didukung();

    function lEsc(t) {
      return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function lUkuran(n) {
      n = parseInt(n || 0, 10);
      if (n >= 1048576) { return (Math.round(n / 104857.6) / 10) + ' MB'; }
      if (n >= 1024) { return Math.round(n / 1024) + ' KB'; }
      return n + ' B';
    }
    function lWaktu(ms) {
      if (!ms) { return ''; }
      var d = new Date(ms);
      var pad = function (x) { return x < 10 ? '0' + x : '' + x; };
      return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function lKabar(html, salah) {
      if (!pesanEl) { return; }
      // Tanpa kelas, elemen ini tidak terlihat sama sekali (jadi jangan pakai
      // atribut `hidden`: aturan display milik .notice akan mengalahkannya).
      pesanEl.className = 'notice' + (salah ? ' notice-salah' : '');
      pesanEl.innerHTML = html;
    }

    var ICO_QUIZ = '<svg class="ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7v.5"/><circle cx="12" cy="17.5" r="1"/><circle cx="12" cy="12" r="9.2"/></svg>';
    var ICO_FILE = '<svg class="ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>';
    var ICO_UNDUH = '<svg class="ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7.5 11 4.5 4.5L16.5 11"/><path d="M4 20h16"/></svg>';

    /* ---------------- tampilan: salinan di perangkat & antrean ---------------- */
    function lGambar() {
      var m = LMSLuring.unduhDaftar();
      var kunci = Object.keys(m);
      if (simpanEl) {
        if (!kunci.length) {
          simpanEl.innerHTML = '<p class="muted small mb0">Belum ada yang tersimpan di perangkat ini. Tekan "Unduh offline" pada daftar di bawah.</p>';
        } else {
          var html = '';
          kunci.forEach(function (u) {
            var d = m[u] || {};
            html += '<div class="luring-item">'
              + '<span class="luring-ic">' + (d.tipe === 'materi' ? ICO_FILE : ICO_QUIZ) + '</span>'
              + '<span class="luring-meta"><strong>' + lEsc(d.judul || u) + '</strong>'
              + '<small>' + (d.tipe === 'materi' ? 'Materi' : 'Quiz') + (d.soal ? ' &middot; ' + (parseInt(d.soal, 10) || 0) + ' soal' : '')
              + ' &middot; ' + (d.ukuran ? lUkuran(d.ukuran) + ' &middot; ' : '')
              + (d.waktu ? 'disimpan ' + lWaktu(d.waktu) : '')
              // kuncinya alamat mutlak; yang ditampilkan cukup jalur relatifnya
              + ' &middot; ' + lEsc(u.replace(/^https?:\/\/[^/]+/, '')) + '</small></span>'
              + '<span class="luring-aksi">'
              + '<a class="btn btn-primary btn-sm" href="' + lEsc(u) + '">Buka</a>'
              + '<button type="button" class="btn btn-ghost btn-sm" data-luring-hapus="' + lEsc(u) + '" data-attempt="' + (parseInt(d.attempt, 10) || 0) + '">Hapus</button>'
              + '</span></div>';
          });
          simpanEl.innerHTML = html;
        }
      }
      // tanda "tersimpan" di baris daftar unduhan (logikanya satu tempat:
      // LMSLuring.tandaGambar — dipakai juga tombol di halaman materi)
      LMSLuring.tandaGambar(luringApp);

      var antre = LMSLuring.antre();
      if (antreEl) {
        if (!antre.length) {
          antreEl.innerHTML = '<p class="muted small mb0">Tidak ada jawaban yang menunggu dikirim.</p>';
        } else {
          var h2 = '';
          antre.forEach(function (a) {
            h2 += '<div class="luring-item">'
              + '<span class="luring-ic">' + ICO_QUIZ + '</span>'
              + '<span class="luring-meta"><strong>' + lEsc(a.judul) + '</strong>'
              + '<small>' + a.terjawab + ' soal terjawab' + (a.kirim ? ' &middot; menunggu dikirim' : ' &middot; menunggu dikirim otomatis') + '</small></span>'
              + '<span class="luring-aksi">'
              + '<a class="btn btn-primary btn-sm" href="ujian.php?attempt=' + lEsc(a.attempt) + '">Buka</a>'
              + '</span></div>';
          });
          antreEl.innerHTML = h2;
        }
      }
      var btnKirim = luringApp.querySelector('[data-luring-kirim]');
      if (btnKirim) { btnKirim.hidden = antre.length === 0 || !LMSLuring.online(); }
      var hitung = luringApp.querySelector('[data-luring-hitung]');
      if (hitung) { hitung.textContent = antre.length; }
    }

    /* ---------------- status jembatan aplikasi ---------------- */
    if (statusEl) {
      statusEl.innerHTML = adaJembatan
        ? '<div class="flash flash-success">' + ICO_UNDUH + '<span>Aplikasi Android terdeteksi — unduhan disimpan di perangkat ini dan bisa dibuka tanpa internet.</span></div>'
        : '<div class="notice">Fitur unduh offline berjalan di dalam aplikasi Android <strong>Gemilang Language Corner</strong> (versi 1.2 ke atas). '
          + 'Di peramban biasa halaman ini tetap bisa dibuka, tetapi salinan offline harus lewat aplikasi. '
          + 'Aplikasinya bisa diunduh <a href="GemilangLanguageCorner.apk">di sini</a>.</div>';
    }

    /* ---------------- unduh quiz (siapkan sesi + simpan halaman) ---------------- */
    function lUnduhQuiz(btn) {
      var quiz = btn.getAttribute('data-luring-quiz');
      var judul = btn.getAttribute('data-judul') || '';
      if (!LMSLuring.online()) { lKabar('Tidak ada internet. Sambungkan dulu, lalu coba lagi.', true); return; }
      if (!adaJembatan) {
        // Diperiksa SEBELUM sesi ujian disiapkan: kalau tidak, jatah percobaan
        // (max_attempt) terpakai percuma karena unduhannya toh tidak bisa dibuat.
        lKabar('Quiz ini hanya bisa diunduh dari aplikasi Android <strong>Gemilang Language Corner</strong> versi 1.2 ke atas.', true);
        return;
      }
      var asli = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = 'Menyiapkan…';
      var fd = new FormData();
      fd.append('_csrf', csrfLuring);
      fd.append('aksi', 'siap');
      fd.append('quiz', quiz);
      fetch('api-offline.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) {
            btn.disabled = false; btn.innerHTML = asli;
            lKabar(lEsc((j && j.error) || 'Gagal menyiapkan quiz ini untuk offline.'), true);
            return;
          }
          // Alamat mutlak wajib (lihat urlPenuh) — server mengirim "ujian.php?attempt=N".
          var url = LMSLuring.urlPenuh(j.url);
          btn.textContent = 'Menyimpan…';
          LMSLuring.simpanLuring(url, j.judul || judul, 'siswa-offline.php', function (ok, pesan) {
            btn.disabled = false; btn.innerHTML = asli;
            if (!ok) { lKabar(lEsc(pesan || 'Gagal menyimpan ke perangkat.'), true); return; }
            LMSLuring.unduhCatat(url, {
              judul: j.judul || judul, tipe: 'quiz', attempt: parseInt(j.attempt, 10) || 0,
              quiz: parseInt(quiz, 10) || 0, kursus: j.kursus || '', soal: parseInt(j.jumlah_soal, 10) || 0,
              menit: parseInt(j.menit, 10) || 0, waktu: Date.now()
            });
            lKabar('"<strong>' + lEsc(j.judul || judul) + '</strong>" tersimpan di perangkat. Buka halaman ini lagi kapan saja — walau tanpa internet.', false);
            lGambar();
          });
        })
        .catch(function () {
          btn.disabled = false; btn.innerHTML = asli;
          lKabar('Gagal menghubungi server. Coba lagi saat ada internet.', true);
        });
    }

    /* ---------------- unduh materi (halaman + gambar) ----------------
       Mesinnya LMSLuring.unduhMateri (dipakai juga tombol "Unduh Offline" di
       halaman materi); di sini hanya umpan baliknya yang berbeda — kotak pesan
       halaman ini, bukan pita status. */
    function lUnduhMateri(btn) {
      LMSLuring.unduhMateri(btn, lKabar);
    }

    /* ---------------- hapus salinan di perangkat ---------------- */
    function lHapus(btn) {
      var url = btn.getAttribute('data-luring-hapus');
      var attempt = parseInt(btn.getAttribute('data-attempt') || '0', 10);
      LMSLuring.hapusLuring(url, function () {});
      LMSLuring.unduhHapus(url);
      lGambar();
      // Sesi ujian yang belum diisi sama sekali dibatalkan di server supaya tidak
      // memakan jatah percobaan (max_attempt). Yang sudah ada jawaban dibiarkan.
      if (attempt && !Object.keys(LMSLuring.jawaban(attempt)).length && LMSLuring.online()) {
        var fd = new FormData();
        fd.append('_csrf', csrfLuring);
        fd.append('aksi', 'batal');
        fd.append('attempt', attempt);
        fetch('api-offline.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
      }
      lKabar('Salinan di perangkat dihapus.', false);
    }

    /* ---------------- rapikan: quiz yang sudah selesai ---------------- */
    function lPeriksaStatus() {
      var m = LMSLuring.unduhDaftar();
      var ids = [];
      Object.keys(m).forEach(function (u) {
        var a = parseInt((m[u] || {}).attempt, 10) || 0;
        if (a && ids.indexOf(a) === -1) { ids.push(a); }
      });
      if (!ids.length) { lGambar(); return; }
      if (!LMSLuring.online()) { lGambar(); return; }
      fetch('api-offline.php?aksi=status&attempts=' + ids.join(','), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { return; }
          var bersih = 0;
          Object.keys(m).forEach(function (u) {
            var a = parseInt((m[u] || {}).attempt, 10) || 0;
            var st = a ? j.attempt[a] : null;
            if (st && st.selesai) {
              LMSLuring.hapusLuring(u, function () {});
              LMSLuring.unduhHapus(u);
              LMSLuring.bersihkan(a);
              bersih++;
            }
          });
          if (bersih) { lKabar(bersih + ' salinan offline dibersihkan karena quiznya sudah selesai dikerjakan.', false); }
          lGambar();
        })
        .catch(function () { lGambar(); });
    }

    /**
     * Rapikan daftar: (1) buang catatan yang berkasnya sudah tidak ada di
     * perangkat (menurut aplikasi), (2) buang salinan yang quiznya sudah selesai.
     */
    function lPeriksa() {
      if (!adaJembatan) { lPeriksaStatus(); return; }
      LMSLuring.daftarLuring(function (items) {
        if (items) {
          var diApp = {};
          items.forEach(function (it) { diApp[it.url] = 1; });
          var m = LMSLuring.unduhDaftar();
          var buang = 0;
          Object.keys(m).forEach(function (u) {
            if (!diApp[u]) { LMSLuring.unduhHapus(u); buang++; }
          });
          if (buang) { lKabar(buang + ' catatan unduhan dirapikan (berkasnya sudah tidak ada di perangkat).', false); }
        }
        lPeriksaStatus();
      });
    }

    /* ---------------- kirim antrean sekarang ---------------- */
    function lKirim() {
      if (!LMSLuring.online()) { lKabar('Tidak ada internet. Jawabanmu tetap aman dan akan terkirim otomatis.', true); return; }
      lKabar('Mengirim jawaban yang tersimpan…', false);
      LMSLuring.flush(false, function (hasil) {
        var kirim = 0, gagal = 0;
        (hasil || []).forEach(function (h) { if (h && h.kirim) { kirim++; } else if (h && h.gagal) { gagal++; } });
        if (gagal) { lKabar('Sebagian jawaban belum bisa dikirim (sesi masuk mungkin berakhir). Coba masuk lagi lalu ulangi.', true); }
        else { lKabar(kirim ? ('&#10003; ' + kirim + ' jawaban sudah terkirim.') : '&#10003; Jawaban tersimpan sudah dikirim ke server.', false); }
        lGambar();
      });
    }

    /* ---------------- interaksi ---------------- */
    luringApp.addEventListener('click', function (ev) {
      var t = ev.target;
      var bQuiz = t.closest ? t.closest('[data-luring-quiz]') : null;
      if (bQuiz) { ev.preventDefault(); lUnduhQuiz(bQuiz); return; }
      var bMateri = t.closest ? t.closest('[data-luring-unduh]') : null;
      if (bMateri) { ev.preventDefault(); lUnduhMateri(bMateri); return; }
      var bHapus = t.closest ? t.closest('[data-luring-hapus]') : null;
      if (bHapus) { ev.preventDefault(); lHapus(bHapus); return; }
      var bKirim = t.closest ? t.closest('[data-luring-kirim]') : null;
      if (bKirim) { ev.preventDefault(); lKirim(); return; }
      var bPeriksa = t.closest ? t.closest('[data-luring-periksa]') : null;
      if (bPeriksa) { ev.preventDefault(); lPeriksa(); return; }
    });

    lGambar();
    // Saat antrean selesai dikirim otomatis (mis. baru tersambung lagi), daftar
    // di halaman ini ikut diperbarui.
    window.addEventListener('online', function () { setTimeout(lGambar, 1500); });
    if (LMSLuring.online()) { lPeriksa(); }
  }

  /* ==================================================================
   * Tombol "Unduh Offline" di halaman materi (materi.php)
   * ------------------------------------------------------------------
   * Tombol yang sama dipakai halaman "Materi Offline", tetapi di sini tanpa
   * daftar salinan: umpan baliknya lewat pita status, dan tanda "Tersimpan"
   * muncul di sebelah tombolnya. Dibiarkan tampil walau aplikasi Android tidak
   * ada (mis. dibuka di komputer): pengguna perlu tahu fiturnya ada dan di mana
   * memasangnya — pesannya menautkan berkas APK-nya.
   * ================================================================== */
  if (!luringApp && document.querySelectorAll('[data-luring-unduh]').length) {
    LMSLuring.tandaGambar(document);
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      var btn = (t && t.closest) ? t.closest('[data-luring-unduh]') : null;
      if (!btn) { return; }
      ev.preventDefault();
      LMSLuring.unduhMateri(btn);
    });
  }

  /* ---------------- tabs deep link ---------------- */
  var hash = window.location.hash.replace('#', '');
  if (hash) {
    var t = document.querySelector('[data-tab="' + hash + '"]');
    if (t) { t.click(); }
  }
})();

/* --- infografis materi (form materi guru): panel tipe + tombol "Susun dengan AI" ---
   Isi infografis disimpan pada textarea biasa, jadi tanpa JavaScript pun materi
   tetap bisa disimpan — yang butuh JS hanya penyusunan otomatis dari bacaan.
   Endpoint: api-infografis.php (kunci API penyedia AI tetap di server). */
(function () {
  var modal = document.querySelector('#modal-materi');
  if (!modal) { return; }
  var pilihTipe = modal.querySelector('[data-tipe-materi]');
  var panel = modal.querySelector('[data-panel-tipe="infografis"]');
  var tombol = modal.querySelector('[data-info-susun]');
  var status = modal.querySelector('[data-info-status]');
  var isi = modal.querySelector('[data-info-isi]');
  var pilihSumber = modal.querySelector('[data-info-sumber]');
  var kotakTeks = modal.querySelector('[data-info-kotak="teks"]');
  var kotakBerkas = modal.querySelector('[data-info-kotak="berkas"]');
  var teksSumber = modal.querySelector('[data-info-teks]');
  var berkasSumber = modal.querySelector('[data-info-berkas]');
  var judulEl = modal.querySelector('[name=judul]');
  var idEl = modal.querySelector('[name=id]');
  if (!panel || !isi || !tombol) { return; }

  function csrfToken() {
    var m = document.querySelector('meta[name="lms-csrf"]');
    return m ? (m.getAttribute('content') || '') : '';
  }
  function catat(pesan) { if (status) { status.textContent = pesan; } }
  function tampilPanel() { panel.hidden = !pilihTipe || pilihTipe.value !== 'infografis'; }
  function tampilSumber() {
    var mode = pilihSumber ? pilihSumber.value : 'materi';
    if (kotakTeks) { kotakTeks.hidden = mode !== 'teks'; }
    if (kotakBerkas) { kotakBerkas.hidden = mode !== 'berkas'; }
  }

  function susun() {
    if (tombol.disabled) { return; }
    var mode = pilihSumber ? pilihSumber.value : 'materi';
    var fd = new FormData();
    fd.append('_csrf', csrfToken());
    fd.append('lesson_id', idEl ? (idEl.value || '0') : '0');
    fd.append('judul', judulEl ? (judulEl.value || '') : '');
    if (mode === 'teks') {
      var teks = teksSumber ? teksSumber.value.trim() : '';
      if (!teks) { catat('Tempel dulu teks bacaannya.'); return; }
      fd.append('sumber', teks);
    } else if (mode === 'berkas') {
      var file = berkasSumber && berkasSumber.files ? berkasSumber.files[0] : null;
      if (!file) { catat('Pilih dulu berkas bacaannya.'); return; }
      fd.append('sumber_file', file);
    }
    tombol.disabled = true;
    catat('AI membaca bacaan lalu menyusun infografis… (bisa sampai satu menit)');
    fetch('api-infografis.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        // Balasan bisa bukan JSON (mis. berkas melebihi batas server) — beri pesan
        // yang bisa dimengerti, jangan "Unexpected token" dari JSON.parse.
        return r.text().then(function (t) {
          try {
            return JSON.parse(t);
          } catch (e) {
            throw new Error(r.status === 413
              ? 'Berkas yang dipilih terlalu besar untuk server. Kirim halamannya satu per satu atau kecilkan berkasnya.'
              : 'Server membalas tidak seperti yang diharapkan (kode ' + r.status + '). Coba lagi.');
          }
        });
      })
      .then(function (d) {
        if (!d || !d.ok) { throw new Error((d && d.error) || 'Gagal menyusun infografis.'); }
        isi.value = d.teks || '';
        catat('Infografis tersusun dari ' + (d.jenis === 'gambar' ? 'gambar bacaan' : 'teks bacaan')
          + ' (' + (d.blok || 0) + ' blok). Periksa dan sunting bila perlu, lalu klik Simpan Materi.');
      })
      .catch(function (e) {
        catat('Gagal: ' + (e && e.message ? e.message : 'tidak diketahui'));
      })
      .finally(function () { tombol.disabled = false; });
  }

  if (pilihTipe) { pilihTipe.addEventListener('change', tampilPanel); }
  if (pilihSumber) { pilihSumber.addEventListener('change', tampilSumber); }
  tombol.addEventListener('click', susun);
  tampilPanel();
  tampilSumber();

  // Dibuka lewat tombol "Infografis" pada daftar materi (?susun=1) → langsung susun.
  if (panel.getAttribute('data-info-auto') === '1' && !panel.hidden) { susun(); }
})();
