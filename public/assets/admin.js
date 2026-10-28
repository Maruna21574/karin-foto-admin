(() => {
  const body = document.body;
  const csrf = body.dataset.csrf;
  const base = body.dataset.base;
  const imgBase = body.dataset.img;
  const MAX_PX = parseInt(body.dataset.maxpx || '4000', 10);
  const $ = (id) => document.getElementById(id);
  const G = window.GALLERY; // { photos, sections, cover }

  let tab = 'all';            // 'all' | 'none' | id sekcie
  const selected = new Set();  // vybrané fotky
  let lastClicked = null;
  const clearSel = () => { selected.clear(); lastClicked = null; };
  const tiles = new Map();     // id fotky -> element (neprekresľujeme, aby neblikali náhľady)

  // náhľad pri zdieľaní sa po zmene titulnej fotky / nahrávaní / mazaní načíta znova
  let ogTimer = 0;
  function refreshOg() {
    clearTimeout(ogTimer);
    ogTimer = setTimeout(() => { $('ogPreview').src = `${body.dataset.og}?_=${Date.now()}`; }, 500);
  }

  /* ---------------- pomocné ---------------- */
  async function post(path, data = {}) {
    const fd = new FormData();
    fd.append('_csrf', csrf);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    try {
      const r = await fetch(`${base}/${path}`, { method: 'POST', body: fd });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) { alert(j.error || 'Akcia zlyhala.'); return null; }
      return j;
    } catch (_) {
      alert('Sieťová chyba, skús to znova.');
      return null;
    }
  }
  const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }) || a.id - b.id;
  const secTitle = (id) => (G.sections.find((s) => s.id === id) || {}).title || '';
  const inTab = (p) => tab === 'all' || (tab === 'none' ? p.s === 0 : p.s === Number(tab));
  const visible = () => G.photos.filter(inTab).sort(byName);
  const uploadTarget = () => (tab === 'all' || tab === 'none' ? 0 : Number(tab));
  const plural = (n) => (n === 1 ? 'fotka' : n >= 2 && n <= 4 ? 'fotky' : 'fotiek');

  /* ---------------- záložky sekcií ---------------- */
  function renderTabs() {
    if (tab !== 'all' && tab !== 'none' && !G.sections.some((s) => s.id === Number(tab))) tab = 'all';
    if (tab === 'none' && !G.sections.length) tab = 'all';
    const el = $('tabs');
    el.innerHTML = '';
    const add = (key, label, n) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'seg-btn' + (String(tab) === String(key) ? ' active' : '');
      b.innerHTML = '<span class="lbl"></span> <span class="n"></span>';
      b.querySelector('.lbl').textContent = label;
      b.querySelector('.n').textContent = n;
      b.addEventListener('click', () => { tab = key; clearSel(); renderAll(); });
      el.appendChild(b);
    };
    add('all', 'Všetky', G.photos.length);
    if (G.sections.length) add('none', 'Bez sekcie', G.photos.filter((p) => p.s === 0).length);
    G.sections.forEach((s) => add(s.id, s.title, G.photos.filter((p) => p.s === s.id).length));
    const numeric = tab !== 'all' && tab !== 'none';
    $('secBar').hidden = !numeric;
    if (numeric) {
      $('secName').textContent = secTitle(Number(tab));
      const i = G.sections.findIndex((s) => s.id === Number(tab));
      document.querySelector('[data-sact="up"]').disabled = i <= 0;
      document.querySelector('[data-sact="down"]').disabled = i === G.sections.length - 1;
    }
  }

  /* ---------------- mriežka fotiek ---------------- */
  function makeTile(p) {
    const fig = document.createElement('figure');
    fig.className = 'atile';
    fig.dataset.id = p.id;
    const ratio = p.w / p.h;
    fig.style.flex = `${ratio * 150} 1 ${ratio * 150}px`;
    fig.innerHTML =
      `<i style="padding-bottom:${(100 / ratio).toFixed(3)}%"></i><img loading="lazy" alt="" src="${imgBase}/${p.id}/thumb">` +
      '<span class="check"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>' +
      '<span class="heart"><svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 017-2.6A4 4 0 0119 10c0 5.6-7 10-7 10z"/></svg></span>' +
      '<span class="badge">titulná</span>' +
      '<span class="badge sec-badge">titulná sekcie</span>' +
      '<button type="button" class="cover-btn">Nastaviť ako titulnú</button>';
    fig.addEventListener('click', (e) => onTileClick(e, p.id));
    fig.querySelector('.cover-btn').addEventListener('click', async (e) => {
      e.stopPropagation();
      const sec = G.sections.find((x) => x.id === Number(tab));
      if (sec) { // v sekcii sa nastavuje titulná fotka sekcie
        const j = await post(`section/${sec.id}/cover`, { photo_id: sec.cover === p.id ? 0 : p.id });
        if (j) { sec.cover = j.cover; syncTiles(); }
        return;
      }
      const j = await post(`photo/${p.id}/cover`);
      if (j) { G.cover = j.cover; syncTiles(); refreshOg(); }
    });
    return fig;
  }

  function syncTiles() {
    tiles.forEach((el, id) => {
      const p = G.photos.find((x) => x.id === id);
      if (!p) return;
      el.classList.toggle('sel', selected.has(id));
      el.querySelector('.heart').hidden = !p.fav;
      const sec = G.sections.find((x) => x.id === Number(tab));
      el.querySelector('.badge:not(.sec-badge)').hidden = !!sec || G.cover !== id;
      el.querySelector('.sec-badge').hidden = !sec || sec.cover !== id;
      el.querySelector('.cover-btn').textContent = sec ? (sec.cover === id ? 'Zrušiť titulnú sekcie' : 'Titulná fotka sekcie') : 'Nastaviť ako titulnú';
    });
  }

  function groupHeading(text, n) {
    const h = document.createElement('h3');
    h.className = 'group-title';
    h.innerHTML = '<span></span> <small></small>';
    h.firstChild.textContent = text;
    h.lastChild.textContent = n;
    return h;
  }

  function renderGrid() {
    const vis = visible();
    const grid = $('photoGrid');
    // zmazané fotky odstránime z cache
    const alive = new Set(G.photos.map((p) => p.id));
    tiles.forEach((el, id) => { if (!alive.has(id)) tiles.delete(id); });

    const frag = document.createDocumentFragment();
    const group = (items) => {
      const wrap = document.createElement('div');
      wrap.className = 'thumbs';
      items.forEach((p) => {
        if (!tiles.has(p.id)) tiles.set(p.id, makeTile(p));
        wrap.appendChild(tiles.get(p.id));
      });
      return wrap;
    };
    if (tab === 'all' && G.sections.length) {
      [...G.sections.map((s) => ({ title: s.title, id: s.id })), { title: 'Bez sekcie', id: 0 }].forEach((g) => {
        const items = vis.filter((p) => p.s === g.id);
        if (!items.length) return;
        frag.appendChild(groupHeading(g.title, items.length));
        frag.appendChild(group(items));
      });
    } else if (vis.length) {
      frag.appendChild(group(vis));
    }
    grid.replaceChildren(frag);
    if (!vis.length) {
      const empty = document.createElement('p');
      empty.className = 'empty';
      empty.textContent = tab === 'all' ? 'Zatiaľ tu nie sú žiadne fotky. Pretiahni ich do poľa vyššie.' : 'V tejto sekcii zatiaľ nie sú žiadne fotky.';
      grid.appendChild(empty);
    }
    const favs = vis.filter((p) => p.fav).length;
    $('countTitle').textContent = `${vis.length} ${plural(vis.length)}` + (favs ? `, z toho ${favs} obľúbených` : '');
    $('selectAll').hidden = !vis.length;
    syncTiles();
  }

  /* ---------------- výber ---------------- */
  function onTileClick(e, id) {
    const order = visible().map((p) => p.id);
    if (e.shiftKey && lastClicked !== null && order.includes(lastClicked)) {
      const a = order.indexOf(lastClicked), b = order.indexOf(id);
      order.slice(Math.min(a, b), Math.max(a, b) + 1).forEach((x) => selected.add(x));
    } else if (selected.has(id)) {
      selected.delete(id);
    } else {
      selected.add(id);
    }
    lastClicked = id;
    renderSelection();
  }

  function renderSelection() {
    syncTiles();
    $('selbar').hidden = selected.size === 0;
    $('selCount').textContent = selected.size;
    const vis = visible();
    $('selectAll').textContent = vis.length && vis.every((p) => selected.has(p.id)) ? 'Zrušiť výber' : 'Vybrať všetky';
    const sel = $('moveTo');
    const prev = sel.value;
    sel.innerHTML = '<option value="0">Bez sekcie</option>';
    G.sections.forEach((s) => {
      const o = document.createElement('option');
      o.value = s.id; o.textContent = s.title;
      sel.appendChild(o);
    });
    if ([...sel.options].some((o) => o.value === prev)) sel.value = prev;
  }

  $('selectAll').onclick = () => {
    const vis = visible();
    const all = vis.every((p) => selected.has(p.id));
    vis.forEach((p) => (all ? selected.delete(p.id) : selected.add(p.id)));
    renderSelection();
  };
  $('clearSel').onclick = () => { clearSel(); renderSelection(); };
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && selected.size) { clearSel(); renderSelection(); } });

  $('moveBtn').onclick = async () => {
    const ids = [...selected];
    const section = Number($('moveTo').value);
    const j = await post('photos/move', { ids: ids.join(','), section_id: section });
    if (!j) return;
    G.photos.forEach((p) => { if (ids.includes(p.id)) p.s = j.section_id; });
    clearSel();
    renderAll();
  };
  $('delBtn').onclick = async () => {
    const ids = [...selected];
    if (!confirm(`Naozaj zmazať ${ids.length} ${plural(ids.length)}? Nedá sa to vrátiť späť.`)) return;
    const j = await post('photos/delete', { ids: ids.join(',') });
    if (!j) return;
    G.photos = G.photos.filter((p) => !j.ids.includes(p.id));
    G.cover = j.cover || 0;
    clearSel();
    renderAll();
    refreshOg();
  };

  /* ---------------- sekcie (bez obnovenia stránky) ---------------- */
  $('addSecBtn').onclick = () => { $('addSecForm').hidden = false; $('addSecForm').title.focus(); };
  $('addSecCancel').onclick = () => { $('addSecForm').hidden = true; };
  $('addSecForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const title = e.target.title.value.trim();
    if (!title) return;
    const j = await post('section/add', { title });
    if (!j) return;
    G.sections.push({ id: j.id, title: j.title, cover: 0 });
    e.target.title.value = '';
    e.target.hidden = true;
    tab = j.id; clearSel();
    renderAll();
  });
  document.querySelectorAll('[data-sact]').forEach((btn) => btn.addEventListener('click', async () => {
    const id = Number(tab);
    const act = btn.dataset.sact;
    const i = G.sections.findIndex((s) => s.id === id);
    if (i < 0) return;
    if (act === 'rename') {
      const title = prompt('Nový názov sekcie:', G.sections[i].title);
      if (!title || !title.trim()) return;
      if (await post(`section/${id}/rename`, { title: title.trim() })) G.sections[i].title = title.trim();
    } else if (act === 'delete') {
      if (!confirm('Zmazať sekciu? Fotky z nej ostanú v galérii, len bez sekcie.')) return;
      if (await post(`section/${id}/delete`)) {
        G.photos.forEach((p) => { if (p.s === id) p.s = 0; });
        G.sections.splice(i, 1);
        tab = G.sections.length ? 'none' : 'all';
      }
    } else {
      const j = i + (act === 'up' ? -1 : 1);
      if (G.sections[j] && await post(`section/${id}/${act}`)) {
        [G.sections[i], G.sections[j]] = [G.sections[j], G.sections[i]];
      }
    }
    renderAll();
  }));

  /* ---------------- nahrávanie ---------------- */
  // Veľké fotky zmenšíme už v prehliadači: ušetrí to nahrávanie aj pamäť servera.
  async function shrink(file) {
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const long = Math.max(bmp.width, bmp.height);
      if (long <= MAX_PX && file.size <= 12 * 1048576) { bmp.close(); return file; }
      const k = Math.min(1, MAX_PX / long);
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k);
      c.height = Math.round(bmp.height * k);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      bmp.close();
      const blob = await new Promise((ok) => c.toBlob(ok, 'image/jpeg', 0.9));
      if (!blob) return file;
      return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch (_) {
      return file; // prehliadač to nezvládol, nech to zmenší server
    }
  }

  const drop = $('drop'), input = $('fileInput'), queue = $('queue');
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', (e) => start(e.dataTransfer.files));
  input.addEventListener('change', () => { start(input.files); input.value = ''; });

  function start(fileList) {
    const files = [...fileList].filter((f) => /^image\/(jpeg|png|webp)$/.test(f.type));
    if (!files.length) return;
    const section = uploadTarget(); // sekcia platí pre celú dávku, aj keď medzitým prepneš záložku
    const items = files.map((f) => {
      const el = document.createElement('div');
      el.className = 'q-item';
      el.innerHTML = '<span></span><div class="q-bar"><i></i></div>';
      el.firstChild.textContent = f.name;
      queue.appendChild(el);
      return { f, el };
    });
    let next = 0, active = 0, done = 0, added = false;
    const pump = () => { while (active < 2 && next < items.length) { active++; send(items[next++]); } };

    async function send({ f, el }) {
      el.firstChild.textContent = `${f.name} (zmenšujem…)`;
      const small = await shrink(f);
      el.firstChild.textContent = f.name;
      const fd = new FormData();
      fd.append('photo', small, small.name);
      fd.append('section_id', section);
      fd.append('_csrf', csrf);
      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${base}/upload`);
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) el.querySelector('i').style.width = (e.loaded / e.total * 100) + '%'; };
      xhr.onload = () => {
        let res = {};
        try { res = JSON.parse(xhr.responseText); } catch (_) {}
        if (xhr.status === 200 && res.photo) {
          G.photos.push(res.photo);
          G.cover = res.cover || G.cover;
          el.remove();                     // hotová fotka z fronty zmizne a objaví sa v mriežke
          scheduleRender();
        } else {
          el.classList.add('err');
          el.firstChild.textContent = `${f.name}: ${res.error || 'chyba ' + xhr.status}`;
          el.addEventListener('click', () => el.remove());
          el.title = 'Kliknutím skryješ';
        }
        finish();
      };
      xhr.onerror = () => { el.classList.add('err'); el.firstChild.textContent = `${f.name}: sieťová chyba`; finish(); };
      xhr.send(fd);
    }
    function finish() { active--; done++; pump(); }
    pump();
  }

  // viac dokončených nahraní naraz prekreslíme jedným ťahom (časovač beží aj na neaktívnej karte)
  let renderTimer = 0;
  function scheduleRender() {
    clearTimeout(renderTimer);
    renderTimer = setTimeout(renderAll, 40);
    refreshOg();
  }

  /* ---------------- vlastná adresa galérie ---------------- */
  const slugMsg = $('slugMsg');
  const slugDefault = slugMsg.textContent;
  const slugify = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  $('slugInput').addEventListener('input', (e) => {
    const pos = e.target.selectionStart;
    const clean = e.target.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-');
    if (clean !== e.target.value) { e.target.value = clean; e.target.setSelectionRange(pos, pos); }
  });
  $('slugRandom').onclick = () => {
    const input = $('slugInput');
    const rand = Math.random().toString(36).slice(2, 6);
    input.value = `${slugify(input.value) || 'galeria'}-${rand}`;
    input.focus();
  };
  $('slugSave').onclick = async () => {
    slugMsg.className = 'muted small';
    const j = await post('slug', { slug: $('slugInput').value });
    if (!j) { slugMsg.textContent = 'Adresu sa nepodarilo uložiť.'; slugMsg.classList.add('bad'); return; }
    $('slugInput').value = j.slug;
    $('clientLink').value = j.link;
    $('openLink').href = j.link;
    slugMsg.textContent = j.slug ? 'Adresa je uložená. Klientovi pošli odkaz z poľa vyššie.' : 'Vlastná adresa je zrušená, použije sa dlhý odkaz.';
    slugMsg.classList.add('ok');
    setTimeout(() => { slugMsg.textContent = slugDefault; slugMsg.className = 'muted small'; }, 5000);
  };

  /* ---------------- ostatné ---------------- */
  $('copyLink').onclick = async () => {
    const i = $('clientLink');
    try { await navigator.clipboard.writeText(i.value); } catch (_) { i.select(); document.execCommand('copy'); }
    $('copyLink').textContent = 'Skopírované ✓';
    setTimeout(() => ($('copyLink').textContent = 'Kopírovať'), 1800);
  };

  function renderAll() {
    renderTabs();
    $('dropTarget').textContent = tab === 'all' || tab === 'none'
      ? (G.sections.length ? '„Bez sekcie“' : '„Galéria“')
      : `„${secTitle(Number(tab))}“`;
    renderGrid();
    renderSelection();
  }
  renderAll();
})();
