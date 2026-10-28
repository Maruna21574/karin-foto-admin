(() => {
  const base = document.body.dataset.base;
  const photos = window.PHOTOS;
  const $ = (id) => document.getElementById(id);
  const grid = $('grid'), lb = $('lb'), lbImg = $('lbImg');
  const small = window.innerWidth < 640;
  const HEART = '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 017-2.6A4 4 0 0119 10c0 5.6-7 10-7 10z"/></svg>';
  let filter = 'all'; // 'all' | 'fav'
  let currentSec = null; // sekcia, ktorú má klient práve na obrazovke
  let list = [];
  let idx = 0;

  const src = (p, kind) => `${base}/img/${p.id}/${kind}`;
  const toast = (msg) => {
    const t = $('toast');
    t.textContent = msg; t.hidden = false;
    clearTimeout(toast.t);
    toast.t = setTimeout(() => (t.hidden = true), 2400);
  };

  const sections = window.SECTIONS || [];
  const favBtns = new Map(); // fotka -> tlačidlo srdiečka v mriežke

  function updateCounts() {
    const n = photos.filter((p) => p.fav).length;
    $('cntFav').textContent = n;
    $('cntAll').textContent = photos.length;
    sections.forEach((s) => {
      const el = document.querySelector(`[data-seccount="${s.id}"]`);
      if (el) el.textContent = photos.filter((p) => p.s === s.id).length;
    });
    document.querySelectorAll('[data-favn]').forEach((el) => (el.textContent = n));
    document.querySelectorAll('[data-fav]').forEach((a) => a.classList.toggle('disabled', n === 0));
  }

  const isVisible = (p) => filter === 'all' || (filter === 'fav' ? p.fav : String(p.s) === filter);

  // prvky sa objavia (trieda .in), až keď sa dostanú do zobrazenia; bez podpory pozorovateľa sa ukážu hneď
  const io = 'IntersectionObserver' in window ? new IntersectionObserver((es) => es.forEach((e) => {
    if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
  }), { rootMargin: '0px 0px -8% 0px' }) : null;
  const reveal = (el) => (io ? requestAnimationFrame(() => io.observe(el)) : el.classList.add('in'));

  function makeTile(p, i) {
    const ratio = p.w / p.h;
    const fig = document.createElement('figure');
    fig.className = 'tile';
    fig.style.transitionDelay = `${(i % 2) * 70}ms`; // vedľajšie fotky naskakujú s malým oneskorením
    reveal(fig);
    fig.innerHTML = `<i style="padding-bottom:${(100 / ratio).toFixed(3)}%"></i>` +
      `<img loading="lazy" alt="" src="${src(p, 'thumb')}">` +
      `<button type="button" class="fav ${p.fav ? 'on' : ''}" aria-label="Obľúbené">${HEART}</button>`;
    const img = fig.querySelector('img');
    img.addEventListener('load', () => img.classList.add('loaded'));
    if (img.complete) img.classList.add('loaded');
    const btn = fig.querySelector('.fav');
    favBtns.set(p, btn);
    btn.addEventListener('click', (e) => { e.stopPropagation(); toggleFav(p); });
    fig.addEventListener('click', () => open(list.indexOf(p)));
    return fig;
  }

  // Fotky v stĺpcoch (2 na mobile) v pôvodnom pomere; každá ide do najnižšieho stĺpca, takže poradie ostáva zľava doprava.
  // Na mobile 2 stĺpce; na väčších displejoch riadky vyplnené celou šírkou (bez prázdnych miest).
  const colCount = () => (window.innerWidth < 640 ? 2 : 0);
  const ROW_H = window.innerWidth > 1600 ? 320 : 260; // cieľová výška riadku v px
  function masonry(tiles, items) {
    const n = colCount();
    if (!n) {
      const rows = document.createElement('div');
      rows.className = 'rows';
      tiles.forEach((t, i) => {
        const r = items[i].w / items[i].h;
        t.style.flex = `${r * ROW_H} 1 ${r * ROW_H}px`;
        t.style.maxWidth = `${Math.round(r * ROW_H * 1.45)}px`; // posledný riadok sa roztiahne, ale fotky nenarastú obrovské
        rows.appendChild(t);
      });
      return rows;
    }
    const wrap = document.createElement('div');
    wrap.className = 'masonry';
    const cols = Array.from({ length: n }, () => {
      const c = document.createElement('div');
      c.className = 'mcol';
      wrap.appendChild(c);
      return { el: c, h: 0 };
    });
    tiles.forEach((t, i) => {
      const low = cols.reduce((a, b) => (b.h < a.h ? b : a));
      low.el.appendChild(t);
      low.h += items[i].h / items[i].w;
    });
    return wrap;
  }
  let lastCols = colCount();
  let resizeT = 0;
  addEventListener('resize', () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(() => { if (colCount() !== lastCols) { lastCols = colCount(); render(); } }, 150);
  });

  function render() {
    const vis = photos.filter(isVisible);
    // skupiny: každá sekcia zvlášť, na konci fotky bez sekcie
    let groups = [{ title: null, items: vis }];
    if (sections.length) {
      groups = sections.map((s) => ({ id: s.id, cover: s.cover, title: s.title, items: vis.filter((p) => p.s === s.id) }));
      groups.push({ id: 0, title: 'Ostatné', items: vis.filter((p) => !sections.some((s) => s.id === p.s)) });
      groups = groups.filter((g) => g.items.length);
    }
    list = groups.flatMap((g) => g.items);
    grid.innerHTML = '';
    favBtns.clear();
    $('emptyFav').hidden = !(filter === 'fav' && list.length === 0);
    let n = 0;
    groups.forEach((g) => {
      if (g.title) {
        // titulná fotka sekcie (inak prvá fotka) je pozadím nadpisu
        const cover = photos.find((p) => p.id === g.cover && p.s === g.id) || g.items[0];
        const h = document.createElement('h2');
        h.className = 'sec-title' + (g.id && cover ? ' hero' : '');
        h.dataset.sec = g.id;
        h.innerHTML = '<span></span><small></small>';
        h.lastChild.textContent = g.id ? `${g.items.length} ${g.items.length === 1 ? 'fotka' : g.items.length < 5 ? 'fotky' : 'fotiek'}` : g.items.length;
        h.firstChild.textContent = g.title;
        if (g.id && cover) h.style.setProperty('--img', `url(${src(cover, 'view')})`);
        reveal(h);
        grid.appendChild(h);
      }
      grid.appendChild(masonry(g.items.map((p) => makeTile(p, n++)), g.items));
    });
    updateCounts();
    updateSectionDownload();
  }

  // v menu Stiahnuť sa pri vybranej sekcii objaví položka pre túto sekciu
  function updateSectionDownload() {
    const a = $('dlSec');
    const sec = filter === 'all' ? sections.find((s) => s.id === currentSec) : null;
    a.hidden = !sec;
    if (sec) {
      a.href = `${base}/zip?section=${sec.id}`;
      a.querySelector('b').textContent = `Sekcia „${sec.title}“`;
      a.querySelector('small').textContent = `${photos.filter((p) => p.s === sec.id).length} fotiek · ZIP`;
    }
  }

  async function toggleFav(p) {
    p.fav = !p.fav; // hneď, aby to nečakalo na server
    refreshFavUI(p, true);
    try {
      const r = await fetch(`${base}/fav/${p.id}`, { method: 'POST' });
      if (!r.ok) throw 0;
      p.fav = (await r.json()).favorite;
    } catch (_) {
      p.fav = !p.fav;
      toast('Nepodarilo sa uložiť, skús to znova.');
    }
    refreshFavUI(p);
    if (filter === 'fav' && lb.hidden) render();
  }

  function refreshFavUI(p, animate = false) {
    const btn = favBtns.get(p);
    const lbBtn = $('lbFav');
    if (btn) {
      btn.classList.toggle('on', p.fav);
      if (animate && p.fav) { btn.classList.remove('pop-anim'); void btn.offsetWidth; btn.classList.add('pop-anim'); }
    }
    if (!lb.hidden && list[idx] === p) {
      lbBtn.classList.toggle('on', p.fav);
      lbBtn.querySelector('span').textContent = p.fav ? 'V obľúbených' : 'Obľúbené';
    }
    updateCounts();
  }

  function show() {
    const p = list[idx];
    if (!p) return close();
    lbImg.classList.add('swap');
    const next = new Image();
    next.onload = () => { lbImg.src = next.src; lbImg.classList.remove('swap'); };
    next.src = src(p, 'view');
    $('lbCount').textContent = `${idx + 1} / ${list.length}`;
    $('lbPrev').hidden = $('lbNext').hidden = list.length < 2; // pri jednej fotke nie je kam listovať
    $('lbDl').href = `${src(p, 'full')}?dl=1`;
    if (typeof resetDl === 'function') resetDl();
    refreshFavUI(p);
    [idx + 1, idx - 1].forEach((j) => list[j] && (new Image().src = src(list[j], 'view')));
    history.replaceState(null, '', `?p=${p.id}`);
  }

  function open(i) { idx = i; lb.hidden = false; document.body.style.overflow = 'hidden'; show(); }
  function close() {
    lb.hidden = true; document.body.style.overflow = '';
    history.replaceState(null, '', location.pathname);
    if (filter === 'fav') render();
  }
  const step = (d) => { idx = (idx + d + list.length) % list.length; show(); };

  $('lbClose').onclick = close;
  $('lbPrev').onclick = () => step(-1);
  $('lbNext').onclick = () => step(1);
  $('lbFav').onclick = () => toggleFav(list[idx]);
  $('lbShare').onclick = async () => {
    const url = `${location.origin}${location.pathname}?p=${list[idx].id}`;
    if (navigator.share) {
      try { await navigator.share({ title: document.title, url }); return; } catch (e) { if (e.name === 'AbortError') return; }
    }
    try { await navigator.clipboard.writeText(url); toast('Odkaz skopírovaný'); }
    catch (_) { prompt('Skopíruj odkaz:', url); }
  };
  // Na mobile otvorí stiahnutie systémové menu zdieľania, kde je „Uložiť obrázok“ (iPhone) / „Uložiť“ do Fotiek.
  // Obyčajný odkaz na stiahnutie ukladá do Súborov. ZIP sa do Fotiek uložiť nedá, to je obmedzenie telefónu.
  const touch = matchMedia('(pointer: coarse)').matches;
  const dlLabel = $('lbDl').querySelector('span');
  const dlText = dlLabel.textContent;
  let dlReady = null; // { id, file }: stiahnutá fotka čaká na druhé ťuknutie, ak telefón nepustil zdieľanie hneď
  $('lbDl').addEventListener('click', async (e) => {
    if (!touch || !navigator.canShare || !navigator.share) return; // obyčajné stiahnutie
    const p = list[idx];
    e.preventDefault();
    try {
      let file = dlReady && dlReady.id === p.id ? dlReady.file : null;
      if (!file) {
        dlLabel.textContent = 'Pripravujem…';
        const blob = await (await fetch(src(p, 'full'))).blob();
        file = new File([blob], `attelierkay-${p.id}.jpg`, { type: blob.type || 'image/jpeg' });
      }
      if (!navigator.canShare({ files: [file] })) throw new Error('nepodporene');
      try {
        await navigator.share({ files: [file] });
        dlReady = null;
        dlLabel.textContent = dlText;
      } catch (err) {
        if (err.name === 'AbortError') { dlReady = null; dlLabel.textContent = dlText; return; }
        if (err.name === 'NotAllowedError') { // zdieľať sa smie len hneď po ťuknutí: fotka je už stiahnutá, stačí ťuknúť znova
          dlReady = { id: p.id, file };
          dlLabel.textContent = 'Uložiť do Fotiek';
          toast('Ťukni ešte raz na „Uložiť do Fotiek“');
          return;
        }
        throw err;
      }
    } catch (_) { // záloha: bežné stiahnutie
      dlLabel.textContent = dlText;
      location.href = `${src(p, 'full')}?dl=1`;
    }
  });
  // Viac fotiek naraz do Fotiek (zobrazený filter: všetky / sekcia / obľúbené), po 10. Dva kroky, lebo
  // telefón dovolí zdieľať len hneď po ťuknutí: 1. stiahne sa dávka, 2. ťuknutím sa otvorí zdieľanie.
  const BATCH = 10;
  const dlPhotos = $('dlPhotos'), phBar = $('phBar'), phGo = $('phGo'), phText = $('phText');
  let ph = null; // { items, pos, files }
  const canShareFiles = () => { try { return touch && navigator.canShare && navigator.canShare({ files: [new File([''], 'a.jpg', { type: 'image/jpeg' })] }); } catch (_) { return false; } };
  if (canShareFiles()) dlPhotos.hidden = false;

  async function phPrepare() {
    const chunk = ph.items.slice(ph.pos, ph.pos + BATCH);
    phGo.disabled = true;
    phGo.textContent = 'Sťahujem…';
    phText.textContent = `Fotky ${ph.pos + 1}–${ph.pos + chunk.length} z ${ph.items.length}`;
    try {
      ph.files = await Promise.all(chunk.map(async (p) => {
        const blob = await (await fetch(src(p, 'full'))).blob();
        return new File([blob], `attelierkay-${p.id}.jpg`, { type: blob.type || 'image/jpeg' });
      }));
      phGo.disabled = false;
      phGo.textContent = `Uložiť ${chunk.length} ${chunk.length === 1 ? 'fotku' : chunk.length < 5 ? 'fotky' : 'fotiek'} do Fotiek`;
    } catch (_) {
      phBar.hidden = true;
      toast('Sťahovanie zlyhalo, skús to znova.');
    }
  }
  dlPhotos.addEventListener('click', (e) => {
    e.preventDefault();
    const items = list.slice();
    if (!items.length) return;
    ph = { items, pos: 0, files: [] };
    phBar.hidden = false;
    phPrepare();
  });
  phGo.onclick = async () => {
    if (!ph || !ph.files.length) return;
    try {
      await navigator.share({ files: ph.files });
    } catch (err) {
      if (err.name === 'AbortError') return; // zrušené, dá sa ťuknúť znova
      toast('Uloženie sa nepodarilo, skús to znova.');
      return;
    }
    ph.pos += ph.files.length;
    ph.files = [];
    if (ph.pos >= ph.items.length) {
      phBar.hidden = true;
      toast('Hotovo, fotky sú vo Fotkách.');
    } else {
      phPrepare();
    }
  };
  $('phClose').onclick = () => { phBar.hidden = true; ph = null; };

  const resetDl = () => { dlReady = null; dlLabel.textContent = dlText; };

  // dvojklik / dvojitý ťuk na fotku = obľúbená
  lbImg.addEventListener('dblclick', () => toggleFav(list[idx]));

  document.addEventListener('keydown', (e) => {
    if (lb.hidden) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowRight') step(1);
    else if (e.key === 'ArrowLeft') step(-1);
  });

  let sx = 0, sy = 0;
  $('stage').addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
  $('stage').addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - sx;
    const dy = e.changedTouches[0].clientY - sy;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) step(dx < 0 ? 1 : -1);
    else if (dy > 90 && Math.abs(dx) < 60) close(); // potiahnutie nadol zavrie
  });

  // Filter pod úvodnou fotkou: sekcie plynulo doskrolujú na svoje miesto, „Obľúbené“ prepne zobrazenie.
  // Počas skrolovania sa zvýrazňuje sekcia, v ktorej práve si.
  const segBtns = [...document.querySelectorAll('.seg-btn')];
  const headH = () => $('site').offsetHeight + $('filters').offsetHeight;
  let spyLock = 0;
  const goTo = (el) => {
    spyLock = Date.now() + 900; // kým skrolovanie beží, zvýraznenie nemeníme
    window.scrollTo({ top: Math.max(0, el.getBoundingClientRect().top + window.scrollY - headH() - 8), behavior: 'smooth' });
  };
  function setActive(key) {
    const act = segBtns.find((x) => x.dataset.filter === String(key));
    if (!act || act.classList.contains('active')) return;
    segBtns.forEach((x) => x.classList.toggle('active', x === act));
    const bar = act.parentElement; // len posun filtra do strán, stránka sa nehýbe
    bar.scrollTo({ left: act.offsetLeft - (bar.clientWidth - act.offsetWidth) / 2, behavior: 'smooth' });
  }
  segBtns.forEach((b) => b.addEventListener('click', () => {
    const f = b.dataset.filter;
    const next = f === 'fav' ? 'fav' : 'all';
    if (filter !== next) { filter = next; render(); }
    if (f === 'fav' || f === 'all') currentSec = null;
    else currentSec = Number(f);
    setActive(f);
    updateSectionDownload();
    const target = f === 'fav' || f === 'all' ? grid : grid.querySelector(`[data-sec="${f}"]`);
    if (target) goTo(target);
  }));
  addEventListener('scroll', () => {
    if (filter !== 'all' || !sections.length || Date.now() < spyLock) return;
    let key = 'all';
    grid.querySelectorAll('[data-sec]').forEach((h) => {
      if (h.getBoundingClientRect().top <= headH() + 80) key = h.dataset.sec === '0' ? 'all' : h.dataset.sec;
    });
    const sec = key === 'all' ? null : Number(key);
    if (sec !== currentSec) { currentSec = sec; setActive(key); updateSectionDownload(); }
  }, { passive: true });

  // filtre sa dajú posúvať do strán: ak sa nezmestia, ukáž to zatmaveným okrajom
  const seg = $('seg');
  const updateSegFade = () => seg.classList.toggle('more', seg.scrollWidth - seg.clientWidth - seg.scrollLeft > 4);
  seg.addEventListener('scroll', updateSegFade, { passive: true });
  addEventListener('resize', updateSegFade);
  updateSegFade();

  // Vstavaný prehliadač Facebooku, Messengera, Instagramu… súbory sťahovať nevie (na iPhone sa nestane nič).
  // Rozpoznáme ho, upozorníme klienta a ponúkneme skopírovanie odkazu do Safari/Chrome.
  const inApp = /FBAN|FBAV|FB_IAB|FBIOS|FBSS|Instagram|MessengerForiOS|Messenger|Line\/|MicroMessenger|Snapchat|TikTok|BytedanceWebview|LinkedInApp|Twitter|GSA\//i.test(navigator.userAgent);
  if (inApp) {
    const sheet = $('inappSheet');
    $('inappBar').hidden = false;
    const openHelp = () => { sheet.hidden = false; };
    $('inappHow').onclick = openHelp;
    $('inappClose').onclick = () => { sheet.hidden = true; };
    sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.hidden = true; });
    $('inappCopy').onclick = async () => {
      const url = location.origin + location.pathname;
      try { await navigator.clipboard.writeText(url); toast('Odkaz skopírovaný, vlož ho do Safari'); sheet.hidden = true; }
      catch (_) { prompt('Skopíruj odkaz a otvor ho v prehliadači:', url); }
    };
    // každé sťahovanie (fotka, ZIP) sa v tomto prehliadači len ukáže ako návod namiesto "nič sa nestalo"
    document.addEventListener('click', (e) => {
      const a = e.target.closest('#lbDl, .menu .pop a');
      if (!a) return;
      e.preventDefault();
      e.stopPropagation();
      openHelp();
    }, true);
  }

  // menu Stiahnuť sa zavrie kliknutím mimo alebo na položku
  const menu = $('dlMenu');
  document.addEventListener('click', (e) => { if (menu.open && !menu.contains(e.target)) menu.open = false; });
  menu.querySelectorAll('.pop a').forEach((a) => a.addEventListener('click', () => setTimeout(() => (menu.open = false), 150)));

  // úvodná fotka: pri skrolovaní text ide pomalšie než stránka a mizne, fotka za ním ešte pomalšie (paralaxa)
  const intro = document.querySelector('.intro');
  if (intro && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    let ticking = false;
    const parallax = () => {
      ticking = false;
      const y = window.scrollY, h = intro.offsetHeight;
      if (y > h) return;
      intro.style.setProperty('--ty', `${(y * 0.4).toFixed(1)}px`);
      intro.style.setProperty('--py', `${(y * 0.25).toFixed(1)}px`);
      intro.style.setProperty('--fade', Math.max(0, 1 - y / (h * 0.6)).toFixed(3));
    };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(parallax); } }, { passive: true });
  }

  // jemná čiara pod hlavičkou po odskrolovaní
  const site = $('site');
  const bar = $('filters');
  const onScroll = () => {
    site.classList.toggle('scrolled', window.scrollY > 8);
    bar.classList.toggle('stuck', bar.getBoundingClientRect().top <= site.offsetHeight + 1); // čiara pod filtrom, až keď sa prilepí
  };
  addEventListener('scroll', onScroll, { passive: true }); onScroll();

  render();
  const want = parseInt(new URLSearchParams(location.search).get('p') || '', 10);
  if (want) {
    const i = list.findIndex((p) => p.id === want);
    if (i >= 0) open(i);
  }
})();
