/* Chinese Chess (Xiangqi) — board, rules and a simple alpha-beta AI.
 * Board: 10 rows x 9 cols, row 0 = top (Black), row 9 = bottom (Red).
 * Pieces: uppercase = Red, lowercase = Black.
 * K general, A advisor, B elephant, N horse, R chariot, C cannon, P soldier. */
(() => {
  'use strict';

  const START = [
    'rnbakabnr', '.........', '.c.....c.', 'p.p.p.p.p', '.........',
    '.........', 'P.P.P.P.P', '.C.....C.', '.........', 'RNBAKABNR',
  ];
  const NAMES = { K: '帥', A: '仕', B: '相', N: '傌', R: '俥', C: '炮', P: '兵',
                  k: '將', a: '士', b: '象', n: '馬', r: '車', c: '砲', p: '卒' };
  const VALUE = { k: 10000, r: 900, c: 450, n: 400, b: 200, a: 200, p: 100 };
  const MAX_PLIES = 300; // declare a draw after this many plies
  const DEPTH = { 1: 1, 2: 2, 3: 3 };

  const isRed = (p) => p !== '.' && p === p.toUpperCase();
  const side = (p) => (p === '.' ? null : isRed(p) ? 'r' : 'b');
  const inBoard = (r, c) => r >= 0 && r < 10 && c >= 0 && c < 9;
  const inPalace = (r, c, s) => c >= 3 && c <= 5 && (s === 'r' ? r >= 7 : r <= 2);

  // ---------- rules ----------
  function pseudoMoves(b, s) {
    const out = [];
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) {
      const p = b[r][c];
      if (side(p) !== s) continue;
      const add = (tr, tc) => {
        if (!inBoard(tr, tc)) return;
        const t = b[tr][tc];
        if (side(t) !== s) out.push([r, c, tr, tc]);
      };
      switch (p.toLowerCase()) {
        case 'k':
          for (const [dr, dc] of [[1,0],[-1,0],[0,1],[0,-1]])
            if (inPalace(r+dr, c+dc, s)) add(r+dr, c+dc);
          break;
        case 'a':
          for (const [dr, dc] of [[1,1],[1,-1],[-1,1],[-1,-1]])
            if (inPalace(r+dr, c+dc, s)) add(r+dr, c+dc);
          break;
        case 'b':
          for (const [dr, dc] of [[2,2],[2,-2],[-2,2],[-2,-2]]) {
            const tr = r+dr, tc = c+dc;
            if (!inBoard(tr, tc) || b[r+dr/2][c+dc/2] !== '.') continue;
            if (s === 'r' ? tr < 5 : tr > 4) continue; // cannot cross river
            add(tr, tc);
          }
          break;
        case 'n':
          for (const [dr, dc, lr, lc] of [[2,1,1,0],[2,-1,1,0],[-2,1,-1,0],[-2,-1,-1,0],
                                          [1,2,0,1],[-1,2,0,1],[1,-2,0,-1],[-1,-2,0,-1]])
            if (inBoard(r+lr, c+lc) && b[r+lr][c+lc] === '.') add(r+dr, c+dc);
          break;
        case 'r':
        case 'c': {
          const cannon = p.toLowerCase() === 'c';
          for (const [dr, dc] of [[1,0],[-1,0],[0,1],[0,-1]]) {
            let tr = r+dr, tc = c+dc, jumped = false;
            while (inBoard(tr, tc)) {
              const t = b[tr][tc];
              if (!jumped) {
                if (t === '.') out.push([r, c, tr, tc]);
                else if (cannon) jumped = true;
                else { if (side(t) !== s) out.push([r, c, tr, tc]); break; }
              } else if (t !== '.') {
                if (side(t) !== s) out.push([r, c, tr, tc]);
                break;
              }
              tr += dr; tc += dc;
            }
          }
          break;
        }
        case 'p': {
          const fwd = s === 'r' ? -1 : 1;
          add(r+fwd, c);
          const crossed = s === 'r' ? r <= 4 : r >= 5;
          if (crossed) { add(r, c-1); add(r, c+1); }
          break;
        }
      }
    }
    return out;
  }

  function findKing(b, s) {
    const k = s === 'r' ? 'K' : 'k';
    for (let r = 0; r < 10; r++) for (let c = 3; c <= 5; c++) if (b[r][c] === k) return [r, c];
    return null;
  }

  function inCheck(b, s) {
    const king = findKing(b, s);
    if (!king) return true;
    const other = s === 'r' ? 'b' : 'r';
    // flying general
    const ok = findKing(b, other);
    if (ok && ok[1] === king[1]) {
      let blocked = false;
      for (let r = Math.min(ok[0], king[0]) + 1; r < Math.max(ok[0], king[0]); r++)
        if (b[r][king[1]] !== '.') { blocked = true; break; }
      if (!blocked) return true;
    }
    return pseudoMoves(b, other).some(([, , tr, tc]) => tr === king[0] && tc === king[1]);
  }

  function makeMove(b, m) {
    const [fr, fc, tr, tc] = m;
    const captured = b[tr][tc];
    b[tr][tc] = b[fr][fc];
    b[fr][fc] = '.';
    return captured;
  }
  function unmakeMove(b, m, captured) {
    const [fr, fc, tr, tc] = m;
    b[fr][fc] = b[tr][tc];
    b[tr][tc] = captured;
  }

  function legalMoves(b, s) {
    return pseudoMoves(b, s).filter((m) => {
      const cap = makeMove(b, m);
      const ok = !inCheck(b, s);
      unmakeMove(b, m, cap);
      return ok;
    });
  }

  // ---------- AI ----------
  function evaluate(b) { // positive = good for black (the AI)
    let score = 0;
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) {
      const p = b[r][c];
      if (p === '.') continue;
      const lower = p.toLowerCase();
      let v = VALUE[lower];
      if (lower === 'p') { // soldiers gain value after crossing & advancing
        const adv = isRed(p) ? 9 - r : r;
        if (adv >= 5) v += 60 + (adv - 5) * 10 - Math.abs(4 - c) * 5;
      } else if (lower === 'n' || lower === 'c' || lower === 'r') {
        v += 8 - Math.abs(4 - c) * 2; // mild centralisation
      }
      score += isRed(p) ? -v : v;
    }
    return score;
  }

  function orderMoves(b, moves) {
    return moves.map((m) => {
      const t = b[m[2]][m[3]];
      return [t === '.' ? 0 : VALUE[t.toLowerCase()] * 10 - VALUE[b[m[0]][m[1]].toLowerCase()], m];
    }).sort((x, y) => y[0] - x[0]).map((x) => x[1]);
  }

  function search(b, depth, alpha, beta, s) {
    const moves = legalMoves(b, s);
    if (moves.length === 0) return s === 'b' ? -100000 - depth : 100000 + depth;
    if (depth === 0) return evaluate(b);
    const ordered = orderMoves(b, moves);
    if (s === 'b') {
      let best = -Infinity;
      for (const m of ordered) {
        const cap = makeMove(b, m);
        best = Math.max(best, search(b, depth - 1, alpha, beta, 'r'));
        unmakeMove(b, m, cap);
        alpha = Math.max(alpha, best);
        if (alpha >= beta) break;
      }
      return best;
    }
    let best = Infinity;
    for (const m of ordered) {
      const cap = makeMove(b, m);
      best = Math.min(best, search(b, depth - 1, alpha, beta, 'b'));
      unmakeMove(b, m, cap);
      beta = Math.min(beta, best);
      if (alpha >= beta) break;
    }
    return best;
  }

  function bestMove(b, level) {
    const moves = orderMoves(b, legalMoves(b, 'b'));
    const depth = DEPTH[level] || 2;
    let best = [], bestScore = -Infinity;
    for (const m of moves) {
      const cap = makeMove(b, m);
      let score = search(b, depth - 1, -Infinity, Infinity, 'r');
      unmakeMove(b, m, cap);
      if (level === 1) score += Math.random() * 120; // easy: be sloppy
      if (score > bestScore) { bestScore = score; best = [m]; }
      else if (score === bestScore) best.push(m);
    }
    return best[Math.floor(Math.random() * best.length)];
  }

  // ---------- UI ----------
  const canvas = document.getElementById('board');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const CELL = 60, PAD = 30, RADIUS = 26;
  const el = (id) => document.getElementById(id);

  let board, turn, selected, targets, history, lastMove, over, startedAt, thinking;

  function reset() {
    board = START.map((row) => row.split(''));
    turn = 'r'; selected = null; targets = []; history = []; lastMove = null;
    over = false; thinking = false; startedAt = Date.now();
    el('moveList').innerHTML = '';
    el('difficulty').disabled = false;
    updateStatus();
    draw();
  }

  const xy = (r, c) => [PAD + c * CELL, PAD + r * CELL];

  function draw() {
    ctx.fillStyle = '#e8c48c';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#5a3a1a'; ctx.lineWidth = 1.5;
    for (let r = 0; r < 10; r++) line(r, 0, r, 8);
    for (let c = 0; c < 9; c++) {
      if (c === 0 || c === 8) line(0, c, 9, c);
      else { line(0, c, 4, c); line(5, c, 9, c); }
    }
    line(0, 3, 2, 5); line(0, 5, 2, 3); line(7, 3, 9, 5); line(7, 5, 9, 3);
    ctx.fillStyle = '#5a3a1a'; ctx.font = '26px serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText('楚 河', PAD + 2 * CELL, PAD + 4.5 * CELL);
    ctx.fillText('漢 界', PAD + 6 * CELL, PAD + 4.5 * CELL);

    if (lastMove) for (const [r, c] of [[lastMove[0], lastMove[1]], [lastMove[2], lastMove[3]]]) {
      const [x, y] = xy(r, c);
      ctx.strokeStyle = '#2f7de1'; ctx.lineWidth = 2;
      ctx.strokeRect(x - RADIUS - 2, y - RADIUS - 2, (RADIUS + 2) * 2, (RADIUS + 2) * 2);
    }
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) if (board[r][c] !== '.') piece(r, c, board[r][c]);
    for (const [, , tr, tc] of targets) {
      const [x, y] = xy(tr, tc);
      ctx.beginPath(); ctx.fillStyle = 'rgba(30,140,60,.55)';
      ctx.arc(x, y, board[tr][tc] === '.' ? 8 : RADIUS + 3, 0, Math.PI * 2);
      board[tr][tc] === '.' ? ctx.fill() : (ctx.lineWidth = 4, ctx.strokeStyle = 'rgba(30,140,60,.8)', ctx.stroke());
    }
  }

  function line(r1, c1, r2, c2) {
    const [x1, y1] = xy(r1, c1), [x2, y2] = xy(r2, c2);
    ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
  }

  function piece(r, c, p) {
    const [x, y] = xy(r, c);
    const sel = selected && selected[0] === r && selected[1] === c;
    ctx.beginPath(); ctx.arc(x, y, RADIUS, 0, Math.PI * 2);
    ctx.fillStyle = sel ? '#fff3b0' : '#fdf3dc'; ctx.fill();
    ctx.lineWidth = 2; ctx.strokeStyle = isRed(p) ? '#b3261e' : '#222'; ctx.stroke();
    ctx.beginPath(); ctx.arc(x, y, RADIUS - 4, 0, Math.PI * 2); ctx.lineWidth = 1; ctx.stroke();
    ctx.fillStyle = isRed(p) ? '#b3261e' : '#222';
    ctx.font = 'bold 28px "Kaiti SC","STKaiti","KaiTi",serif';
    ctx.fillText(NAMES[p], x, y + 1);
  }

  function updateStatus(text) {
    el('status').textContent = text || (turn === 'r' ? 'Your move (Red)' : 'Computer is thinking…');
    el('moveCount').textContent = history.length;
  }

  function notation(m, p) {
    return `${NAMES[p]} ${String.fromCharCode(97 + m[1])}${9 - m[0]} → ${String.fromCharCode(97 + m[3])}${9 - m[2]}`;
  }

  function apply(m) {
    const p = board[m[0]][m[1]];
    const cap = makeMove(board, m);
    history.push({ m, cap });
    lastMove = m;
    const li = document.createElement('li');
    li.textContent = notation(m, p) + (cap !== '.' ? ' ×' + NAMES[cap] : '');
    el('moveList').appendChild(li);
    el('moveList').scrollTop = 1e9;
    turn = turn === 'r' ? 'b' : 'r';
    el('difficulty').disabled = true;
  }

  function checkEnd() {
    if (legalMoves(board, turn).length === 0) {
      finish(turn === 'r' ? 'loss' : 'win');
      return true;
    }
    if (history.length >= MAX_PLIES) { finish('draw'); return true; }
    if (inCheck(board, turn)) updateStatus(turn === 'r' ? 'Check! Your move' : 'Check! Computer thinking…');
    else updateStatus();
    return false;
  }

  function finish(result) {
    over = true;
    updateStatus({ win: 'You win! 🎉', loss: 'You lost.', draw: 'Draw.' }[result]);
    draw();
    saveResult(result);
  }

  async function saveResult(result) {
    const msg = el('scoreMsg');
    if (!window.APP || !APP.loggedIn) {
      msg.innerHTML = 'Game over. <a href="/register">Register</a> or <a href="/login">log in</a> to save your scores next time.';
      return;
    }
    const playerMoves = Math.ceil(history.length / 2);
    if (playerMoves < 1) return;
    try {
      const res = await fetch(APP.saveUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': APP.csrf },
        body: JSON.stringify({
          result,
          difficulty: Number(el('difficulty').value),
          moves: playerMoves,
          duration_seconds: Math.round((Date.now() - startedAt) / 1000),
        }),
      });
      if (!res.ok) throw new Error(res.status);
      const data = await res.json();
      msg.textContent = `Saved: +${data.points} points. Total: ${data.total}.`;
    } catch (e) {
      msg.textContent = 'Could not save the result (' + e.message + ').';
    }
  }

  function aiTurn() {
    thinking = true;
    updateStatus();
    setTimeout(() => {
      const m = bestMove(board, Number(el('difficulty').value));
      thinking = false;
      if (over) return;
      apply(m);
      draw();
      checkEnd();
    }, 50);
  }

  canvas.addEventListener('click', (e) => {
    if (over || turn !== 'r' || thinking) return;
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / rect.width, sy = canvas.height / rect.height;
    const c = Math.round(((e.clientX - rect.left) * sx - PAD) / CELL);
    const r = Math.round(((e.clientY - rect.top) * sy - PAD) / CELL);
    if (!inBoard(r, c)) return;

    const mv = targets.find((m) => m[2] === r && m[3] === c);
    if (mv) {
      selected = null; targets = [];
      apply(mv); draw();
      if (!checkEnd()) aiTurn();
      return;
    }
    if (side(board[r][c]) === 'r') {
      selected = [r, c];
      targets = legalMoves(board, 'r').filter((m) => m[0] === r && m[1] === c);
    } else { selected = null; targets = []; }
    draw();
  });

  el('newGame').addEventListener('click', () => {
    if (!over && history.length > 1 && !confirm('Abandon the current game?')) return;
    reset();
  });
  el('undo').addEventListener('click', () => {
    if (over || thinking || turn !== 'r' || history.length < 2) return;
    for (let i = 0; i < 2; i++) {
      const { m, cap } = history.pop();
      unmakeMove(board, m, cap);
      el('moveList').lastChild?.remove();
    }
    lastMove = history.length ? history[history.length - 1].m : null;
    selected = null; targets = [];
    updateStatus(); draw();
  });
  el('resign').addEventListener('click', () => {
    if (over || history.length === 0 || !confirm('Resign this game?')) return;
    finish('loss');
  });

  reset();
})();
