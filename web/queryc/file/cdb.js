var PREFIX = '/';
var apiurl = new String(PREFIX + 'cdb.php');
var statsurl = new String(PREFIX + 'statsc.php?lang=0');
var f = 0,
	iif = 0,
	kk = 0,
	z = 0,
	wb = 0,
	flipmode = 0,
	busy = 0,
	automove = 0,
	autotimer = 0,
	curstep = 0;
var fens = new String();
var desk = new Array(8);
var movtable = new Array();
var Vselect, Vbmm, Vwmm, Vout, Vout2, Vstats, Vdesk, Vsecsel, Vfirsel, Vrulecheck, Vnumbar1, Vnumbar2, Vrankbar, Vhidescore, Vbauto, Vwauto, Vautopolicy, Vlocalengine, Vbks, Vbqs, Vwks, Vwqs;
var chess = new Chess();

var prevmove = new Array();
desk[0] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[1] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[2] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[3] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[4] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[5] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[6] = new Array(0, 0, 0, 0, 0, 0, 0, 0);
desk[7] = new Array(0, 0, 0, 0, 0, 0, 0, 0);

var FigureFiles = new Array();
FigureFiles[0] = 'a';
FigureFiles[1] = 'b';
FigureFiles[2] = 'c';
FigureFiles[3] = 'd';
FigureFiles[4] = 'e';
FigureFiles[5] = 'f';
FigureFiles[6] = 'g';
FigureFiles[7] = 'h';

var FigureIcons = new Array();
FigureIcons['w'] = new Array();
FigureIcons['b'] = new Array();
FigureIcons['w']['P'] = '♙';
FigureIcons['w']['Q'] = '♕';
FigureIcons['w']['R'] = '♖';
FigureIcons['w']['B'] = '♗';
FigureIcons['w']['N'] = '♘';
FigureIcons['w']['K'] = '♔';
FigureIcons['b']['p'] = '♟';
FigureIcons['b']['q'] = '♛';
FigureIcons['b']['r'] = '♜';
FigureIcons['b']['b'] = '♝';
FigureIcons['b']['n'] = '♞';
FigureIcons['b']['k'] = '♚';

if (window.addEventListener)
	window.addEventListener('load', Start, false);
else if (window.attachEvent)
	window.attachEvent('onload', Start);
else
	Start();

function trimNull(a) {
	var c = a.indexOf('\0');
	if (c>-1) {
		return a.substr(0, c);
	}
	return a;
}

function SetAddr() {
	if (fens.length) {
		var ss = fens.replace(/ /g, '_');
		window.location.replace(window.location.pathname + "?" + ss);
	}
}

function CopyFen() {
	if (fens.length) {
		var ss = new String();
		if (prevmove.length) {
			ss = prevmove[0][0] + ' moves';
			for (var i = 0; i < prevmove.length; i++) {
				ss = ss + ' ' + prevmove[i][2];
			}
		} else {
			ss = fens;
		}
		if (window.clipboardData && window.clipboardData.setData('Text', ss)) {
			alert("当前局面FEN已复制！");
		} else {
			prompt("请按CTRL+C复制：", ss);
		}
	}
}
function DrawGridNum() {
	var tmpStr = new String();
	Vnumbar1 = document.getElementById("numbar1");
	Vnumbar2 = document.getElementById("numbar2");
	Vrankbar = document.getElementById("rankbar");
	if (flipmode == 1) {
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 45 + "px; top: 0px; width: 45px; height: 15px; position: absolute;\">" + FigureFiles[7 - x] + "</span>";
		}
		Vnumbar1.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 45 + "px; top: 0px; width: 45px; height: 15px; position: absolute;\">" + FigureFiles[7 - x] + "</span>";
		}
		Vnumbar2.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: 0px; top: " + x * 45 + "px; height: 45px; position: absolute;\">" + (x + 1) + "</span>";
		}
		Vrankbar.innerHTML = tmpStr;
	}
	else {
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 45 + "px; top: 0px; width: 45px; height: 15px; position: absolute;\">" + FigureFiles[x] + "</span>";
		}
		Vnumbar1.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 45 + "px; top: 0px; width: 45px; height: 15px; position: absolute;\">" + FigureFiles[x] + "</span>";
		}
		Vnumbar2.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 8; x++) {
			tmpStr = tmpStr + "<span style=\"left: 0px; top: " + x * 45 + "px; height: 45px; position: absolute;\">" + (8 - x) + "</span>";
		}
		Vrankbar.innerHTML = tmpStr;
	}
}
function Start() {
	var plid = new String(),
		inS = new String();

	DrawGridNum();
	Vdesk = document.getElementById("desk");
	Vdesk.innerHTML = "<span>请稍候...<\/span>";
	for (var y = 0; y < 8; y++) {
		for (var x = 0; x < 8; x++) {
			plid = x + ',' + y;
			inS = inS + "<img onmousedown='onmdown(\"" + plid + "\")' style='position:absolute;top:" + y * 45 + "px;left:" + x * 45 + "px;z-index:5;width:45px;height:45px' id='" + plid + "' src='/file/oo.gif'><\/img>";

		}
	}
	inS = inS + '<img alt="" style="z-index:3;margin:0px" src="/file/chess/chess.svg"><img alt="" id="select" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif"><img alt="" id="secselect" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif"><img alt="" id="firselect" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif">';
	Vdesk.innerHTML = inS;

	Vselect = document.getElementById("select");
	Vbmm = document.getElementById("bmm");
	Vwmm = document.getElementById("wmm");
	Vout = document.getElementById("out");
	Vout2 = document.getElementById("out2");
	Vstats = document.getElementById("stats");
	Vsecsel = document.getElementById("secselect");
	Vfirsel = document.getElementById("firselect");
	Vrulecheck = document.getElementById("rulecheck");
	Vhidescore = document.getElementById("hidescore");
	Vbauto = document.getElementById("bauto");
	Vwauto = document.getElementById("wauto");
	Vautopolicy = document.getElementById("prandom");
	Vlocalengine = document.getElementById("localengine");
	Vbks = document.getElementById("bks");
	Vbqs = document.getElementById("bqs");
	Vwks = document.getElementById("wks");
	Vwqs = document.getElementById("wqs");
	Vtheme = document.getElementById("theme");

	var month = new Date().getMonth();
	if (month >= 11 || month <= 1) {
		Vtheme.href = "/file/style_candy.css";
	} else if (month >= 5 && month <= 7) {
		Vtheme.href = "/file/style_mint.css";
	}

	Vrulecheck.checked = Vrulecheck.defaultChecked;
	Vhidescore.checked = Vhidescore.defaultChecked;
	Vbauto.checked = Vbauto.defaultChecked;
	Vwauto.checked = Vwauto.defaultChecked;
	Vlocalengine.checked = Vlocalengine.defaultChecked;

	document.body.addEventListener('keydown', OnKeyEvent);

	inS = String(window.location.search);
	if (inS.length > 0) {
		inS = inS.substr(1);
		if (VerifyFEN(inS)) {
			SetFen(inS);
			return;
		}
	}
	ResetFen("rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1");
	return;
}

function FlipDesk() {
	var f;
	for (var y = 0; y < 4; y++) {
		for (var x = 0; x < 8; x++) {
			f = desk[x][y];
			desk[x][y] = desk[7 - x][7 - y];
			desk[7 - x][7 - y] = f;
		}
	}
	return;
}

