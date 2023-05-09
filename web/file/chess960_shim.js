var Chess960 = function (xfen) {
  this.core = new Chess();

  // Expose chess.js BITS so callers can test flags on this instance.
  this.BITS = this.core.BITS;

  // Starting references (used to decide "standard-representable" for KQkq)
  this.startKing  = { w: 'e1', b: 'e8' };

  // Castling rights as a set of rook files per color, e.g. { w:{a:1,h:1}, b:{h:1} }
  this.cr = { w: {}, b: {} };

  // Logical move history for undo()
  this._hist = [];

  if (xfen) this.load(xfen);
};

/* ----------------- tiny helpers ----------------- */
function emptyRights(){ return {}; }
function hasRight(R, f){ return !!R[f]; }
function addRight(R, f){ R[f] = 1; }
function delRight(R, f){ delete R[f]; }
function listRights(R){ return Object.keys(R).sort().reverse(); }
function square(file, rank){ return file + rank; }

/* ----------------- core API ----------------- */
Chess960.prototype.load = function (xfen) {
  const parts = String(xfen || '').trim().split(/\s+/);
  if (parts.length < 2) return false;

  const piece = parts[0], side = parts[1], xcr = parts[2] || '-', ep = parts[3] || '-', half = parts[4] || '0', full = parts[5] || '1';

  // parse X-FEN rights to internal sets
  const want = { w:{left:false,right:false, files:[]}, b:{left:false,right:false, files:[]} };
  this.cr.w = emptyRights(); this.cr.b = emptyRights();
  if (xcr && xcr !== '-') {
    for (var i=0;i<xcr.length;i++){
      const ch = xcr[i];
      if (ch === 'K') want.w.right = true;
      else if (ch === 'Q') want.w.left = true;
      else if (ch === 'k') want.b.right = true;
      else if (ch === 'q') want.b.left = true;
      else if (/[A-H]/.test(ch)) want.w.files.push(ch.toLowerCase());
      else if (/[a-h]/.test(ch)) want.b.files.push(ch);
    }
  }

  // Feed chess.js a FEN with "-" castling; we own castling logic here.
  const ok = this.core.load([piece, side, '-', ep, half, full].join(' '));
  if (!ok) return false;

  // Infer current king squares (guides X-FEN output & targets)
  for (const c of ['w','b']) {
    const ks = this._findKing(c);
    if (ks) this.startKing[c] = ks;
  }

  // Now resolve K/Q to actual rook files relative to each king, then add explicit files.

  this._resolveCastleRights('w', want.w);
  this._resolveCastleRights('b', want.b);
  // Clear history on load
  this._hist = [];
  return true;
};

Chess960.prototype.fen = function () {
  const parts = this.core.fen().split(' ');
  parts[2] = this._buildXCastlingField() || '-';
  return parts.join(' ');
};

Chess960.prototype.put = function (piece, square) { return this.core.put(piece, square); };
Chess960.prototype.remove = function (square) { return this.core.remove(square); };

Chess960.prototype.move = function (uci) {
  const side = this.core.turn();
  const from = uci.slice(0,2);
  const to   = uci.slice(2,4);
  const promo = uci[4];

  // snapshot rights BEFORE executing the move
  const prevCR = this._cloneCR();

  if (!this._isCastleUCI(side, from, to)) {
    const mv = this.core.move({ from, to, promotion: promo });
    if (!mv) return null;
    this._consumeRightsOnMove(side, mv.from, mv.to, mv.piece, mv.captured);
    this._hist.push({ type:'normal', prevCR: prevCR,
                      summary:{ san: mv.san, from: mv.from, to: mv.to, flags: mv.flags }});
    return { san: mv.san, from: mv.from, to: mv.to, flags: mv.flags };
  }

  // chess960 castle (king→rook or standard UCI to king target)
  const c = this._makeCastleMove(side, from, to);
  if (!c) return null;
  const piece_king = this.core.remove(c.kingFrom);
  const piece_rook = this.core.remove(c.rookFrom);
  this.core.put(piece_king, c.kingTo);
  this.core.put(piece_rook, c.rookTo);
  this.cr[side] = emptyRights();
  var san = (c.flags === 'k') ? 'O-O' : 'O-O-O';
  this.core.do_null_move();
  if (this.core.in_check()) {
    if (this.core.in_checkmate()) {
      san += '#';
    } else {
      san += '+';
    }
  }
  this._hist.push({ type:'castle', prevCR: prevCR,
                    summary:c });
  return { san: san, from: from, to: to, flags: c.flags };
};

