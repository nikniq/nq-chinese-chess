/* Chinese Chess UI. Rules come from engine.js (window.Xiangqi); the computer
 * thinks in a Web Worker running the same file, so the page stays responsive. */
(() => {
  'use strict';
  const X = window.Xiangqi;
  const canvas = document.getElementById('board');
  if (!X || !canvas) return;

  const NAMES = { K: '帥', A: '仕', B: '相', N: '傌', R: '俥', C: '炮', P: '兵',
                  k: '將', a: '士', b: '象', n: '馬', r: '車', c: '砲', p: '卒' };
  const EN = { k: 'General', a: 'Advisor', b: 'Elephant', n: 'Horse', r: 'Chariot', c: 'Cannon', p: 'Soldier' };
  const MAX_PLIES = 300;
  const SAVE_KEY = 'nqcc-game-v2';
  const LEVEL_NAMES = { 1: 'Easy', 2: 'Medium', 3: 'Hard' };

  const el = (id) => document.getElementById(id);
  const ctx = canvas.getContext('2d');
  const CELL = 60, PAD = 36, RADIUS = 26, W = PAD * 2 + 8 * CELL, H = PAD * 2 + 9 * CELL;

  // Crisp on high-DPI screens: draw in logical units, back the canvas at device pixels.
  const dpr = Math.min(window.devicePixelRatio || 1, 3);
  canvas.width = W * dpr; canvas.height = H * dpr;
  ctx.scale(dpr, dpr);

  //------------------------------------------------------------ state
  let S; // game state
  let selected = null, targets = [], thinking = false, anim = null, hintMove = null;

  function freshState() {
    return {
      board: X.START.map((r) => r.split('')),
      turn: 'r', history: [], positions: {},
      mode: el('mode').value, level: Number(el('difficulty').value),
      flipped: false, over: false, result: null,
      ranked: true, undos: 0, hints: 0, startedAt: Date.now(),
    };
  }
  const posKey = () => S.turn + S.board.map((r) => r.join('')).join('/');

  function save() {
    try { localStorage.setItem(SAVE_KEY, JSON.stringify(S)); } catch (e) { /* private mode */ }
  }
  function load() {
    try {
      const s = JSON.parse(localStorage.getItem(SAVE_KEY));
      if (s && s.board && !s.over && s.history.length) return s;
    } catch (e) { /* ignore */ }
    return null;
  }

  //------------------------------------------------------------ worker
  let worker = null, reqId = 0;
  const pending = {};
  try {
    worker = new Worker(window.APP.engineUrl);
    worker.onmessage = (e) => { const cb = pending[e.data.id]; delete pending[e.data.id]; cb && cb(e.data.move); };
    worker.onerror = () => { worker = null; };
  } catch (e) { worker = null; }

  function think(side, level, cb) {
    if (worker) { const id = ++reqId; pending[id] = cb; worker.postMessage({ id, board: S.board, side, level }); }
    else setTimeout(() => cb(X.bestMove(S.board, side, level)), 30); // fallback: same engine, on-page
  }

  //------------------------------------------------------------ sound
  let audio = null;
  function beep(freq, dur, type = 'triangle', vol = 0.15) {
    if (!el('sound').checked) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const o = audio.createOscillator(), g = audio.createGain();
      o.type = type; o.frequency.value = freq;
      g.gain.setValueAtTime(vol, audio.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + dur);
      o.connect(g).connect(audio.destination); o.start(); o.stop(audio.currentTime + dur);
    } catch (e) { /* no audio */ }
  }
  const sfx = {
    move: () => beep(520, 0.08, 'square', 0.06),
    capture: () => { beep(240, 0.12, 'sawtooth', 0.1); },
    check: () => { beep(880, 0.12); setTimeout(() => beep(1100, 0.15), 110); },
    win: () => [523, 659, 784, 1047].forEach((f, i) => setTimeout(() => beep(f, 0.2), i * 120)),
    lose: () => [392, 330, 262].forEach((f, i) => setTimeout(() => beep(f, 0.25), i * 150)),
  };

  //------------------------------------------------------------ drawing
  // Board coordinates → screen, honouring the flip.
  const view = (r, c) => (S.flipped ? [9 - r, 8 - c] : [r, c]);
  const xy = (r, c) => { const [vr, vc] = view(r, c); return [PAD + vc * CELL, PAD + vr * CELL]; };

  function line(x1, y1, x2, y2) { ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke(); }

  function drawBoard() {
    const g = ctx.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, '#efd29b'); g.addColorStop(1, '#e2bd7c');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = '#6b4220'; ctx.lineWidth = 1.5;
    const gx = (c) => PAD + c * CELL, gy = (r) => PAD + r * CELL;
    for (let r = 0; r < 10; r++) line(gx(0), gy(r), gx(8), gy(r));
    for (let c = 0; c < 9; c++) {
      if (c === 0 || c === 8) line(gx(c), gy(0), gx(c), gy(9));
      else { line(gx(c), gy(0), gx(c), gy(4)); line(gx(c), gy(5), gx(c), gy(9)); }
    }
    line(gx(3), gy(0), gx(5), gy(2)); line(gx(5), gy(0), gx(3), gy(2));
    line(gx(3), gy(7), gx(5), gy(9)); line(gx(5), gy(7), gx(3), gy(9));
    ctx.lineWidth = 3; ctx.strokeRect(gx(0) - 6, gy(0) - 6, CELL * 8 + 12, CELL * 9 + 12);
    // position marks for cannons and soldiers
    ctx.lineWidth = 1.2;
    const marks = [[2, 1], [2, 7], [7, 1], [7, 7], [3, 0], [3, 2], [3, 4], [3, 6], [3, 8], [6, 0], [6, 2], [6, 4], [6, 6], [6, 8]];
    for (const [r, c] of marks) {
      const x = gx(c), y = gy(r), d = 4, l = 9;
      for (const [sx, sy] of [[-1, -1], [1, -1], [-1, 1], [1, 1]]) {
        if ((c === 0 && sx < 0) || (c === 8 && sx > 0)) continue;
        ctx.beginPath(); ctx.moveTo(x + sx * d, y + sy * (d + l)); ctx.lineTo(x + sx * d, y + sy * d); ctx.lineTo(x + sx * (d + l), y + sy * d); ctx.stroke();
      }
    }
    ctx.fillStyle = 'rgba(107,66,32,.85)'; ctx.font = '28px "Kaiti SC","STKaiti","KaiTi",serif';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    const [left, right] = S.flipped ? ['漢 界', '楚 河'] : ['楚 河', '漢 界'];
    ctx.fillText(left, gx(2), PAD + 4.5 * CELL);
    ctx.fillText(right, gx(6), PAD + 4.5 * CELL);
  }

  function drawPiece(x, y, p, opts = {}) {
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,.35)'; ctx.shadowBlur = opts.lift ? 14 : 5; ctx.shadowOffsetY = opts.lift ? 6 : 2;
    ctx.beginPath(); ctx.arc(x, y, RADIUS, 0, Math.PI * 2);
    const g = ctx.createRadialGradient(x - 8, y - 8, 4, x, y, RADIUS);
    g.addColorStop(0, opts.selected ? '#fff8c8' : '#fffaf0'); g.addColorStop(1, opts.selected ? '#f3dc7a' : '#ead7b0');
    ctx.fillStyle = g; ctx.fill();
    ctx.restore();
    const col = X.isRed(p) ? '#b3261e' : '#1f1f1f';
    ctx.strokeStyle = col; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.arc(x, y, RADIUS, 0, Math.PI * 2); ctx.stroke();
    ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(x, y, RADIUS - 4, 0, Math.PI * 2); ctx.stroke();
    ctx.fillStyle = col; ctx.font = 'bold 28px "Kaiti SC","STKaiti","KaiTi",serif';
    ctx.fillText(NAMES[p], x, y + 1);
  }

  function ring(r, c, color, width = 3, rad = RADIUS + 4) {
    const [x, y] = xy(r, c);
    ctx.strokeStyle = color; ctx.lineWidth = width;
    ctx.beginPath(); ctx.arc(x, y, rad, 0, Math.PI * 2); ctx.stroke();
  }

  function draw() {
    drawBoard();
    const last = S.history.length ? S.history[S.history.length - 1].m : null;
    if (last) {
      const [x, y] = xy(last[0], last[1]);
      ctx.fillStyle = 'rgba(47,125,225,.25)'; ctx.beginPath(); ctx.arc(x, y, 9, 0, Math.PI * 2); ctx.fill();
      ring(last[2], last[3], 'rgba(47,125,225,.8)');
    }
    if (hintMove) { ring(hintMove[0], hintMove[1], '#e8a317', 4); ring(hintMove[2], hintMove[3], '#e8a317', 4, 10); }
    for (const s of ['r', 'b']) if (X.inCheck(S.board, s)) {
      const k = X.findKing(S.board, s);
      if (k) { const [x, y] = xy(k[0], k[1]); const g = ctx.createRadialGradient(x, y, 10, x, y, 40); g.addColorStop(0, 'rgba(220,30,30,.7)'); g.addColorStop(1, 'rgba(220,30,30,0)'); ctx.fillStyle = g; ctx.fillRect(x - 40, y - 40, 80, 80); }
    }
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) {
      const p = S.board[r][c];
      if (p === '.' || (anim && anim.m[2] === r && anim.m[3] === c)) continue;
      const [x, y] = xy(r, c);
      drawPiece(x, y, p, { selected: selected && selected[0] === r && selected[1] === c });
    }
    for (const [, , tr, tc] of targets) {
      const [x, y] = xy(tr, tc);
      if (S.board[tr][tc] === '.') { ctx.fillStyle = 'rgba(30,140,60,.6)'; ctx.beginPath(); ctx.arc(x, y, 8, 0, Math.PI * 2); ctx.fill(); }
      else ring(tr, tc, 'rgba(30,140,60,.9)', 4);
    }
    if (anim) {
      const t = Math.min(1, (performance.now() - anim.t0) / anim.ms), e = 1 - Math.pow(1 - t, 3);
      const [x0, y0] = xy(anim.m[0], anim.m[1]), [x1, y1] = xy(anim.m[2], anim.m[3]);
      drawPiece(x0 + (x1 - x0) * e, y0 + (y1 - y0) * e, anim.p, { lift: true });
    }
  }

  function animate(m, p, done) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { done(); return; }
    const a = anim = { m, p, t0: performance.now(), ms: 180, finished: false };
    // Finish exactly once, whether the last animation frame or the timer gets
    // there first. Browsers pause animation frames in background tabs; without
    // the timer, a move made just before switching tabs would never land.
    const finish = () => { if (a.finished) return; a.finished = true; if (anim === a) anim = null; done(); };
    const step = () => {
      if (a.finished) return;
      draw();
      if (performance.now() - a.t0 < a.ms) requestAnimationFrame(step); else finish();
    };
    requestAnimationFrame(step);
    setTimeout(finish, a.ms + 120);
  }

  //------------------------------------------------------------ panel
  function sideName(s) {
    if (S.mode === 'friend') return s === 'r' ? 'Red' : 'Black';
    return s === 'r' ? 'You (Red)' : 'Computer';
  }

  function updatePanel(text) {
    const check = !S.over && X.inCheck(S.board, S.turn);
    el('status').textContent = text || (S.over ? el('status').textContent
      : (thinking ? 'Computer is thinking…' : `${sideName(S.turn)} to move`) + (check ? ' — check!' : ''));
    el('status').className = check ? 'status check' : 'status';
    el('moveCount').textContent = Math.ceil(S.history.length / 2);
    el('difficulty').disabled = S.history.length > 0 || S.mode === 'friend';
    el('mode').disabled = S.history.length > 0;
    el('undo').disabled = thinking || S.over || S.history.length === 0;
    el('hint').disabled = thinking || S.over || (S.mode === 'computer' && S.turn !== 'r');
    el('resign').disabled = S.over || S.history.length === 0;

    const badge = el('ranked');
    if (S.mode === 'friend') { badge.textContent = 'Friendly game · not scored'; badge.className = 'badge'; }
    else if (!window.APP.loggedIn) { badge.textContent = `Guest · ${LEVEL_NAMES[S.level]} · not saved`; badge.className = 'badge'; }
    else if (S.ranked) { badge.textContent = `Ranked · ${LEVEL_NAMES[S.level]} · win = ${10 * S.level} pts`; badge.className = 'badge ok'; }
    else { badge.textContent = `Unranked · ${S.undos ? 'undo' : 'hint'} used`; badge.className = 'badge warn'; }

    // captured pieces
    const caps = { r: [], b: [] };
    for (const h of S.history) if (h.cap !== '.') caps[X.side(h.cap)].push(h.cap);
    const fmt = (arr) => arr.sort((a, b) => 'rcnbapk'.indexOf(a.toLowerCase()) - 'rcnbapk'.indexOf(b.toLowerCase()))
      .map((p) => `<span class="cap ${X.isRed(p) ? 'red' : 'blk'}" title="${EN[p.toLowerCase()]}">${NAMES[p]}</span>`).join('');
    el('capByRed').innerHTML = fmt(caps.b) || '<span class="muted">—</span>';
    el('capByBlack').innerHTML = fmt(caps.r) || '<span class="muted">—</span>';

    const list = el('moveList');
    list.innerHTML = '';
    for (let i = 0; i < S.history.length; i += 2) {
      const li = document.createElement('li');
      li.textContent = [S.history[i], S.history[i + 1]].filter(Boolean).map((h) => h.n).join('   ');
      list.appendChild(li);
    }
    list.scrollTop = 1e9;
  }

  const sq = (r, c) => `${String.fromCharCode(97 + c)}${9 - r}`;

  //------------------------------------------------------------ game flow
  function apply(m, after) {
    const p = S.board[m[0]][m[1]];
    hintMove = null;
    animate(m, p, () => {
      const cap = X.makeMove(S.board, m);
      S.history.push({ m, cap, n: `${NAMES[p]}${sq(m[0], m[1])}${cap !== '.' ? '×' : '-'}${sq(m[2], m[3])}` });
      S.turn = X.other(S.turn);
      S.positions[posKey()] = (S.positions[posKey()] || 0) + 1;
      if (X.inCheck(S.board, S.turn)) sfx.check(); else if (cap !== '.') sfx.capture(); else sfx.move();
      save();
      draw();
      if (!checkEnd()) { updatePanel(); after && after(); }
    });
  }

  function checkEnd() {
    if (X.legalMoves(S.board, S.turn).length === 0) {
      // Checkmate or stalemate: in xiangqi the side with no legal move loses.
      const winner = X.other(S.turn);
      const how = X.inCheck(S.board, S.turn) ? 'Checkmate' : 'Stalemate';
      if (S.mode === 'friend') finish('draw', `${how} — ${winner === 'r' ? 'Red' : 'Black'} wins!`, winner === 'r' ? 'win' : 'loss');
      else finish(winner === 'r' ? 'win' : 'loss', winner === 'r' ? `${how} — you win!` : `${how} — the computer wins.`);
      return true;
    }
    if (S.positions[posKey()] >= 3) { finish('draw', 'Draw by threefold repetition.'); return true; }
    if (S.history.length >= MAX_PLIES) { finish('draw', `Draw — ${MAX_PLIES / 2} moves each without a result.`); return true; }
    return false;
  }

  function finish(result, message, sound) {
    S.over = true; S.result = result; selected = null; targets = [];
    save(); draw();
    updatePanel(message);
    ({ win: sfx.win, loss: sfx.lose }[sound || result] || (() => {}))();
    showResult(result, message);
    if (S.mode === 'computer') saveResult(result);
  }

  function showResult(result, message) {
    el('resultTitle').textContent = message;
    el('resultIcon').textContent = S.mode === 'friend' ? '🤝' : { win: '🏆', loss: '♟', draw: '🤝' }[result];
    el('resultScore').textContent = '';
    el('resultDialog').hidden = false;
  }

  async function saveResult(result) {
    const out = el('resultScore');
    if (window.APP.unverified) {
      out.innerHTML = `<a href="${window.APP.verifyUrl}">Verify your email</a> to save games like this.`;
      return;
    }
    if (!window.APP.loggedIn) {
      out.innerHTML = `Sign in to put games like this on the <a href="${window.APP.leaderboardUrl}">leaderboard</a>.`;
      return;
    }
    if (!S.ranked) { out.textContent = 'Not scored: undo or hint was used this game.'; return; }
    const moves = Math.ceil(S.history.length / 2);
    if (moves < 1) return;
    out.textContent = 'Saving…';
    try {
      const res = await fetch(window.APP.saveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.APP.csrf },
        body: JSON.stringify({ result, difficulty: S.level, moves, duration_seconds: Math.round((Date.now() - S.startedAt) / 1000) }),
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      out.textContent = `+${data.points} points · total ${data.total}`;
      el('scoreTotal') && (el('scoreTotal').textContent = data.total);
    } catch (e) {
      out.textContent = 'Could not save the result (' + e.message + ').';
    }
  }

  function computerTurn() {
    if (S.over || S.mode !== 'computer' || S.turn !== 'b') return;
    thinking = true; updatePanel();
    const started = Date.now();
    think('b', S.level, (m) => {
      // A short minimum pause, so an instant reply doesn't feel like a glitch.
      setTimeout(() => {
        thinking = false;
        if (S.over || !m) { updatePanel(); return; }
        apply(m);
      }, Math.max(0, 350 - (Date.now() - started)));
    });
  }

  function newGame() {
    S = freshState();
    selected = null; targets = []; hintMove = null; thinking = false;
    S.positions[posKey()] = 1;
    S.flipped = false;
    el('resultDialog').hidden = true;
    save(); draw(); updatePanel();
  }

  //------------------------------------------------------------ input
  function boardSquare(evt) {
    const rect = canvas.getBoundingClientRect();
    const x = (evt.clientX - rect.left) * (W / rect.width), y = (evt.clientY - rect.top) * (H / rect.height);
    const vc = Math.round((x - PAD) / CELL), vr = Math.round((y - PAD) / CELL);
    if (!X.inBoard(vr, vc)) return null;
    return S.flipped ? [9 - vr, 8 - vc] : [vr, vc];
  }

  const humanTurn = () => !S.over && !thinking && !anim && (S.mode === 'friend' || S.turn === 'r');

  canvas.addEventListener('click', (evt) => {
    if (!humanTurn()) return;
    const sqr = boardSquare(evt);
    if (!sqr) return;
    const [r, c] = sqr;
    const mv = targets.find((m) => m[2] === r && m[3] === c);
    if (mv) { selected = null; targets = []; apply(mv, computerTurn); return; }
    if (X.side(S.board[r][c]) === S.turn) {
      selected = [r, c];
      targets = X.legalMoves(S.board, S.turn).filter((m) => m[0] === r && m[1] === c);
      if (!targets.length) beep(180, 0.08, 'square', 0.05);
    } else { selected = null; targets = []; }
    draw();
  });

  el('newGame').addEventListener('click', () => {
    if (!S.over && S.history.length > 1 && !confirm('Abandon the current game?')) return;
    newGame();
  });
  el('again').addEventListener('click', newGame);
  el('closeResult').addEventListener('click', () => { el('resultDialog').hidden = true; });

  el('undo').addEventListener('click', () => {
    if (thinking || S.over || !S.history.length) return;
    // vs computer: take back the computer's reply and your move together.
    const n = S.mode === 'computer' ? (S.turn === 'r' ? 2 : 1) : 1;
    for (let i = 0; i < n && S.history.length; i++) {
      const k = posKey(); if (S.positions[k]) S.positions[k]--;
      const { m, cap } = S.history.pop();
      X.unmakeMove(S.board, m, cap);
      S.turn = X.other(S.turn);
    }
    if (S.mode === 'computer') { S.undos++; S.ranked = false; }
    selected = null; targets = []; hintMove = null;
    save(); draw(); updatePanel();
  });

  el('hint').addEventListener('click', () => {
    if (!humanTurn()) return;
    el('hint').disabled = true;
    think(S.turn, 2, (m) => {
      hintMove = m;
      if (S.mode === 'computer') { S.hints++; S.ranked = false; }
      save(); draw(); updatePanel();
    });
  });

  el('resign').addEventListener('click', () => {
    if (S.over || !S.history.length || !confirm('Resign this game?')) return;
    if (S.mode === 'friend') finish('draw', `${S.turn === 'r' ? 'Red' : 'Black'} resigned.`);
    else finish('loss', 'You resigned.');
  });

  el('flip').addEventListener('click', () => { S.flipped = !S.flipped; save(); draw(); });
  el('mode').addEventListener('change', () => { if (!S.history.length) newGame(); });
  el('difficulty').addEventListener('change', () => { if (!S.history.length) { S.level = Number(el('difficulty').value); save(); updatePanel(); } });
  try { el('sound').checked = localStorage.getItem('nqcc-sound') !== 'off'; } catch (e) { /* */ }
  el('sound').addEventListener('change', () => { try { localStorage.setItem('nqcc-sound', el('sound').checked ? 'on' : 'off'); } catch (e) { /* */ } });

  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, select, textarea')) return;
    if (e.key === 'u') el('undo').click();
    else if (e.key === 'h') el('hint').click();
    else if (e.key === 'f') el('flip').click();
    else if (e.key === 'Escape') { selected = null; targets = []; el('resultDialog').hidden = true; draw(); }
  });

  //------------------------------------------------------------ start
  const resumed = load();
  if (resumed) {
    S = resumed;
    el('mode').value = S.mode; el('difficulty').value = String(S.level);
    el('resumeNote').hidden = false;
    draw(); updatePanel();
    if (S.mode === 'computer' && S.turn === 'b') computerTurn();
  } else {
    newGame();
  }
})();