function Flip(l) {
	ClearDot();
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	iif = 0;
	f = 0;
	unselectpiece();

	var piece, to;
	for (var y = 0; y < 8; y++) {
		for (var x = 0; x < 8; x++) {
			if (desk[x][y] != 0) {
				piece = document.getElementById(desk[x][y] + 'd');
				to = document.getElementById((7 - x) + ',' + (7 - y));
				piece.style.left = to.style.left;
				piece.style.top = to.style.top;
			}
		}
	}
	FlipDesk();
	if (l) {
		Vfirsel.style.left = (315 - parseInt(Vfirsel.style.left)) + 'px';
		Vfirsel.style.top = (315 - parseInt(Vfirsel.style.top)) + 'px';
		Vsecsel.style.left = (315 - parseInt(Vsecsel.style.left)) + 'px';
		Vsecsel.style.top = (315 - parseInt(Vsecsel.style.top)) + 'px';

		flipmode = 1 - flipmode;
		DrawGridNum();


		var io = document.getElementById("dotframe1").innerHTML;
		document.getElementById("dotframe1").innerHTML = document.getElementById("dotframe2").innerHTML;
		document.getElementById("dotframe2").innerHTML = io;

	}
	return;
}

function ChangeMoveOrder(o) {
	Vfirsel.src = '/file/oo.gif';
	Vsecsel.src = '/file/oo.gif';
	wb = o;

	var tokens = chess.fen().split(' ');
	if (o == 1) {
		tokens[1] = 'b';
		document.getElementById('bturn').src = '/file/chess/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		tokens[1] = 'w';
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/chess/wturn.png';
	}
	tokens[3] = '-';
	chess.load(tokens.join(' '));
	fens = chess.fen();
	while (prevmove.length)
		prevmove.pop();
	curstep = 0;
	SyncDesk();
	return;
}

function ChangeCastling() {
	var tokens = chess.fen().split(' ');
	var flag = '';
	if (Vwks.checked) {
		flag += 'K';
	}
	if (Vwqs.checked) {
		flag += 'Q';
	}
	if (Vbks.checked) {
		flag += 'k';
	}
	if (Vbqs.checked) {
		flag += 'q';
	}
	if (flag == '') {
		flag = '-';
	}
	tokens[2] = flag;
	var newfen = tokens.join(' ');
	var result = chess.validate_fen(newfen);
	if (!result['valid'])
		return false;
	chess.load(newfen);
	fens = newfen;
	while (prevmove.length)
		prevmove.pop();
	curstep = 0;
	SyncDesk();
	return true;
}

function RefreshAll() {
	ClearDot();
	while (movtable.length) {
		movtable.pop();
	}
	f = 0;
	iif = 0;
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	unselectpiece();
	GetPage(fens);
	return;
}
function RefreshInner() {
	if (busy)
		return;
	if(autotimer) {
		clearTimeout(autotimer);
		autotimer = 0;
	}
	ClearDot();
	while (movtable.length) {
		movtable.pop();
	}
	f = 0;
	iif = 0;
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	unselectpiece();
	ClearInner();
	Vout.innerHTML += '<span style="text-align:center; display:block;">请稍候...<\/span>';
	GetInnerPage(fens);
	return;
}

function mdown(cid) {
	wb = 1 - wb;
	if (wb == 1) {
		Vbmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/chess/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		Vwmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/chess/wturn.png';
	}
	var a = new Array();
	var b = new Array();
	var s = new String();
	a = cid.split(/,/);
	if (desk[a[0]][a[1]] != 0) {
		DeleteFigure(cid);
	}
	s = place(cid);
	f = 0;
	iif = 0;
	Vfirsel.style.left = Vselect.style.left;
	Vfirsel.style.top = Vselect.style.top;
	Vfirsel.src = '/file/chess/point.gif';
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	var rps = new RegExp(s);
	for (var x = 0; x < movtable.length; x++) {
		if (movtable[x].search(rps) != -1) {
			s = movtable[x];
			x = movtable.length;
		}
	}
	b = s.split(/\./);
	while(prevmove.length > curstep)
		prevmove.pop();
	prevmove.push(new Array(fens, b[5], b[4], b[0], b[1]));
	curstep++;
	ClearDesk();
	fens = b[2];
	Initialize();
	var cido = document.getElementById(cid);
	Vsecsel.style.left = cido.style.left;
	Vsecsel.style.top = cido.style.top;
	Vsecsel.src = "/file/chess/point.gif";

	RefreshAll();
	return;
}

function ClearDot() {
	var plaza = Vdesk;
	while (String(plaza.lastChild.id).search(/waypoint/) != -1) {
		plaza.removeChild(plaza.lastChild);
	}
	return;
}

function Placesecsel(cid, p) {
	if (flipmode == 1) {
		var k = new Array();
		k = cid.split(/,/);
		cid = (7 - k[0]) + ',' + (7 - k[1]);
	}
	var cido = document.getElementById(cid);
	if (p) {
		Vsecsel.style.left = cido.style.left;
		Vsecsel.style.top = cido.style.top;
		Vsecsel.src = "/file/chess/point.gif";
	} else {
		Vfirsel.style.left = cido.style.left;
		Vfirsel.style.top = cido.style.top;
		Vfirsel.src = "/file/chess/point.gif";
	}
	return;
}

function PlaceDot(cid) {
	var k = new Array();
	k = cid.split(/,/);
	if (flipmode == 1) {
		cid = (7 - k[0]) + ',' + (7 - k[1]);
	}
	k = cid.split(/,/);
	var plaza = Vdesk;
	var cido = document.getElementById(cid);
	var a = cido.style.left;
	var b = cido.style.top;
	var elem = document.createElement("div");
	elem.style.left = a;
	elem.style.top = b;
	elem.style.zIndex = 7;
	elem.id = 'waypoint' + cid;
	elem.style.position = 'absolute';
	if (desk[k[0]][k[1]] != 0) {
		elem.innerHTML = "<img alt='' onmousedown='mdown(\"" + cid + "\")' ondragover='ondover(event)' ondrop='ond(event, \"" + cid + "\")' onstyle='position:absolute;z-index:7' src='/file/chess/cap.gif'>";
	} else {
		elem.innerHTML = "<img alt='' onmousedown='mdown(\"" + cid + "\")' ondragover='ondover(event)' ondrop='ond(event, \"" + cid + "\")' onstyle='position:absolute;z-index:7' src='/file/chess/waypoint.gif'>";
	}
	plaza.appendChild(elem);
	plaza.lastChild.style.left = a;
	plaza.lastChild.style.top = b;
	plaza.lastChild.style.width = 45 + 'px';
	plaza.lastChild.style.height = 45 + 'px';
	return;
}

function FillDot(cid) {
	var b = new Array();
	if (flipmode == 1) {
		var a = new Array();
		a = cid.split(/,/);
		cid = (7 - a[0]) + ',' + (7 - a[1]);
	}
	for (var x = 0; x < movtable.length; x++) {
		b = movtable[x].split(/\./);
		if (cid == b[0]) {
			PlaceDot(b[1]);
		}
	}
	return;
}