Chess960.prototype.undo = function () {
  if (!this._hist.length) return null;
  const rec = this._hist.pop();
  if (rec.type === 'castle') {
    const piece_king = this.core.remove(rec.summary.kingTo);
    const piece_rook = this.core.remove(rec.summary.rookTo);
    this.core.put(piece_king, rec.summary.kingFrom);
    this.core.put(piece_rook, rec.summary.rookFrom);
    this.core.undo_null_move();
  } else {
    this.core.undo();                                    // revert core
  }
  this.cr = rec.prevCR;                                  // restore rights
  return rec.summary;                                    // {san,from,to,flags}
};

/* ----------------- validation ----------------- */
Chess960.prototype.validate_fen = function (xfen) {
  const parts = String(xfen || '').trim().split(/\s+/);
  if (parts.length < 2) return { valid:false, error_number:1, error:'FEN string must contain 2 or more space-delimited fields.' };
  if (parts.length > 2) {
    if (!/^(-|[KQkqA-Ha-h]{1,4})$/.test(parts[2])) return { valid:false, error_number:5, error:'3rd field (castling availability) is invalid.' };
    parts[2] = '-'; // strip X-FEN castling to make chess.js happy
  }
  return this.core.validate_fen(parts.join(' '));
};

/* ----------------- castling flags ----------------- */
// No-arg, returns { w:<mask>, b:<mask> } based on rook-rights relative to king.
Chess960.prototype.castling_flag = function () {
  return { w: this._castleMask('w'), b: this._castleMask('b') };
};

Chess960.prototype.turn = function () { return this.core.turn(); };
Chess960.prototype.in_threefold_repetition = function () { return this.core.in_threefold_repetition(); };
Chess960.prototype.insufficient_material = function () { return this.core.insufficient_material(); };
/* ----------------- internals ----------------- */
Chess960.prototype._cloneCR = function () {
  function cloneSide(s){ var o={}; for (var k in s) if (Object.prototype.hasOwnProperty.call(s,k)) o[k]=1; return o; }
  return { w: cloneSide(this.cr.w), b: cloneSide(this.cr.b) };
};

Chess960.prototype._board = function(){ return this.core.board(); };

Chess960.prototype._findKing = function (color) {
  const b = this._board();
  for (var r=0;r<8;r++) for (var f=0;f<8;f++){
    const p = b[r][f];
    if (p && p.type==='k' && p.color===color){
      const file = 'abcdefgh'[f], rank = 8 - r;
      return file + rank;
    }
  }
  return null;
};

Chess960.prototype._resolveCastleRights = function (color, want) {
  const rank = (color==='w') ? '1' : '8';
  const ks = this.startKing[color];
  if (!ks || ks[1] != rank) return;
  const kf = ks[0].charCodeAt(0);
  // collect all home-rank rook files present on board
  const homeRooks = [];
  for (const f of 'abcdefgh') {
    const sq = f + rank;
    const p = this.core.get(sq);
    if (p && p.type==='r' && p.color===color) homeRooks.push(f);
  }
  // left of king: max file < kf; right of king: min file > kf
  var left = null, right = null;
  for (const f of homeRooks) {
    const fc = f.charCodeAt(0);
    if (fc < kf) left  = (left && left.charCodeAt(0) > fc) ? left : f;
    if (fc > kf) right = (right && right.charCodeAt(0) < fc) ? right : f;
  }
  if (want.left  && left)  addRight(this.cr[color], left);
  if (want.right && right) addRight(this.cr[color], right);
  for (const f of want.files) addRight(this.cr[color], f);
};

