/* Xiangqi rules + AI. Loaded two ways:
 *   - as a classic <script> on the page: exposes window.Xiangqi (rules, used by the UI)
 *   - as a Web Worker: answers {board, side, level} with {move}, so the page never
 *     freezes while the computer thinks.
 * Board: 10 rows x 9 cols, row 0 = top (Black), row 9 = bottom (Red).
 * Uppercase = Red, lowercase = Black. K general, A advisor, B elephant,
 * N horse, R chariot, C cannon, P soldier. */
(function (root) {
  'use strict';

  const START = [
    'rnbakabnr', '.........', '.c.....c.', 'p.p.p.p.p', '.........',
    '.........', 'P.P.P.P.P', '.C.....C.', '.........', 'RNBAKABNR',
  ];
  const VALUE = { k: 6000, r: 600, c: 285, n: 270, b: 120, a: 120, p: 30 };

  // Piece-square bonuses from Red's point of view (row 0 = far side). Black reads them mirrored.
  const PST = {
    p: [
      [0, 3, 6, 9, 12, 9, 6, 3, 0], [18, 36, 56, 80, 120, 80, 56, 36, 18], [14, 26, 42, 60, 80, 60, 42, 26, 14],
      [10, 20, 30, 34, 40, 34, 30, 20, 10], [6, 12, 18, 18, 20, 18, 18, 12, 6], [2, 0, 8, 0, 8, 0, 8, 0, 2],
      [0, 0, -2, 0, 4, 0, -2, 0, 0], [0, 0, 0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0, 0, 0], [0, 0, 0, 0, 0, 0, 0, 0, 0],
    ],
    n: [
      [4, 8, 16, 12, 4, 12, 16, 8, 4], [4, 10, 28, 16, 8, 16, 28, 10, 4], [12, 14, 16, 20, 18, 20, 16, 14, 12],
      [8, 24, 18, 24, 20, 24, 18, 24, 8], [6, 16, 14, 18, 16, 18, 14, 16, 6], [4, 12, 16, 14, 12, 14, 16, 12, 4],
      [2, 6, 8, 6, 10, 6, 8, 6, 2], [4, 2, 8, 8, 4, 8, 8, 2, 4], [0, 2, 4, 4, -2, 4, 4, 2, 0], [0, -4, 0, 0, 0, 0, 0, -4, 0],
    ],
    r: [
      [14, 14, 12, 18, 16, 18, 12, 14, 14], [16, 20, 18, 24, 26, 24, 18, 20, 16], [12, 12, 12, 18, 18, 18, 12, 12, 12],
      [12, 18, 16, 22, 22, 22, 16, 18, 12], [12, 14, 12, 18, 18, 18, 12, 14, 12], [12, 16, 14, 20, 20, 20, 14, 16, 12],
      [6, 10, 8, 14, 14, 14, 8, 10, 6], [4, 8, 6, 14, 12, 14, 6, 8, 4], [8, 4, 8, 16, 8, 16, 8, 4, 8], [-2, 10, 6, 14, 12, 14, 6, 10, -2],
    ],
    c: [
      [6, 4, 0, -10, -12, -10, 0, 4, 6], [2, 2, 0, -4, -14, -4, 0, 2, 2], [2, 2, 0, -10, -8, -10, 0, 2, 2],
      [0, 0, -2, 4, 10, 4, -2, 0, 0], [0, 0, 0, 2, 8, 2, 0, 0, 0], [-2, 0, 4, 2, 6, 2, 4, 0, -2],
      [0, 0, 0, 2, 4, 2, 0, 0, 0], [4, 0, 8, 6, 10, 6, 8, 0, 4], [0, 2, 4, 6, 6, 6, 4, 2, 0], [0, 0, 2, 6, 6, 6, 2, 0, 0],
    ],
  };

  const isRed = (p) => p !== '.' && p === p.toUpperCase();
  const side = (p) => (p === '.' ? null : isRed(p) ? 'r' : 'b');
  const other = (s) => (s === 'r' ? 'b' : 'r');
  const inBoard = (r, c) => r >= 0 && r < 10 && c >= 0 && c < 9;
  const inPalace = (r, c, s) => c >= 3 && c <= 5 && (s === 'r' ? r >= 7 : r <= 2);

  function pseudoMoves(b, s, capturesOnly) {
    const out = [];
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) {
      const p = b[r][c];
      if (side(p) !== s) continue;
      const add = (tr, tc) => {
        if (!inBoard(tr, tc)) return;
        const t = b[tr][tc];
        if (side(t) === s) return;
        if (capturesOnly && t === '.') return;
        out.push([r, c, tr, tc]);
      };
      switch (p.toLowerCase()) {
        case 'k':
          for (const [dr, dc] of [[1, 0], [-1, 0], [0, 1], [0, -1]])
            if (inPalace(r + dr, c + dc, s)) add(r + dr, c + dc);
          break;
        case 'a':
          for (const [dr, dc] of [[1, 1], [1, -1], [-1, 1], [-1, -1]])
            if (inPalace(r + dr, c + dc, s)) add(r + dr, c + dc);
          break;
        case 'b':
          for (const [dr, dc] of [[2, 2], [2, -2], [-2, 2], [-2, -2]]) {
            const tr = r + dr, tc = c + dc;
            if (!inBoard(tr, tc) || b[r + dr / 2][c + dc / 2] !== '.') continue;
            if (s === 'r' ? tr < 5 : tr > 4) continue;
            add(tr, tc);
          }
          break;
        case 'n':
          for (const [dr, dc, lr, lc] of [[2, 1, 1, 0], [2, -1, 1, 0], [-2, 1, -1, 0], [-2, -1, -1, 0],
            [1, 2, 0, 1], [-1, 2, 0, 1], [1, -2, 0, -1], [-1, -2, 0, -1]])
            if (inBoard(r + lr, c + lc) && b[r + lr][c + lc] === '.') add(r + dr, c + dc);
          break;
        case 'r':
        case 'c': {
          const cannon = p.toLowerCase() === 'c';
          for (const [dr, dc] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
            let tr = r + dr, tc = c + dc, jumped = false;
            while (inBoard(tr, tc)) {
              const t = b[tr][tc];
              if (!jumped) {
                if (t === '.') { if (!capturesOnly) out.push([r, c, tr, tc]); }
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
          add(r + (s === 'r' ? -1 : 1), c);
          if (s === 'r' ? r <= 4 : r >= 5) { add(r, c - 1); add(r, c + 1); }
          break;
        }
      }
    }
    return out;
  }

  function findKing(b, s) {
    const k = s === 'r' ? 'K' : 'k';
    const rows = s === 'r' ? [7, 8, 9] : [0, 1, 2];
    for (const r of rows) for (let c = 3; c <= 5; c++) if (b[r][c] === k) return [r, c];
    return null;
  }

  function inCheck(b, s) {
    const king = findKing(b, s);
    if (!king) return true;
    const ok = findKing(b, other(s));
    if (ok && ok[1] === king[1]) { // flying general
      let blocked = false;
      for (let r = Math.min(ok[0], king[0]) + 1; r < Math.max(ok[0], king[0]); r++)
        if (b[r][king[1]] !== '.') { blocked = true; break; }
      if (!blocked) return true;
    }
    return pseudoMoves(b, other(s), true).some(([, , tr, tc]) => tr === king[0] && tc === king[1]);
  }

  function makeMove(b, m) { const cap = b[m[2]][m[3]]; b[m[2]][m[3]] = b[m[0]][m[1]]; b[m[0]][m[1]] = '.'; return cap; }
  function unmakeMove(b, m, cap) { b[m[0]][m[1]] = b[m[2]][m[3]]; b[m[2]][m[3]] = cap; }

  function legalMoves(b, s, capturesOnly) {
    return pseudoMoves(b, s, capturesOnly).filter((m) => {
      const cap = makeMove(b, m);
      const ok = !inCheck(b, s);
      unmakeMove(b, m, cap);
      return ok;
    });
  }

  //---------------------------------------------------------------- AI
  // Score from the point of view of side `s` (negamax).
  function evaluate(b, s) {
    let score = 0;
    for (let r = 0; r < 10; r++) for (let c = 0; c < 9; c++) {
      const p = b[r][c];
      if (p === '.') continue;
      const l = p.toLowerCase();
      const red = isRed(p);
      const t = PST[l];
      const v = VALUE[l] + (t ? (red ? t[r][c] : t[9 - r][8 - c]) : 0);
      score += red ? v : -v;
    }
    return s === 'r' ? score : -score;
  }

  const MATE = 100000;

  function order(b, moves, best) {
    return moves.map((m) => {
      const t = b[m[2]][m[3]];
      let k = t === '.' ? 0 : VALUE[t.toLowerCase()] * 10 - VALUE[b[m[0]][m[1]].toLowerCase()];
      if (best && m[0] === best[0] && m[1] === best[1] && m[2] === best[2] && m[3] === best[3]) k = 1e9;
      return [k, m];
    }).sort((x, y) => y[0] - x[0]).map((x) => x[1]);
  }

  function makeSearch(deadline) {
    let nodes = 0;
    const timeUp = () => (++nodes & 1023) === 0 && Date.now() > deadline;
    let aborted = false;

    // Quiescence: keep resolving captures so a hanging piece at the horizon isn't missed.
    function quiesce(b, s, alpha, beta, qd) {
      const stand = evaluate(b, s);
      if (stand >= beta) return stand;
      if (stand > alpha) alpha = stand;
      if (qd <= 0) return stand;
      for (const m of order(b, legalMoves(b, s, true))) {
        const cap = makeMove(b, m);
        const v = -quiesce(b, other(s), -beta, -alpha, qd - 1);
        unmakeMove(b, m, cap);
        if (v >= beta) return v;
        if (v > alpha) alpha = v;
      }
      return alpha;
    }

    function negamax(b, s, depth, alpha, beta, ply) {
      if (aborted || timeUp()) { aborted = true; return 0; }
      const moves = legalMoves(b, s);
      if (moves.length === 0) return -MATE + ply; // no moves = loss in xiangqi
      if (depth === 0) return quiesce(b, s, alpha, beta, 4);
      let best = -Infinity;
      for (const m of order(b, moves)) {
        const cap = makeMove(b, m);
        const v = -negamax(b, other(s), depth - 1, -beta, -alpha, ply + 1);
        unmakeMove(b, m, cap);
        if (v > best) best = v;
        if (v > alpha) alpha = v;
        if (alpha >= beta) break;
      }
      return best;
    }

    return { negamax, isAborted: () => aborted };
  }

  // level 1 = Easy, 2 = Medium, 3 = Hard. Iterative deepening within a time budget.
  const LEVELS = {
    1: { maxDepth: 1, ms: 300, noise: 90 },
    2: { maxDepth: 3, ms: 900, noise: 12 },
    3: { maxDepth: 6, ms: 2200, noise: 0 },
  };

  function bestMove(board, s, level) {
    const b = board.map((row) => row.slice());
    const cfg = LEVELS[level] || LEVELS[2];
    const root = legalMoves(b, s);
    if (root.length === 0) return null;
    if (root.length === 1) return root[0];
    const deadline = Date.now() + cfg.ms;
    let best = root[0], prevBest = null;

    for (let depth = 1; depth <= cfg.maxDepth; depth++) {
      const search = makeSearch(deadline);
      let iterBest = null, iterScore = -Infinity, alpha = -Infinity;
      for (const m of order(b, root, prevBest)) {
        const cap = makeMove(b, m);
        let v = -search.negamax(b, other(s), depth - 1, -Infinity, -alpha, 1);
        unmakeMove(b, m, cap);
        if (search.isAborted()) break;
        if (cfg.noise) v += Math.random() * cfg.noise;
        if (v > iterScore) { iterScore = v; iterBest = m; }
        if (v > alpha) alpha = v;
      }
      if (search.isAborted()) break; // keep the last fully searched answer
      best = prevBest = iterBest;
      if (iterScore > MATE - 100) break; // found a forced win
    }
    return best;
  }

  const api = { START, isRed, side, other, inBoard, legalMoves, inCheck, makeMove, unmakeMove, findKing, bestMove };

  if (typeof importScripts === 'function') {
    // Worker
    self.onmessage = (e) => {
      const { id, board, side: s, level } = e.data;
      self.postMessage({ id, move: bestMove(board, s, level) });
    };
  } else {
    root.Xiangqi = api;
  }
  if (typeof module !== 'undefined') module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