function GetFigureMove(s) {
	var ef = new String();
	var fromx = s.charCodeAt(0) - 97;
	var fromy = 8 - (s.charCodeAt(1) - 48);
	var tox = s.charCodeAt(2) - 97;
	var toy = 8 - (s.charCodeAt(3) - 48);

	if (flipmode == 1) {
		FlipDesk();
	}
	var mov = chess.move(s, {sloppy: true});
	var vf = chess.fen();
	chess.undo();
	var fn = FigureIcons[desk[fromx][fromy].charAt(0)][desk[fromx][fromy].charAt(1)] + mov.san;
	if (flipmode == 1) {
		FlipDesk();
	}
	ef = fromx + ',' + fromy + '.' + tox + ',' + toy + '.' + vf + '.' + fn + '.' + s + '.' + mov.san;
	movtable.push(ef);
	return new Array(vf, fn, fromx + ',' + fromy, tox + ',' + toy, mov.san);
}

function getXmlHttp() {
	var xmlhttp;
	if (typeof XMLHttpRequest != 'undefined') {
		xmlhttp = new XMLHttpRequest();
	} else {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch(e) {
			xmlhttp = false;
		}
	}
	return xmlhttp;
}
function ClearInner() {
	Vout.innerHTML = '<table cellspacing="0" style="text-align:center;" class="movelist"><thead><tr style="height:20px;"><td><b>着法<\/b><\/td><td><b>排序<\/b><\/td><td><b>分数<\/b><\/td><td style="min-width:100px;padding-right:20px;"><b>备注<\/b><\/td><\/tr><\/thead><\/table>';
}