Chess960.prototype._buildXCastlingField = function () {
  const toks = [];
  for (const c of ['w','b']){
    const rights = this.cr[c];
    const files = listRights(rights);
    if (!files.length) continue;
    const eSq = square('e', c==='w'?1:8);
    const std = (this.startKing[c] === eSq) && files.every(function(f){ return f==='a' || f==='h'; });
    if (std){
      if (hasRight(rights,'h')) toks.push(c==='w' ? 'K' : 'k');
      if (hasRight(rights,'a')) toks.push(c==='w' ? 'Q' : 'q');
    } else {
      for (const f of files) toks.push(c==='w' ? f.toUpperCase() : f);
    }
  }
  return toks.join('');
};

Chess960.prototype._isCastleUCI = function (side, from, to) {
  const kingSq = this._findKing(side);
  if (!kingSq || from !== kingSq) return false;

  // accept standard e1g1/e1c1 etc. if that matches computed targets
  if (kingSq === (side==='w'?'e1':'e8') && to === this._kingTarget(side,'K')) return true;
  if (kingSq === (side==='w'?'e1':'e8') && to === this._kingTarget(side,'Q')) return true;

  // else accept king→rook encoding (rook file on home rank that still has rights)
  const rf = to[0];
  const homeRankOk = to[1] === (side==='w' ? '1' : '8');
  return homeRankOk && hasRight(this.cr[side], rf);
};

Chess960.prototype._kingTarget = function (side, which) {
  const rank = (side === 'w') ? '1' : '8';
  const file = (which === 'K') ? 'g' : 'c';
  return file + rank;
};

Chess960.prototype._makeCastleMove = function (side, from, to) {
  const rank = (side==='w') ? '1' : '8';
  var rookFile = null, which = null;

  if (to === this._kingTarget(side,'K')) { which = 'k'; rookFile = this._rightRookFile(side); }
  else if (to === this._kingTarget(side,'Q')) { which = 'q'; rookFile = this._leftRookFile(side); }
  else { // king→rook encoding
    rookFile = to[0];
    which = (rookFile > from[0]) ? 'k' : 'q';
  }
  if (!rookFile) return null;

  const kingFrom = from;
  const rookFrom = rookFile + rank;
  // FIDE-960 finals are fixed by side of castling
  const kingTo = (which === 'k') ? ('g' + rank) : ('c' + rank);
  const rookTo = (which === 'k') ? ('f' + rank) : ('d' + rank);
  return { flags: which, kingFrom, kingTo, rookFrom, rookTo, rookFile };
};

Chess960.prototype._leftRookFile = function (side) {
  const files = listRights(this.cr[side]);
  return files.length ? files[files.length-1] : null;
};
Chess960.prototype._rightRookFile = function (side) {
  const files = listRights(this.cr[side]);
  return files.length ? files[0] : null;
};

Chess960.prototype._consumeRightsOnMove = function (side, from, to, piece, captured) {
  // king move → drop all rights
  if (piece === 'k') { this.cr[side] = emptyRights(); return; }
  // rook move off home rank → drop that file's right
  const homeRank = (side==='w') ? '1' : '8';
  if (piece === 'r' && from[1] === homeRank) delRight(this.cr[side], from[0]);
  // if a *home-rank* rook with rights gets captured, drop that right
  const opp = (side==='w') ? 'b' : 'w';
  const oppHome = (opp==='w') ? '1' : '8';
  if (to[1] === oppHome) delRight(this.cr[opp], to[0]);
};

Chess960.prototype._castleMask = function (color) {
  // KSIDE if any rook-right lies to the king's right; QSIDE if any to the left.
  var mask = 0;
  var bits = this.BITS;
  var kingSq = this._findKing(color);
  if (!kingSq) return 0;
  var kf = kingSq.charCodeAt(0);
  var files = listRights(this.cr[color]);
  for (var i=0;i<files.length;i++){
    var rf = files[i].charCodeAt(0);
    if (rf > kf) mask |= bits.KSIDE_CASTLE;
    else if (rf < kf) mask |= bits.QSIDE_CASTLE;
  }
  return mask;
};