function RequestQueue() {
	ClearInner();
	Vout.innerHTML += '<span style="text-align:center; display:block;">请稍候...<\/span>';

	var xmlhttpQueue = getXmlHttp();

	xmlhttpQueue.open('GET', apiurl + '?action=queue&board=' + fens, true);
	xmlhttpQueue.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpQueue.onreadystatechange = function() {
		if (xmlhttpQueue.readyState == 4) {
			if (xmlhttpQueue.status == 200) {
				if(xmlhttpQueue.responseText.search(/ok/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">已提交后台计算，5秒后自动刷新...<\/span>';
					if(autotimer == 0) {
						autotimer = setTimeout("RefreshInner()", 5000);
					}
				}
				else if(xmlhttpQueue.responseText.search(/invalid/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">当前局面无效！<\/span>';
				}
				else if(xmlhttpQueue.responseText.search(/exceeded/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">查询过于频繁！<\/span>';
				}
				else {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">当前局面棋子过少，您可以：<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="AutoMove()" class="button">&nbsp;自动走棋&nbsp;<\/td><\/tr><\/table><\/span>';
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">网络错误！<\/span>';
			}
		}
	};
	xmlhttpQueue.send(null);
}
function AsyncUpdateMoves(e) {
	var xmlhttp = getXmlHttp();
	xmlhttp.open('GET', apiurl + '?action=queryall&learn=1&showall=1&board=' + e, true);
	xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttp.onreadystatechange = function() {
		if (xmlhttp.readyState == 4) {
			if (xmlhttp.status == 200) {
				var s = xmlhttp.responseText.replace(/[\r\n]/, '');
				GetMoveList(s);
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">网络错误！<\/span>';
				SyncHistory();
			}
		}
	};
	xmlhttp.send(null);
}

function GetInnerPage(e) {
	if(Vrulecheck.checked)
	{
		if(chess.in_threefold_repetition())
		{
			ClearInner();
			Vout.innerHTML += '<span style="text-align:center; display:block;">根据三次重复规则判为和局！<\/span>';
			SyncHistory();
		}
		else if(chess.insufficient_material())
		{
			ClearInner();
			Vout.innerHTML += '<span style="text-align:center; display:block;">双方子力不足，判为和局！<\/span>';
			SyncHistory();
		}
		else
		{
			AsyncUpdateMoves(e);
		}
	}
	else
	{
		AsyncUpdateMoves(e);
	}
}

function GetPage(e) {
	ClearInner();
	Vout.innerHTML += '<span style="text-align:center; display:block;">请稍候...<\/span>';

	var xmlhttpStats = getXmlHttp();
	xmlhttpStats.open('GET', statsurl, true);
	xmlhttpStats.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpStats.onreadystatechange = function() {
		if (xmlhttpStats.readyState == 4) {
			if (xmlhttpStats.status == 200) {
				Vstats.innerHTML = xmlhttpStats.responseText;
			}
		}
	};
	xmlhttpStats.send(null);
	GetInnerPage(e);
}

function OnHover(id) {
	var b = new Array();
	b = String(movtable[Number(id.replace(/ft/, ''))]).split(/\./);
	document.getElementById(id).className = 'hovon';
	ClearDot();
	f = 0;
	iif = 0;
	unselectpiece();
	PlaceDot(b[1]);
	if (flipmode == 1) {
		var k = new Array();
		k = b[0].split(/,/);
		b[0] = (7 - k[0]) + ',' + (7 - k[1]);
	}
	Vselect.style.left = document.getElementById(b[0]).style.left;
	Vselect.style.top = document.getElementById(b[0]).style.top;
	Vselect.src = '/file/chess/select.gif';
	return;
}

function OffHover(id) {
	document.getElementById(id).className = 'hovoff';
	ClearDot();
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	return;
}

function PreviousStep() {
	if (busy)
		return;
	busy = 1;
	while(prevmove.length > curstep)
		prevmove.pop();
	Vfirsel.src = Vsecsel.src = '/file/oo.gif';
	if(autotimer) {
		clearTimeout(autotimer);
		autotimer = 0;
	}
	Vwauto.checked = false;
	Vbauto.checked = false;
	
	if(curstep > 0) {
		var mv = prevmove.pop();
		curstep--;
		SetFen(mv[0]);
		if(prevmove.length) {
			Placesecsel(prevmove[prevmove.length-1][3], 0);
			Placesecsel(prevmove[prevmove.length-1][4], 1);
		}
	} else {
		SyncHistory();
	}
}
function OnKeyEvent(e) {
	switch(e.keyCode) {
		case 37:
		case 38:
			e.preventDefault();
			NavStep('-');
			break;
		case 39:
		case 40:
			e.preventDefault();
			NavStep('+');
			break;
		case 13:
			e.preventDefault();
			AutoMove();
			break;
		case 8:
		case 46:
			e.preventDefault();
			PreviousStep();
			break;
		case 81:
			e.preventDefault();
			RequestQueue();
			break;
		case 82:
			e.preventDefault();
			RefreshInner();
			break;
	}
}
function NavStep(pos) {
	if(pos == '-')
		pos = curstep - 1;
	else if(pos == '+')
		pos = curstep + 1;
	if(pos < 0)
		return;
	if(pos == curstep)
		return;
	if (busy)
		return;
	busy = 1;

	Vfirsel.src = Vsecsel.src = '/file/oo.gif';
	if(autotimer) {
		clearTimeout(autotimer);
		autotimer = 0;
	}
	Vwauto.checked = false;
	Vbauto.checked = false;
	if(pos == 0) {
		curstep = pos;
		var mv = prevmove[pos];
		SetFen(mv[0]);
	}
	else if(pos <= prevmove.length) {
		curstep = pos - 1;
		var mv = prevmove[pos-1];
		ClearDesk();
		fens = mv[0];
		Initialize();
		GetFigureMove(mv[2]);
		mv = String(movtable[0]).split(/\./);
		curstep++;
		SetFen(mv[2]);
		Placesecsel(mv[0], 0);
		Placesecsel(mv[1], 1);
	} else {
		busy = 0;
	}
}
function SyncHistory()
{
	var s2 = '<table cellspacing="0" class="movelist" style="width:100%"><thead style="border-spacing: 2px;"><tr><td onClick="NavStep(\'-\')" id="gbck" class="mbutton">&nbsp;<<&nbsp;<\/td><td onClick="NavStep(\'+\')" id="gfwd" class="mbutton">&nbsp;>>&nbsp;<\/td><td onClick="PreviousStep()" id="undo" class="mbutton">&nbsp;<--&nbsp;<\/td><\/tr><tr><td colspan="3"><div style="margin-top:5px;" onClick="nclick(event, 0)" onContextMenu="ncontext(event)">';
	if(curstep == 0) {
		s2 = s2 + '<span id="cur">&nbsp;=====&nbsp;历史着法&nbsp;=====&nbsp;<\/span>';
	} else {
		s2 = s2 + '<b>&nbsp;=====&nbsp;历史着法&nbsp;=====&nbsp;<\/b>';
	}
	s2 = s2 + '<\/div><\/td><\/tr><\/thead><tbody id="movehis" style="margin-top:2px;">';
	if (prevmove.length != 0) {
		for (var x = 0; x < prevmove.length; x += 2) {
			s2 = s2 + '<tr style="height: 20px;"><td><span style="display: inline-block; min-width:30px; text-align:right; background-color: inherit;">' + (x / 2 + 1) + '.&nbsp;<\/span><div onClick="nclick(event, ' + (x + 1) + ')" onContextMenu="ncontext(event)">';
			if(x + 1 == curstep) {
				s2 = s2 + '<span id="cur" style="margin-left: 5px;">' + prevmove[x][1] + '<\/span>';
			} else {
				s2 = s2 + '<span style="margin-left: 5px;">' + prevmove[x][1] + '<\/span>';
			}
			s2 = s2 + '<\/div>&nbsp;';
			if (x + 1 < prevmove.length) {
				s2 = s2 + '<div onClick="nclick(event, ' + (x + 2) + ')" onContextMenu="ncontext(event)">';
				if(x + 2 == curstep) {
					s2 = s2 + '<span id="cur" style="margin-left: 5px;">' + prevmove[x + 1][1] + '<\/span>';
				} else {
					s2 = s2 + '<span style="margin-left: 5px;">' + prevmove[x + 1][1] + '<\/span>';
				}
				s2 = s2 + '<\/div>';
			}
			s2 = s2 + '<\/td><\/tr>';
		}
		s2 = s2 + '<\/tbody><\/table>';
		Vout2.innerHTML = s2;
		var Vcontainer = document.getElementById('movehis');
		var containerRect = Vcontainer.getBoundingClientRect();
		var curRect = document.getElementById('cur').getBoundingClientRect();
		if (curRect.top < containerRect.top || curRect.bottom > containerRect.bottom) {
			Vcontainer.scrollTop = curRect.top;
		}
	} else {
		s2 = s2 + '<\/tbody><\/table>';
		Vout2.innerHTML = s2;
	}
	if(automove) {
		automove = 0;
	} else {
		Vbauto.checked = false;
		Vwauto.checked = false;
	}
	busy = 0;
}
function GetMoveList(s) {
	ClearDot();
	Vselect.src = '/file/oo.gif';
	Vselect.style.left = '0px';
	Vselect.style.top = '0px';

	if (s.search(/unknown/) != -1) {
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">该局面尚未被收录，您可以：<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="RequestQueue()" class="button">&nbsp;提交后台计算&nbsp;<\/td><\/tr><\/table><\/span>';
		if(wb == 0 && Vwauto.checked) {
			alert('白方没有自动走棋着法！');
		} else if (wb == 1 && Vbauto.checked) {
			alert('黑方没有自动走棋着法！');
		}
		SyncHistory();
		return;
	}
	else if (s.search(/checkmate/) != -1) {
		ClearInner();
		if (wb == 0) {
			Vout.innerHTML += '<span style="text-align:center; display:block;">白方被将死！<\/span>';
		}
		else {
			Vout.innerHTML += '<span style="text-align:center; display:block;">黑方被将死！<\/span>';
		}
		SyncHistory();
		return;
	}
	else if (s.search(/stalemate/) != -1) {
		ClearInner();
		if (wb == 0) {
			Vout.innerHTML += '<span style="text-align:center; display:block;">白方被困毙！<\/span>';
		}
		else {
			Vout.innerHTML += '<span style="text-align:center; display:block;">黑方被困毙！<\/span>';
		}
		SyncHistory();
		return;
	}
	else if (s.search(/invalid/) != -1) {
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">当前局面无效！<\/span>';
		SyncHistory();
		return;
	}
	else if (s.search(/exceeded/) != -1) {
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">查询过于频繁！<\/span>';
		SyncHistory();
		return;
	}
	var a = new Array(),
		ml = new String();
	a = trimNull(s).split('|');
	if( !Vhidescore.checked ) {
		s = '<table cellspacing="0" style="text-align:center;" class="movelist"><thead><tr style="height:20px;"><td><b>着法<\/b><\/td><td><b>排序<\/b><\/td><td><b>分数<\/b><\/td><td style="min-width:100px;padding-right:20px;"><b>备注<\/b><\/td><\/tr><\/thead><tbody style="height:600px">';
		var skip = 0;
		for (var x = 0; x < a.length; x++) {
			vs = a[x];
			i = vs.split(',');
			if (x == 0 && i[1] == 'score:??') {
				skip = 1;
			}
			i[0] = i[0].substr(5, i[0].length - 5);
			i[1] = i[1].substr(6, i[1].length - 6);
			//if (i[4]) {
			//	i[1] = i[4].substr(8, i[4].length - 8) + "%";
			//}

			var mov = GetFigureMove(i[0]);
			if (!skip) {
				s = s + '<tr class="hovoff" onMouseOver="OnHover(\'ft' + x + '\')" onMouseOut="OffHover(\'ft' + x + '\')" onClick="mclick(event, \'' + x + '\')" onContextMenu="mcontext(event, \'' + x + '\')" id="ft' + x + '"><td>';
				if ((i[2] == 'rank:2') || (i[2] == 'rank:1')) {
					s = s + '<span>' + mov[1] + '<\/span><\/td>';
				} else {
					s = s + mov[1] + '<\/td>';
				}
				s = s + '<td>' + i[2].substr(5, i[2].length - 5) + '<\/td><td>' + i[1] + '<\/td><td style="min-width:100px;">' + i[3].substr(5, i[3].length - 5) + '<\/td><\/tr>';
			}
		}
		if (skip) {
			ClearInner();
			Vout.innerHTML += '<span style="text-align:center; display:block;">该局面尚未被收录，您可以：<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="RequestQueue()" class="button">&nbsp;提交后台计算&nbsp;<\/td><\/tr><\/table><\/span>';
		} else {
			s = s + '<\/tbody><\/table>';
			Vout.innerHTML = s;
		}
	} else {
		for (var x = 0; x < a.length; x++) {
			vs = a[x];
			i = vs.split(',');
			i[0] = i[0].substr(5, i[0].length - 5);
			GetFigureMove(i[0]);
		}
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">已选择隐藏查询结果！<\/span>';
	}
	if (f == 1) {
		FillDot(GetDeskIDbyFigureID(iif));
	}
	if (Vwauto.checked || Vbauto.checked) {
		automove = 1;
	}
	if ((wb == 0 && Vwauto.checked) || (wb == 1 && Vbauto.checked)) {
		if(autotimer == 0) {
			autotimer = setTimeout("AutoMove()", 500);
		}
	}
	SyncHistory();
	return;
}

function VerifyFEN(s) {
	s = s.replace(/[\r\n]/, '');
	s = s.replace(/%20/g, ' ');
	s = s.replace(/\+/g, ' ');
	s = s.replace(/_/g, ' ');
	s = s.replace(/ moves.*/, '');
	var result = chess.validate_fen(s);
	return result['valid'];
}

function InputFen() {
	var s = prompt("输入局面FEN：", "");
	if (Number(s) == 0) {
		return;
	}
	if (VerifyFEN(s) == 0) {
		alert("无效的局面FEN！");
		return;
	}
	ResetFen(s);
}

function ResetFen(s) {
	if (busy)
		return;
	busy = 1;
	Vfirsel.src = '/file/oo.gif';
	Vsecsel.src = '/file/oo.gif';
	while (prevmove.length)
		prevmove.pop();
	curstep = 0;
	if(autotimer) {
		clearTimeout(autotimer);
		autotimer = 0;
	}
	Vbauto.checked = Vbauto.defaultChecked;
	Vwauto.checked = Vwauto.defaultChecked;
	SetFen(s);
}

function SetFen(s) {
	ClearDesk();
	s = s.replace(/[\r\n]/, '');
	s = s.replace(/%20/g, ' ');
	s = s.replace(/\+/g, ' ');
	s = s.replace(/_/g, ' ');
	var mvl = new Array();
	if (s.search(/ moves /) != -1) {
		mvl = s.split(/ moves /)[1].split(/ /);
	}
	s = s.replace(/ moves.*/g, '');
	fens = s;
	Initialize();
	fens = chess.fen();
	if (mvl.length > 0) {
		for (var i = 0; i < mvl.length; i++) {
			var mov = GetFigureMove2(mvl[i]);
			prevmove.push(new Array(fens, mov[4], mvl[i], mov[2], mov[3]));
			curstep++;
			ClearDesk();
			fens = mov[0];
			Initialize2();
		}
		ClearDesk();
		fens = chess.fen();
		Initialize();
	}
	RefreshAll();
	return;
}

function ChangeFen(id) {
	if (busy)
		return;
	busy = 1;
	var b = new Array();
	b = String(movtable[Number(id)]).split(/\./);
	Placesecsel(b[0], 0);
	Placesecsel(b[1], 1);
	while(prevmove.length > curstep)
		prevmove.pop();
	prevmove.push(new Array(fens, b[5], b[4], b[0], b[1]));
	curstep++;
	SetFen(b[2]);
	return;
}

function GetFigureMove2(s) {
	var ef = new String();
	var fromx = s.charCodeAt(0) - 97;
	var fromy = 8 - (s.charCodeAt(1) - 48);
	var tox = s.charCodeAt(2) - 97;
	var toy = 8 - (s.charCodeAt(3) - 48);

	if (flipmode == 1) {
		FlipDesk();
	}
	var mov = chess.move(s, {sloppy: true});
	var vf = chess.fen();
	var fn = FigureIcons[desk[fromx][fromy].charAt(0)][desk[fromx][fromy].charAt(1)] + mov.san;
	if (flipmode == 1) {
		FlipDesk();
	}
	ef = fromx + ',' + fromy + '.' + tox + ',' + toy + '.' + vf + '.' + fn + '.' + s + '.' + mov.san;
	return new Array(vf, fn, fromx + ',' + fromy, tox + ',' + toy, mov.san);
}

function Initialize2() {
	var s = fens;
	var f = new String();
	z = 0;
	var x = 0;
	for (var y = 0; y < 8; y++) {
		while (x < 8) {
			f = s.charAt(0);
			s = s.substr(1);
			if (f.search(/[1-8]/) == -1) {
				if (f == f.toLowerCase()) {
					f = 'b' + f;
				} else {
					f = 'w' + f;
				}
				if (flipmode) {
					AddFigure(7 - x, 7 - y, f);
				} else {
					AddFigure(x, y, f);
				}
				x++;
			} else {
				x = x + Number(f);
			}
		}
		s = s.substr(1, s.length - 1);
		x = 0;
	}
	f = 0;
	iif = 0;
	unselectpiece();
	return;
}

function FillPV(id, stable) {
	if (busy)
		return;
	busy = 1;
	var b = new Array();
	b = String(movtable[Number(id)]).split(/\./);
	while(prevmove.length > curstep)
		prevmove.pop();
	prevmove.push(new Array(fens, b[5], b[4], b[0], b[1]));

	var xmlhttpPV = getXmlHttp();

	xmlhttpPV.open('GET', apiurl + '?action=querypv&board=' + b[2] + "&stable=" + stable, true);
	xmlhttpPV.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpPV.onreadystatechange = function() {
		if (xmlhttpPV.readyState == 4) {
			if (xmlhttpPV.status == 200) {
				if (xmlhttpPV.responseText.search(/pv:/) != -1) {
					var oldmovtable = movtable.slice();
					GetFigureMove2(b[4]);
					var mvl = trimNull(xmlhttpPV.responseText.split(/pv:/)[1]).split(/\|/);
					ClearDesk();
					fens = b[2];
					Initialize2();
					for (var i = 0; i < mvl.length; i++) {
						var mov = GetFigureMove2(mvl[i]);
						prevmove.push(new Array(fens, mov[4], mvl[i], mov[2], mov[3]));
						ClearDesk();
						fens = mov[0];
						Initialize2();
					}
					ClearDesk();
					fens = prevmove[curstep][0];
					Initialize();
					movtable = oldmovtable;
				}
			}
			SyncHistory();
		}
	};
	xmlhttpPV.send(null);
}

function mclick(e, id) {
	e.preventDefault();
	if (e.shiftKey) {
		FillPV(id, true);
	}
	else {
		ChangeFen(id);
	}
	return false;
}

function mcontext(e, id) {
	e.preventDefault();
	FillPV(id, e.shiftKey);
	return false;
}

function nclick(e, pos) {
	e.preventDefault();
	NavStep(pos);
	return false;
}

function ncontext(e) {
	e.preventDefault();
	return false;
}

function SyncDesk() {
	if (wb == 1) {
		Vbmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/chess/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		Vwmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/chess/wturn.png';
	}
	RefreshAll();
	return;
}

function ClearDesk() {
	ClearDot();
	while (movtable.length) {
		movtable.pop();
	}
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	f = 0;
	iif = 0;
	var s = new String();
	for (var y = 0; y < 8; y++) {
		for (var x = 0; x < 8; x++) {
			if (String(desk[x][y]) != 0) {
				DeleteFigure(x + ',' + y);
			}
		}
	}
	z = 0;
	return;
}

function DeleteFigure(cid) {
	var a = new Array();
	a = cid.split(/,/);
	Vdesk.removeChild(document.getElementById(String(desk[a[0]][a[1]]) + 'd'));
	desk[a[0]][a[1]] = 0;
	return;
}

function Initialize() {
	var s = fens;
	if (curstep > 0) {
		chess.load(prevmove[0][0]);
		for (var x = 0; x < curstep; x++) {
			chess.move(prevmove[x][2], {sloppy: true});
		}
	}
	else {
		chess.load(s);
	}
	var f = new String();
	z = 0;
	if (chess.turn() == 'b') {
		Vbmm.checked = 'checked';
		wb = 1;
		document.getElementById('bturn').src = '/file/chess/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		Vwmm.checked = 'checked';
		wb = 0;
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/chess/wturn.png';
	}
	if (chess.castling_flag().b & chess.BITS.KSIDE_CASTLE) {
		Vbks.checked = 'checked';
	}
	else {
		Vbks.checked = false;
	}
	if (chess.castling_flag().b & chess.BITS.QSIDE_CASTLE) {
		Vbqs.checked = 'checked';
	}
	else {
		Vbqs.checked = false;
	}
	if (chess.castling_flag().w & chess.BITS.KSIDE_CASTLE) {
		Vwks.checked = 'checked';
	}
	else {
		Vwks.checked = false;
	}
	if (chess.castling_flag().w & chess.BITS.QSIDE_CASTLE) {
		Vwqs.checked = 'checked';
	}
	else {
		Vwqs.checked = false;
	}
	var x = 0;
	for (var y = 0; y < 8; y++) {
		while (x < 8) {
			f = s.charAt(0);
			s = s.substr(1);
			if (f.search(/[1-8]/) == -1) {
				if (f == f.toLowerCase()) {
					f = 'b' + f;
				} else {
					f = 'w' + f;
				}
				if (flipmode) {
					AddFigure(7 - x, 7 - y, f);
				} else {
					AddFigure(x, y, f);
				}
				x++;
			} else {
				x = x + Number(f);
			}
		}
		s = s.substr(1, s.length - 1);
		x = 0;
	}
	f = 0;
	iif = 0;
	unselectpiece();
	return;
}

function GetIdCoord(id, k) {
	var xs = new String();
	if (k == 'l') {
		xs = document.getElementById(id).style.left;
	} else {
		xs = document.getElementById(id).style.top;
	}
	return (Number(xs.replace(/px/, '')));

}

function AddFigure(x, y, id) {
	var plaza = Vdesk;
	var s = new String(id + String(z));
	desk[x][y] = s;
	var cid = document.getElementById(x + ',' + y);
	var a = cid.style.left;
	var b = cid.style.top;
	var elem = document.createElement("div");
	elem.style.left = a;
	elem.style.top = b;
	elem.style.zIndex = 6;
	elem.id = id + String(z) + 'd';
	elem.style.Class = 'chess';
	elem.style.position = 'absolute';
	elem.innerHTML = "<img alt='' draggable='true' onmousedown='onmdown2(event,\"" + s + "\")' ondragstart='ondstart(event,\"" + s + "\")' ondragend='ondend(event)' style='position:absolute;z-index:6' id='" + s + "'src='/file/chess/" + id.toLowerCase() + ".svg'>";
	plaza.appendChild(elem);
	plaza.lastChild.style.left = a;
	plaza.lastChild.style.top = b;
	plaza.lastChild.style.width = 45 + 'px';
	plaza.lastChild.style.height = 45 + 'px';
	z++;
	return;
}

function place(cid) {
	var a = new Array();
	var ret = new String();
	var fig = document.getElementById(iif + 'd'),
		plid = document.getElementById(cid);
	a = cid.split(/,/);
	for (var y = 0; y < 8; y++) {
		for (var x = 0; x < 8; x++) {
			if (desk[x][y] == iif) {
				desk[x][y] = 0;
				if (flipmode != 1) {
					ret = x + ',' + y + '.' + cid;
				} else {
					ret = (7 - x) + ',' + (7 - y) + '.' + (7 - a[0]) + ',' + (7 - a[1]);
				}
				x = 9;
				y = 9;
			}
		}
	}
	desk[a[0]][a[1]] = iif;
	fig.style.left = plid.style.left;
	fig.style.top = plid.style.top;
	return (ret);
}

function PlaceFigure(cid) {
	var a = new Array();
	a = cid.split(/,/);
	AddFigure(a[0], a[1], iif);
	return;
}

function VerifyMove(cid) {
	var a = new Array(),
		b = new Array();
	var move = new String();
	a = cid.split(/,/);
	b = String(GetDeskIDbyFigureID(iif)).split(/,/);
	if (flipmode != 1) {
		move = b[0] + ',' + b[1] + '.' + cid;
	} else {
		move = (7 - b[0]) + ',' + (7 - b[1]) + '.' + (7 - a[0]) + ',' + (7 - a[1]);
	}
	var rps = new RegExp(move);
	for (var x = 0; x < movtable.length; x++) {
		if (movtable[x].search(rps) != -1) {
			return (1);
		}
	}
	return (0);
}

function onmdown(cid) {
	if (f == 1) {
		if (VerifyMove(cid)) {
			mdown(cid);
		}
		return;
	} else if (f == 3) {
		if (iif != 0)
		{
			var s = place(cid).split(/\./);
			var src = s[0].split(/,/);
			var dst = s[1].split(/,/);
			chess.put(chess.remove(FigureFiles[src[0]] + (8 - src[1])), FigureFiles[dst[0]] + (8 - dst[1]));
			fens = chess.fen();
			iif = 0;
			Vfirsel.src = '/file/oo.gif';
			Vsecsel.src = '/file/oo.gif';
			Vselect.style.left = 0;
			Vselect.style.top = 0;
			Vselect.src = '/file/oo.gif';
			while (prevmove.length)
				prevmove.pop();
			curstep = 0;
			Vout2.innerHTML = '';
			SyncDesk();
		}
	} else if (f == 0 || f == 2) {
		if (iif != 0 && iif != 'del') {
			var dst = cid.split(/,/);
			if (flipmode == 1) {
				dst[0] = 7 - dst[0];
				dst[1] = 7 - dst[1];
			}
			if(chess.put({ type: iif.charAt(1).toLowerCase(), color: iif.charAt(0) }, FigureFiles[dst[0]] + (8 - dst[1]))) {
				PlaceFigure(cid);
				fens = chess.fen();
				f = 0;
				iif = 0;
				unselectpiece();
				while (prevmove.length)
					prevmove.pop();
				curstep = 0;
				Vout2.innerHTML = '';
				SyncDesk();
			}
		}
	} else {
		f = 0;
		iif = 0;
	}
	return;
}

function GetDeskIDbyFigureID(id) {
	for (var y = 0; y < 8; y++) {
		for (var x = 0; x < 8; x++) {
			if (desk[x][y] == id) {
				return (x + ',' + y);
			}
		}
	}
}

function onmdown2(event, id) {
	if (f == 2) {
		var a = event.clientX;
		var b = event.clientY;
		var k = new String();
		k = GetDeskIDbyFigureID(id);
		var dst = k.split(/,/);
		var tmp = chess.remove(FigureFiles[dst[0]] + (8 - dst[1]));
		if (iif != 'del') {
			if(chess.put({ type: iif.charAt(1).toLowerCase(), color: iif.charAt(0) }, FigureFiles[dst[0]] + (8 - dst[1]))) {
				DeleteFigure(k);
				PlaceFigure(k);
			}
			else {
				chess.put(tmp, FigureFiles[dst[0]] + (8 - dst[1]));
			}
		}
		else {
			DeleteFigure(k);
		}
		fens = chess.fen();
		Vfirsel.src = '/file/oo.gif';
		Vsecsel.src = '/file/oo.gif';
		unselectpiece();
		f = 0;
		iif = 0;
		while (prevmove.length)
			prevmove.pop();
		curstep = 0;
		SyncDesk();
		return;
	}
	if (iif != id) {
		if (f != 3) {
			f = 1;
		}
		ClearDot();
		iif = id;
		Vselect.style.left = GetIdCoord(id + 'd', 'l') + 'px';
		Vselect.style.top = GetIdCoord(id + 'd', 't') + 'px';
		Vselect.src = '/file/chess/select.gif';
		if (f != 3) {
			FillDot(GetDeskIDbyFigureID(id));
		}
	} else {
		if (f != 3) {
			f = 0;
		}
		ClearDot();
		iif = 0;
		Vselect.style.left = 0;
		Vselect.style.top = 0;
		Vselect.src = '/file/oo.gif';
	}
	return;
}

function unselectpiece() {
	var t1 = document.getElementById('tt1').childNodes,
		t2 = document.getElementById('tt2').childNodes;
	for (var x = 0; x < t1.length; x++) {
		if (t1[x].className == 'selpiece') {
			t1[x].className = 'unselpiece';
		}
		if (t2[x].className == 'selpiece') {
			t2[x].className = 'unselpiece';
		}
	}

}

function SelectFigure(id) {
	ClearDot();
	Vselect.style.left = 0;
	Vselect.style.top = 0;
	Vselect.src = '/file/oo.gif';
	unselectpiece();
	if (iif != id && !(f == 3 && id == 'move')) {
		document.getElementById(id + 't').className = 'selpiece';
		f = 2;
		iif = id;
		if (id == 'move') {
			f = 3;
			iif = 0;
			return;
		}
	} else {
		f = 0;
		iif = 0;
	}
	return;
}

function AsyncGetEngineMove() {
	var xmlhttpMove = getXmlHttp();
	xmlhttpMove.open('POST', apiurl, true);
	xmlhttpMove.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpMove.onreadystatechange = function () {
		if (xmlhttpMove.readyState == 4) {
			if (xmlhttpMove.status == 200) {
				var ss = new String();
				var xx;
				if (xmlhttpMove.responseText.search(/move:/) != -1) {
					var rps = new RegExp(trimNull(xmlhttpMove.responseText).substr(5, 5));
					for (var x = 0; x < movtable.length; x++) {
						if (movtable[x].search(rps) != -1) {
							ss = movtable[x];
							xx = x;
							x = movtable.length;
						}
					}
				}
				busy = 0;
				if (ss.length) {
					var b = ss.split(/\./);
					var cid = b[0];
					if (flipmode == 1) {
						var k = new Array();
						k = cid.split(/,/);
						cid = (7 - k[0]) + ',' + (7 - k[1]);
					}
					Vselect.style.left = document.getElementById(cid).style.left;
					Vselect.style.top = document.getElementById(cid).style.top;
					Vselect.src = '/file/chess/select.gif';
					ChangeFen(xx);
				} else {
					Vwauto.checked = false;
					Vbauto.checked = false;
					if (wb == 0) {
						alert('白方没有自动走棋着法！');
					} else {
						alert('黑方没有自动走棋着法！');
					}
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">网络错误！<\/span>';
			}
		}
	};
	var s = fens;
	var movelist = new String();
	if (curstep > 0) {
		s = prevmove[0][0];
		for (var x = 0; x < curstep; x ++) {
			if (movelist.length) {
				movelist = movelist + '|';
			}
			movelist = movelist + prevmove[x][2];
		}
	}

	function d(n, t) {
		var r = (65535 & n) + (65535 & t);
		return (n >> 16) + (t >> 16) + (r >> 16) << 16 | 65535 & r
	}
	function f(n, t, r, e, o, c) {
		return d((u = d(d(t, n), d(e, c))) << (f = o) | u >>> 32 - f, r);
		var u, f
	}
	function l(n, t, r, e, o, c, u) {
		return f(t & r | ~t & e, n, t, o, c, u)
	}
	function m(n, t, r, e, o, c, u) {
		return f(t & e | r & ~e, n, t, o, c, u)
	}
	function v(n, t, r, e, o, c, u) {
		return f(t ^ r ^ e, n, t, o, c, u)
	}
	function g(n, t, r, e, o, c, u) {
		return f(r ^ (t | ~e), n, t, o, c, u)
	}
	function o() {
		var n, t, r, e;
		(t = s.concat(u),
		function(n) {
			var t, r, e = "0123456789abcdef", o = "";
			for (r = 0; r < n.length; r += 1)
				t = n.charCodeAt(r),
				o += e.charAt(t >>> 4 & 15) + e.charAt(15 & t);
			return o
		}((r = t,
		function(n) {
			var t, r = "", e = 32 * n.length;
			for (t = 0; t < e; t += 8)
				r += String.fromCharCode(n[t >> 5] >>> t % 32 & 255);
			return r
		}(function(n, t) {
			var r, e, o, c, u;
			n[t >> 5] |= 128 << t % 32,
			n[14 + (t + 64 >>> 9 << 4)] = t;
			var f = 1732584193
			  , a = -271733879
			  , i = -1732584194
			  , h = 271733878;
			for (r = 0; r < n.length; r += 16)
				a = g(a = g(a = g(a = g(a = v(a = v(a = v(a = v(a = m(a = m(a = m(a = m(a = l(a = l(a = l(a = l(o = a, i = l(c = i, h = l(u = h, f = l(e = f, a, i, h, n[r], 7, -680876936), a, i, n[r + 1], 12, -389564586), f, a, n[r + 2], 17, 606105819), h, f, n[r + 3], 22, -1044525330), i = l(i, h = l(h, f = l(f, a, i, h, n[r + 4], 7, -176418897), a, i, n[r + 5], 12, 1200080426), f, a, n[r + 6], 17, -1473231341), h, f, n[r + 7], 22, -45705983), i = l(i, h = l(h, f = l(f, a, i, h, n[r + 8], 7, 1770035416), a, i, n[r + 9], 12, -1958414417), f, a, n[r + 10], 17, -42063), h, f, n[r + 11], 22, -1990404162), i = l(i, h = l(h, f = l(f, a, i, h, n[r + 12], 7, 1804603682), a, i, n[r + 13], 12, -40341101), f, a, n[r + 14], 17, -1502002290), h, f, n[r + 15], 22, 1236535329), i = m(i, h = m(h, f = m(f, a, i, h, n[r + 1], 5, -165796510), a, i, n[r + 6], 9, -1069501632), f, a, n[r + 11], 14, 643717713), h, f, n[r], 20, -373897302), i = m(i, h = m(h, f = m(f, a, i, h, n[r + 5], 5, -701558691), a, i, n[r + 10], 9, 38016083), f, a, n[r + 15], 14, -660478335), h, f, n[r + 4], 20, -405537848), i = m(i, h = m(h, f = m(f, a, i, h, n[r + 9], 5, 568446438), a, i, n[r + 14], 9, -1019803690), f, a, n[r + 3], 14, -187363961), h, f, n[r + 8], 20, 1163531501), i = m(i, h = m(h, f = m(f, a, i, h, n[r + 13], 5, -1444681467), a, i, n[r + 2], 9, -51403784), f, a, n[r + 7], 14, 1735328473), h, f, n[r + 12], 20, -1926607734), i = v(i, h = v(h, f = v(f, a, i, h, n[r + 5], 4, -378558), a, i, n[r + 8], 11, -2022574463), f, a, n[r + 11], 16, 1839030562), h, f, n[r + 14], 23, -35309556), i = v(i, h = v(h, f = v(f, a, i, h, n[r + 1], 4, -1530992060), a, i, n[r + 4], 11, 1272893353), f, a, n[r + 7], 16, -155497632), h, f, n[r + 10], 23, -1094730640), i = v(i, h = v(h, f = v(f, a, i, h, n[r + 13], 4, 681279174), a, i, n[r], 11, -358537222), f, a, n[r + 3], 16, -722521979), h, f, n[r + 6], 23, 76029189), i = v(i, h = v(h, f = v(f, a, i, h, n[r + 9], 4, -640364487), a, i, n[r + 12], 11, -421815835), f, a, n[r + 15], 16, 530742520), h, f, n[r + 2], 23, -995338651), i = g(i, h = g(h, f = g(f, a, i, h, n[r], 6, -198630844), a, i, n[r + 7], 10, 1126891415), f, a, n[r + 14], 15, -1416354905), h, f, n[r + 5], 21, -57434055), i = g(i, h = g(h, f = g(f, a, i, h, n[r + 12], 6, 1700485571), a, i, n[r + 3], 10, -1894986606), f, a, n[r + 10], 15, -1051523), h, f, n[r + 1], 21, -2054922799), i = g(i, h = g(h, f = g(f, a, i, h, n[r + 8], 6, 1873313359), a, i, n[r + 15], 10, -30611744), f, a, n[r + 6], 15, -1560198380), h, f, n[r + 13], 21, 1309151649), i = g(i, h = g(h, f = g(f, a, i, h, n[r + 4], 6, -145523070), a, i, n[r + 11], 10, -1120210379), f, a, n[r + 2], 15, 718787259), h, f, n[r + 9], 21, -343485551),
				f = d(f, e),
				a = d(a, o),
				i = d(i, c),
				h = d(h, u);
			return [f, a, i, h]
		}(function(n) {
			var t, r = [];
			for (r[(n.length >> 2) - 1] = void 0,
			t = 0; t < r.length; t += 1)
				r[t] = 0;
			var e = 8 * n.length;
			for (t = 0; t < e; t += 8)
				r[t >> 5] |= (255 & n.charCodeAt(t / 8)) << t % 32;
			return r
		}(e = unescape(encodeURIComponent(r))), 8 * e.length))))).substring(0, 2) == Array(3).join("0") ? xmlhttpMove.send('action=queryengine&board=' + s + '&movelist=' + movelist + '&token=' + u) : setTimeout(function() {
			u += Math.floor(100 * Math.random()),
			o()
		}, 0)
	}
	var u = Math.floor(1e4 * Math.random());
	o();
}

function AsyncGetAutoMove() {
	var xmlhttpMove = getXmlHttp();

	xmlhttpMove.open('POST', apiurl, true);
	xmlhttpMove.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpMove.onreadystatechange = function() {
		if (xmlhttpMove.readyState == 4) {
			if (xmlhttpMove.status == 200) {
				var ss = new String();
				var xx;
				if (xmlhttpMove.responseText.search(/move:/) != -1 || xmlhttpMove.responseText.search(/egtb:/) != -1) {
					var rps = new RegExp(trimNull(xmlhttpMove.responseText).substr(5, 5));
					for (var x = 0; x < movtable.length; x++) {
						if (movtable[x].search(rps) != -1) {
							ss = movtable[x];
							xx = x;
							x = movtable.length;
						}
					}
				} else if (xmlhttpMove.responseText.search(/search:/) != -1) {
					var rps = new RegExp(trimNull(xmlhttpMove.responseText).substr(7, 5));
					for (var x = 0; x < movtable.length; x++) {
						if (movtable[x].search(rps) != -1) {
							ss = movtable[x];
							xx = x;
							x = movtable.length;
						}
					}
				} else if (Vlocalengine.checked) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">正在在线计算...<\/span>';
					AsyncGetEngineMove();
					return;
				}
				busy = 0;
				if (ss.length) {
					var b = ss.split(/\./);
					var cid = b[0];
					if (flipmode == 1) {
						var k = new Array();
						k = cid.split(/,/);
						cid = (7 - k[0]) + ',' + (7 - k[1]);
					}
					Vselect.style.left = document.getElementById(cid).style.left;
					Vselect.style.top = document.getElementById(cid).style.top;
					Vselect.src = '/file/chess/select.gif';
					ChangeFen(xx);
				} else {
					Vwauto.checked = false;
					Vbauto.checked = false;
					if(wb == 0) {
						alert('白方没有自动走棋着法！');
					} else {
						alert('黑方没有自动走棋着法！');
					}
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">网络错误！<\/span>';
			}
		}
	};
	if( Vautopolicy.checked ) {
		xmlhttpMove.send('action=query&learn=1&board=' + fens);
	} else {
		xmlhttpMove.send('action=querybest&learn=1&board=' + fens);
	}
}
function AutoMove() {
	if (busy)
		return;
	if(autotimer) {
		clearTimeout(autotimer);
		autotimer = 0;
	}
	if(movtable.length) {
		busy = 1;
		AsyncGetAutoMove();
	}
}
function ScreenShot() {
	html2canvas(document.querySelector("#desk")).then(function(canvas) {
		if(!window.navigator.msSaveBlob) {
			var imglink = document.getElementById('img_link');
			imglink.setAttribute('download', 'screenshot.png');
			imglink.setAttribute('href', canvas.toDataURL("image/png"));
			imglink.click();
		} else {
			window.navigator.msSaveBlob(canvas.msToBlob(), 'screenshot.png');
		}
	});
}
function ondover(ev) {
	ev.preventDefault();
}
function ondstart(ev, id) {
	ev.target.style.opacity = 0;
	ev.target.ondragover = ondover;
	ev.dataTransfer.effectAllowed = "move";
	const img = new Image();
	img.src = ev.target.src;
	ev.dataTransfer.setDragImage(img, img.width / 2, img.height / 2);
	if (iif != id) {
		if (f != 3) {
			f = 1;
		}
		ClearDot();
		iif = id;
		Vselect.style.left = GetIdCoord(id + 'd', 'l') + 'px';
		Vselect.style.top = GetIdCoord(id + 'd', 't') + 'px';
		Vselect.src = '/file/chess/select.gif';
		if (f != 3) {
			FillDot(GetDeskIDbyFigureID(id));
		}
	}
}
function ondend(ev) {
	ev.target.ondragover = null;
	ev.target.style.opacity = 1;
}
function ond(ev, cid) {
	ev.preventDefault();
	mdown(cid);
}

