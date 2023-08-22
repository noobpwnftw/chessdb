var PREFIX = '/';
var apiurl = new String(PREFIX + 'chessdb.php');
var statsurl = new String(PREFIX + 'stats.php?lang=1');
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
var desk = new Array(9);
var movtable = new Array();
var Vselect, Vbmm, Vwmm, Vout, Vout2, Vstats, Vdesk, Vsecsel, Vfirsel, Vrulecheck, Vdtctb, Vdtmtb, Vnumbar1, Vnumbar2, Vhidescore, Vbauto, Vwauto, Vautopolicy, Vlocalengine;

var prevmove = new Array();
desk[0] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[1] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[2] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[3] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[4] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[5] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[6] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[7] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
desk[8] = new Array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);

var FigureDir = new Array();
FigureDir['w'] = new Array();
FigureDir['b'] = new Array();
FigureDir['w'][0] = '+';
FigureDir['w'][1] = '-';
FigureDir['b'][0] = '-';
FigureDir['b'][1] = '+';
FigureDir['p'] = '=';
var FigureOrd = new Array();
FigureOrd['w'] = new Array();
FigureOrd['b'] = new Array();
FigureOrd['w'][0] = '+';
FigureOrd['w'][1] = '-';
FigureOrd['b'][0] = '-';
FigureOrd['b'][1] = '+';
FigureOrd['m'] = '=';
var FigureNames = new Array();
FigureNames['k'] = 'k';
FigureNames['a'] = 'a';
FigureNames['b'] = 'b';
FigureNames['r'] = 'r';
FigureNames['n'] = 'n';
FigureNames['c'] = 'c';
FigureNames['p'] = 'p';
FigureNames['K'] = 'K';
FigureNames['A'] = 'A';
FigureNames['B'] = 'B';
FigureNames['R'] = 'R';
FigureNames['N'] = 'N';
FigureNames['C'] = 'C';
FigureNames['P'] = 'P';

var FigureValues = new Array();
FigureValues['w'] = new Array();
FigureValues['b'] = new Array();
FigureValues['w'][0] = '1';
FigureValues['w'][1] = '2';
FigureValues['w'][2] = '3';
FigureValues['w'][3] = '4';
FigureValues['w'][4] = '5';
FigureValues['w'][5] = '6';
FigureValues['w'][6] = '7';
FigureValues['w'][7] = '8';
FigureValues['w'][8] = '9';
FigureValues['b'][0] = '9';
FigureValues['b'][1] = '8';
FigureValues['b'][2] = '7';
FigureValues['b'][3] = '6';
FigureValues['b'][4] = '5';
FigureValues['b'][5] = '4';
FigureValues['b'][6] = '3';
FigureValues['b'][7] = '2';
FigureValues['b'][8] = '1';

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
			alert("Successfully copied to clipboard!");
		} else {
			prompt("Please press CTRL+C to copy:", ss);
		}
	}
}
function DrawGridNum() {
	var tmpStr = new String();
	Vnumbar1 = document.getElementById("numbar1");
	Vnumbar2 = document.getElementById("numbar2");
	if (flipmode == 1) {
		for (var x = 0; x < 9; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 40 + "px; top: 0px; width: 40px; height: 15px; position: absolute;\">" + FigureValues['w'][x] + "</span>";
		}
		Vnumbar1.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 9; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 40 + "px; top: 0px; width: 40px; height: 15px; position: absolute;\">" + FigureValues['b'][x] + "</span>";
		}
		Vnumbar2.innerHTML = tmpStr;
	}
	else {
		for (var x = 0; x < 9; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 40 + "px; top: 0px; width: 40px; height: 15px; position: absolute;\">" + FigureValues['b'][8-x] + "</span>";
		}
		Vnumbar1.innerHTML = tmpStr;
		tmpStr = "";
		for (var x = 0; x < 9; x++) {
			tmpStr = tmpStr + "<span style=\"left: " + x * 40 + "px; top: 0px; width: 40px; height: 15px; position: absolute;\">" + FigureValues['w'][8-x] + "</span>";
		}
		Vnumbar2.innerHTML = tmpStr;
	}
}
function Start() {
	var plid = new String(),
		inS = new String();

	DrawGridNum();
	Vdesk = document.getElementById("desk");
	Vdesk.innerHTML = "<span>Please wait...<\/span>";
	for (var y = 0; y < 10; y++) {
		for (var x = 0; x < 9; x++) {
			plid = x + ',' + y;
			inS = inS + "<img onmousedown='onmdown(\"" + plid + "\")' style='position:absolute;top:" + y * 40 + "px;left:" + x * 40 + "px;z-index:5;width:40px;height:40px' id='" + plid + "' src='/file/oo.gif'><\/img>";

		}
	}
	inS = inS + '<img alt="" style="z-index:3;margin:0px" src="/file/xiangqi.gif"><img alt="" id="select" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif"><img alt="" id="secselect" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif"><img alt="" id="firselect" style="position:absolute;top:0px;left:0px;z-index:4" src="/file/oo.gif">';
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
	Vdtctb = document.getElementById("dtctb");
	Vdtmtb = document.getElementById("dtmtb");
	Vhidescore = document.getElementById("hidescore");
	Vbauto = document.getElementById("bauto");
	Vwauto = document.getElementById("wauto");
	Vautopolicy = document.getElementById("prandom");
	Vlocalengine = document.getElementById("localengine");
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
	ResetFen("rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w");
	return;
}

function FlipDesk() {
	var f;
	for (var y = 0; y < 5; y++) {
		for (var x = 0; x < 9; x++) {
			f = desk[x][y];
			desk[x][y] = desk[8 - x][9 - y];
			desk[8 - x][9 - y] = f;
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
	for (var y = 0; y < 10; y++) {
		for (var x = 0; x < 9; x++) {
			if (desk[x][y] != 0) {
				piece = document.getElementById(desk[x][y] + 'd');
				to = document.getElementById((8 - x) + ',' + (9 - y));
				piece.style.left = to.style.left;
				piece.style.top = to.style.top;
			}
		}
	}
	FlipDesk();
	if (l) {
		Vfirsel.style.left = (320 - parseInt(Vfirsel.style.left)) + 'px';
		Vfirsel.style.top = (360 - parseInt(Vfirsel.style.top)) + 'px';
		Vsecsel.style.left = (320 - parseInt(Vsecsel.style.left)) + 'px';
		Vsecsel.style.top = (360 - parseInt(Vsecsel.style.top)) + 'px';

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
	if (o == 1) {
		document.getElementById('bturn').src = '/file/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/wturn.png';
	}
	while (prevmove.length)
		prevmove.pop();
	curstep = 0;
	SyncDesk();
	return;
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
	Vout.innerHTML += '<span style="text-align:center; display:block;">Please wait...<\/span>';
	GetInnerPage(fens);
	return;
}

function mdown(cid) {
	wb = 1 - wb;
	if (wb == 1) {
		Vbmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		Vwmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/wturn.png';
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
	Vfirsel.src = '/file/point.gif';
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
	prevmove.push(new Array(fens, b[3], b[4], b[0], b[1]));
	curstep++;
	fens = b[2];

	var cido = document.getElementById(cid);
	Vsecsel.style.left = cido.style.left;
	Vsecsel.style.top = cido.style.top;
	Vsecsel.src = "/file/point.gif";

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
		cid = (8 - k[0]) + ',' + (9 - k[1]);
	}
	var cido = document.getElementById(cid);
	if (p) {
		Vsecsel.style.left = cido.style.left;
		Vsecsel.style.top = cido.style.top;
		Vsecsel.src = "/file/point.gif";
	} else {
		Vfirsel.style.left = cido.style.left;
		Vfirsel.style.top = cido.style.top;
		Vfirsel.src = "/file/point.gif";
	}
	return;
}

function PlaceDot(cid) {
	var k = new Array();
	k = cid.split(/,/);
	if (flipmode == 1) {
		cid = (8 - k[0]) + ',' + (9 - k[1]);
		k = cid.split(/,/);
	}
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
		elem.innerHTML = "<img alt='' onmousedown='mdown(\"" + cid + "\")' ondragover='ondover(event)' ondrop='ond(event, \"" + cid + "\")' onstyle='position:absolute;z-index:7' src='/file/cap.gif'>";
	} else {
		elem.innerHTML = "<img alt='' onmousedown='mdown(\"" + cid + "\")' ondragover='ondover(event)' ondrop='ond(event, \"" + cid + "\")' onstyle='position:absolute;z-index:7' src='/file/waypoint.gif'>";
	}
	plaza.appendChild(elem);
	plaza.lastChild.style.left = a;
	plaza.lastChild.style.top = b;
	plaza.lastChild.style.width = 40 + 'px';
	plaza.lastChild.style.height = 40 + 'px';
	return;
}

function FillDot(cid) {
	var b = new Array();
	if (flipmode == 1) {
		var a = new Array();
		a = cid.split(/,/);
		cid = (8 - a[0]) + ',' + (9 - a[1]);
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
	var fromy = 9 - (s.charCodeAt(1) - 48);
	var tox = s.charCodeAt(2) - 97;
	var toy = 9 - (s.charCodeAt(3) - 48);

	if (flipmode == 1) {
		FlipDesk();
	}
	var vf = new String();
	vf = '';
	var c = 0;
	for (var y = 0; y < 10; y++) {
		if (y != 0)
			vf = vf + '/';
		for (var x = 0; x < 9; x++) {
			if (x == fromx && y == fromy) {
				c++;
			} else if ((x != tox || y != toy) && desk[x][y] == 0) {
				c++;
			} else {
				if (c != 0) {
					vf = vf + c;
					c = 0;
				}
				if (x == tox && y == toy) {
					vf = vf + desk[fromx][fromy].charAt(1);
				} else {
					vf = vf + desk[x][y].charAt(1);
				}
			}
		}
		if (c != 0) {
			vf = vf + c;
			c = 0;
		}
	}
	if (wb == 1) {
		vf = vf + ' w';
	} else {
		vf = vf + ' b';
	}
	var fn = GetFigureMoveName(s);
	if (flipmode == 1) {
		FlipDesk();
	}
	ef = fromx + ',' + fromy + '.' + tox + ',' + toy + '.' + vf + '.' + fn + '.' + s;
	movtable.push(ef);
	return new Array(vf, fn, fromx + ',' + fromy, tox + ',' + toy);
}

function GetFigureMoveName(s) {
	var fromx = s.charCodeAt(0) - 97;
	var fromy = 9 - (s.charCodeAt(1) - 48);
	var tox = s.charCodeAt(2) - 97;
	var toy = 9 - (s.charCodeAt(3) - 48);

	var c = 0;
	var cc = 0;
	var cr = 0;
	var s1 = new String();
	if (desk[fromx][fromy].charAt(1) != 'A' && desk[fromx][fromy].charAt(1) != 'B' && desk[fromx][fromy].charAt(1) != 'a' && desk[fromx][fromy].charAt(1) != 'b') {
		for (var x = 0; x < 9; x++) {
			if (x == fromx) {
				continue;
			}
			for (var y = 0; y < 10; y++) {
				if (desk[x][y] != 0 && desk[x][y].substr(0, 2) == desk[fromx][fromy].substr(0, 2)) {
					cr++;
				}
			}
			if (cr < 2) {
				cr = 0;
			}
		}
		for (var y = 0; y < 10; y++) {
			if (desk[fromx][y] != 0 && desk[fromx][y].substr(0, 2) == desk[fromx][fromy].substr(0, 2)) {
				if (y < fromy) {
					if (cr == 0) {
						s1 = FigureOrd[desk[fromx][fromy].charAt(0)][1] + FigureNames[desk[fromx][fromy].charAt(1)];
						c++;
					} else {
						s1 = FigureOrd[desk[fromx][fromy].charAt(0)][1] + FigureValues[desk[fromx][fromy].charAt(0)][9 - fromx - 1];
						c++;
					}
				} else if (y == fromy) {
					if (c == 0) {
						s1 = FigureNames[desk[fromx][fromy].charAt(1)] + FigureValues[desk[fromx][fromy].charAt(0)][9 - fromx - 1];
					} else {
						cc = c;
					}
				} else {
					if (cr == 0) {
						if (c == 0) {
							s1 = FigureOrd[desk[fromx][fromy].charAt(0)][0] + FigureNames[desk[fromx][fromy].charAt(1)];
						} else {
							s1 = FigureOrd['m'] + FigureNames[desk[fromx][fromy].charAt(1)];
							c++;
						}
					} else {
						if (c == 0) {
							s1 = FigureOrd[desk[fromx][fromy].charAt(0)][0] + FigureValues[desk[fromx][fromy].charAt(0)][9 - fromx - 1];
						} else {
							s1 = FigureOrd['m'] + FigureValues[desk[fromx][fromy].charAt(0)][9 - fromx - 1];
							c++;
						}
					}
				}
			}
		}
		if (c > 2 && c != cc) {
			if (desk[fromx][fromy].charAt(0) == 'w') {
				s1 = FigureValues['w'][cc] + FigureNames[desk[fromx][fromy].charAt(1)];
			} else {
				s1 = FigureValues['w'][c - cc] + FigureNames[desk[fromx][fromy].charAt(1)];
			}
		}
	} else {
		s1 = FigureNames[desk[fromx][fromy].charAt(1)] + FigureValues[desk[fromx][fromy].charAt(0)][9 - fromx - 1];
	}
	var s2 = new String();
	if (fromy > toy) {
		if (fromx == tox) {
			if (desk[fromx][fromy].charAt(0) == 'w') {
				s2 = FigureDir[desk[fromx][fromy].charAt(0)][0] + FigureValues[desk[fromx][fromy].charAt(0)][(fromy - toy - 1)];
			} else {
				s2 = FigureDir[desk[fromx][fromy].charAt(0)][0] + FigureValues[desk[fromx][fromy].charAt(0)][9 - (fromy - toy - 1) - 1];
			}
		} else {
			s2 = FigureDir[desk[fromx][fromy].charAt(0)][0] + FigureValues[desk[fromx][fromy].charAt(0)][9 - tox - 1];
		}
	} else if (fromy == toy) {
		s2 = FigureDir['p'] + FigureValues[desk[fromx][fromy].charAt(0)][9 - tox - 1];
	} else {
		if (fromx == tox) {
			if (desk[fromx][fromy].charAt(0) == 'w') {
				s2 = FigureDir[desk[fromx][fromy].charAt(0)][1] + FigureValues[desk[fromx][fromy].charAt(0)][(toy - fromy - 1)];
			} else {
				s2 = FigureDir[desk[fromx][fromy].charAt(0)][1] + FigureValues[desk[fromx][fromy].charAt(0)][9 - (toy - fromy - 1) - 1];
			}
		} else {
			s2 = FigureDir[desk[fromx][fromy].charAt(0)][1] + FigureValues[desk[fromx][fromy].charAt(0)][9 - tox - 1];
		}
	}
	return s1 + s2;
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
	Vout.innerHTML = '<table cellspacing="0" style="text-align:center;" class="movelist"><thead><tr style="height:20px;"><td><b>Move<\/b><\/td><td><b>Rank<\/b><\/td><td><b>Score<\/b><\/td><td style="min-width:100px;padding-right:20px;"><b>Notes<\/b><\/td><\/tr><\/thead><\/table>';
}

function RequestQueue() {
	ClearInner();
	Vout.innerHTML += '<span style="text-align:center; display:block;">Please wait...<\/span>';

	var xmlhttpQueue = getXmlHttp();

	xmlhttpQueue.open('GET', apiurl + '?action=queue&board=' + fens, true);
	xmlhttpQueue.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpQueue.onreadystatechange = function() {
		if (xmlhttpQueue.readyState == 4) {
			if (xmlhttpQueue.status == 200) {
				if(xmlhttpQueue.responseText.search(/ok/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Request successful, refresh in 5 seconds...<\/span>';
					if(autotimer == 0) {
						autotimer = setTimeout("RefreshInner()", 5000);
					}
				}
				else if(xmlhttpQueue.responseText.search(/invalid/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Invalid board!<\/span>';
				}
				else if(xmlhttpQueue.responseText.search(/exceeded/) != -1) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Query rate limit exceeded!<\/span>';
				}
				else {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Too few pieces on the board, you can:<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="AutoMove()" class="button">&nbsp;AI move&nbsp;<\/td><\/tr><\/table><\/span>';
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
			}
		}
	};
	xmlhttpQueue.send(null);
}
function AsyncUpdateMoves(e) {
	var xmlhttp = getXmlHttp();
	if( Vdtctb.checked )
		xmlhttp.open('GET', apiurl + '?action=queryall&learn=1&showall=1&egtbmetric=dtc&board=' + e, true);
	else
		xmlhttp.open('GET', apiurl + '?action=queryall&learn=1&showall=1&board=' + e, true);

	xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttp.onreadystatechange = function() {
		if (xmlhttp.readyState == 4) {
			if (xmlhttp.status == 200) {
				var s = xmlhttp.responseText.replace(/[\r\n]/, '');
				GetMoveList(s);
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
				SyncHistory();
			}
		}
	};
	xmlhttp.send(null);
}

function GetInnerPage(e) {
	if (Vrulecheck.checked && curstep >= 4) {
		var movelist = new String();
		for (var x = 0; x < curstep; x ++) {
			if (movelist.length) {
				movelist = movelist + '|';
			}
			movelist = movelist + prevmove[x][2];
		}
		var xmlhttpRule = getXmlHttp();

		xmlhttpRule.open('POST', apiurl, true);
		xmlhttpRule.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xmlhttpRule.onreadystatechange = function() {
			if (xmlhttpRule.readyState == 4) {
				if (xmlhttpRule.status == 200) {
					var ruleResult = parseInt(xmlhttpRule.responseText);
					if (ruleResult == 0) {
						AsyncUpdateMoves(e);
					}
					else if (ruleResult == 1) {
						ClearInner();
						Vout.innerHTML += '<span style="text-align:center; display:block;">Draw by rule!<\/span>';
						SyncHistory();
					}
					else if (ruleResult == 2) {
						AsyncUpdateMoves(e);
					}
					else if (ruleResult == 3) {
						ClearInner();
						if (wb == 0) {
							Vout.innerHTML += '<span style="text-align:center; display:block;">Black lost by rule!<\/span>';
						} else {
							Vout.innerHTML += '<span style="text-align:center; display:block;">Red lost by rule!<\/span>';
						}
						SyncHistory();
					}
					else {
						ClearInner();
						Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
						SyncHistory();
					}
				} else {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
					SyncHistory();
				}
			}
		};
		xmlhttpRule.send('action=rulecheck&board=' + prevmove[0][0] + '&movelist=' + movelist);
	}
	else {
		AsyncUpdateMoves(e);
	}
}

function GetPage(e) {
	ClearInner();
	Vout.innerHTML += '<span style="text-align:center; display:block;">Please wait...<\/span>';

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
		b[0] = (8 - k[0]) + ',' + (9 - k[1]);
	}
	Vselect.style.left = document.getElementById(b[0]).style.left;
	Vselect.style.top = document.getElementById(b[0]).style.top;
	Vselect.src = '/file/select.gif';
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
		case 84:
			e.preventDefault();
			ToggleMetric();
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
		curstep = pos;
		var mv = prevmove[pos-1];
		ClearDesk();
		fens = mv[0];
		Initialize();
		GetFigureMove(mv[2]);
		mv = String(movtable[0]).split(/\./);
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
		s2 = s2 + '<span id="cur">&nbsp;&nbsp;===&nbsp;Move History&nbsp;===&nbsp;&nbsp;<\/span>';
	} else {
		s2 = s2 + '<b>&nbsp;&nbsp;===&nbsp;Move History&nbsp;===&nbsp;&nbsp;<\/b>';
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
		Vout.innerHTML += '<span style="text-align:center; display:block;">This position is not yet analyzed, you can:<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="RequestQueue()" class="button">&nbsp;Request for analysis&nbsp;<\/td><td>&nbsp;or&nbsp;<\/td><td onClick="ToggleMetric()" class="button">&nbsp;Toggle EGTB metric&nbsp;<\/td><\/tr><\/table><\/span>';
		if(wb == 0 && Vwauto.checked) {
			alert('No AI move for red!');
		} else if (wb == 1 && Vbauto.checked) {
			alert('No AI move for black!');
		}
		SyncHistory();
		return;
	}
	else if (s.search(/checkmate/) != -1) {
		ClearInner();
		if (wb == 0) {
			Vout.innerHTML += '<span style="text-align:center; display:block;">Red is checkmated!<\/span>';
		}
		else {
			Vout.innerHTML += '<span style="text-align:center; display:block;">Black is checkmated!<\/span>';
		}
		SyncHistory();
		return;
	}
	else if (s.search(/stalemate/) != -1) {
		ClearInner();
		if (wb == 0) {
			Vout.innerHTML += '<span style="text-align:center; display:block;">Red is stalemated!<\/span>';
		}
		else {
			Vout.innerHTML += '<span style="text-align:center; display:block;">Black is stalemated!<\/span>';
		}
		SyncHistory();
		return;
	}
	else if (s.search(/invalid/) != -1) {
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">Invalid board!<\/span>';
		SyncHistory();
		return;
	}
	else if (s.search(/exceeded/) != -1) {
		ClearInner();
		Vout.innerHTML += '<span style="text-align:center; display:block;">Query rate limit exceeded!<\/span>';
		SyncHistory();
		return;
	}
	var a = new Array(),
		ml = new String();
	a = trimNull(s).split('|');
	if( !Vhidescore.checked ) {
		s = '<table cellspacing="0" style="text-align:center;" class="movelist"><thead><tr style="height:20px;"><td><b>Move<\/b><\/td><td><b>Rank<\/b><\/td><td><b>Score<\/b><\/td><td style="min-width:100px;padding-right:20px;"><b>Notes<\/b><\/td><\/tr><\/thead><tbody style="height:640px">';
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
			Vout.innerHTML += '<span style="text-align:center; display:block;">This position is not yet analyzed, you can:<table style="margin-left: auto;margin-right: auto;"><tr><td onClick="RequestQueue()" class="button">&nbsp;Request for analysis&nbsp;<\/td><td>&nbsp;or&nbsp;<\/td><td onClick="ToggleMetric()" class="button">&nbsp;Toggle EGTB metric&nbsp;<\/td><\/tr><\/table><\/span>';
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
		Vout.innerHTML += '<span style="text-align:center; display:block;">Results are hidden.<\/span>';
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
	s = s.replace(/ b.*/, ' b');
	s = s.replace(/ w.*/, ' w');
	s = s.replace(/ r.*/, ' w');

	var a = new Array();
	var sum = 0;
	var w = new String(s.substr(s.length - 2, 2));
	w = w.toLowerCase();
	if (w != ' w' && w != ' b') {
		return (0);
	}
	s = s.substr(0, s.length - 2);
	a = String(s).split(/\//);
	if (a.length != 10) {
		return (0);
	}
	for (var x = 0; x < 10; x++) {
		sum = 0;
		if (String(a[x]).search(/[^1-9kabnrcpKABNRCP]/) != -1) {
			return (0);
		}
		a[x] = String(a[x]).replace(/[kabnrcpKABNRCP]/g, '1');
		while (String(a[x]).length != 0) {
			sum = sum + Number(String(a[x]).charAt(0));
			a[x] = String(a[x]).substr(1);
		}
		if (sum != 9) {
			return (0);
		}
	}
	return (1);
}

function InputFen() {
	var s = prompt("Input FEN:", "");
	if (Number(s) == 0) {
		return;
	}
	if (VerifyFEN(s) == 0) {
		alert("Invalid FEN!");
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
	s = s.replace(/ b.*/, ' b');
	s = s.replace(/ w.*/, ' w');
	s = s.replace(/ r.*/, ' w');
	fens = s;
	Initialize();
	for (var i = 0; i < mvl.length; i++) {
		var mov = GetFigureMove(mvl[i]);
		prevmove.push(new Array(fens, mov[1], mvl[i], mov[2], mov[3]));
		curstep++;
		ClearDesk();
		fens = mov[0];
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
	prevmove.push(new Array(fens, b[3], b[4], b[0], b[1]));
	curstep++;
	SetFen(b[2]);
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
	prevmove.push(new Array(fens, b[3], b[4], b[0], b[1]));

	var xmlhttpPV = getXmlHttp();

	xmlhttpPV.open('GET', apiurl + '?action=querypv&board=' + b[2] + "&stable=" + stable, true);
	xmlhttpPV.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpPV.onreadystatechange = function() {
		if (xmlhttpPV.readyState == 4) {
			if (xmlhttpPV.status == 200) {
				if (xmlhttpPV.responseText.search(/pv:/) != -1) {
					var oldmovtable = movtable.slice();
					var mvl = trimNull(xmlhttpPV.responseText.split(/pv:/)[1]).split(/\|/);
					ClearDesk();
					fens = b[2];
					Initialize();
					for (var i = 0; i < mvl.length; i++) {
						var mov = GetFigureMove(mvl[i]);
						prevmove.push(new Array(fens, mov[1], mvl[i], mov[2], mov[3]));
						ClearDesk();
						fens = mov[0];
						Initialize();
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
	if (flipmode == 1) {
		FlipDesk();
	}
	var vf = new String();
	fens = '';
	var c = 0;
	for (var y = 0; y < 10; y++) {
		if (y != 0)
			fens = fens + '/';
		for (var x = 0; x < 9; x++) {
			if (desk[x][y] == 0) {
				c++;
			} else {
				if (c != 0) {
					fens = fens + c;
					c = 0;
				}
				if (desk[x][y].charAt(0) == 'b') {
					fens = fens + desk[x][y].charAt(1);
				} else {
					vf = desk[x][y].charAt(1);
					fens = fens + vf.toUpperCase();
				}
			}
		}
		if (c != 0) {
			fens = fens + c;
			c = 0;
		}
	}
	if (wb == 1) {
		fens = fens + ' b';
		Vbmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		fens = fens + ' w';
		Vwmm.checked = 'checked';
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/wturn.png';
	}
	
	if (flipmode == 1) {
		FlipDesk();
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
	for (var y = 0; y < 10; y++) {
		for (var x = 0; x < 9; x++) {
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
	var f = new String();
	z = 0;
	if (s.charAt(s.length - 1) == 'b') {
		Vbmm.checked = 'checked';
		wb = 1;
		document.getElementById('bturn').src = '/file/bturn.png';
		document.getElementById('wturn').src = '/file/ooo.gif';
	} else {
		Vwmm.checked = 'checked';
		wb = 0;
		document.getElementById('bturn').src = '/file/ooo.gif';
		document.getElementById('wturn').src = '/file/wturn.png';
	}
	var x = 0;
	for (var y = 0; y < 10; y++) {
		while (x < 9) {
			f = s.charAt(0);
			s = s.substr(1);
			if (f.search(/[1-9]/) == -1) {
				if (f == f.toLowerCase()) {
					f = 'b' + f;
				} else {
					f = 'w' + f;
				}
				if (flipmode) {
					AddFigure(8 - x, 9 - y, f);
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
	elem.innerHTML = "<img alt='' draggable='true' onmousedown='onmdown2(event,\"" + s + "\")' ondragstart='ondstart(event,\"" + s + "\")' ondragend='ondend(event)' style='position:absolute;z-index:6' id='" + s + "'src='/file/" + id.toLowerCase() + ".png'>";
	plaza.appendChild(elem);
	plaza.lastChild.style.left = a;
	plaza.lastChild.style.top = b;
	plaza.lastChild.style.width = 40 + 'px';
	plaza.lastChild.style.height = 40 + 'px';
	z++;
	return;
}

function place(cid) {
	var a = new Array();
	var ret = new String();
	var fig = document.getElementById(iif + 'd'),
		plid = document.getElementById(cid);
	a = cid.split(/,/);
	for (var y = 0; y < 10; y++) {
		for (var x = 0; x < 9; x++) {
			if (desk[x][y] == iif) {
				desk[x][y] = 0;
				if (flipmode != 1) {
					ret = x + ',' + y + '.' + cid;
				} else {
					ret = (8 - x) + ',' + (9 - y) + '.' + (8 - a[0]) + ',' + (9 - a[1]);
				}
				x = 10;
				y = 11;
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
		move = (8 - b[0]) + ',' + (9 - b[1]) + '.' + (8 - a[0]) + ',' + (9 - a[1]);
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
			place(cid);
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
			PlaceFigure(cid);
			f = 0;
			iif = 0;
			unselectpiece();
			while (prevmove.length)
				prevmove.pop();
			curstep = 0;
			Vout2.innerHTML = '';
			SyncDesk();
		}
	} else {
		f = 0;
		iif = 0;
	}
	return;
}

function GetDeskIDbyFigureID(id) {
	for (var y = 0; y < 10; y++) {
		for (var x = 0; x < 9; x++) {
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
		DeleteFigure(k);
		if (iif != 'del') {
			PlaceFigure(k);
		}
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
		Vselect.src = '/file/select.gif';
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
					var rps = new RegExp(xmlhttpMove.responseText.substr(5, 4));
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
						cid = (8 - k[0]) + ',' + (9 - k[1]);
					}
					Vselect.style.left = document.getElementById(cid).style.left;
					Vselect.style.top = document.getElementById(cid).style.top;
					Vselect.src = '/file/select.gif';
					ChangeFen(xx);
				} else {
					Vwauto.checked = false;
					Vbauto.checked = false;
					if (wb == 0) {
						alert('No AI move for red!');
					} else {
						alert('No AI move for black!');
					}
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
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
		}(e = unescape(encodeURIComponent(r))), 8 * e.length))))).substring(0, 2) == Array(3).join("0") ? (Vdtctb.checked ? xmlhttpMove.send('action=queryengine&egtbmetric=dtc&board=' + s + '&movelist=' + movelist + '&token=' + u) : xmlhttpMove.send('action=queryengine&board=' + s + '&movelist=' + movelist + '&token=' + u)) : setTimeout(function() {
			u += Math.floor(100 * Math.random()),
			o()
		}, 0)
	}
	var u = Math.floor(1e4 * Math.random());
	o();
}

function AsyncGetAutoMove(banarr) {
	var xmlhttpMove = getXmlHttp();

	var banlist = new String();
	if (banarr.length) {
		for (var x = 0; x < banarr.length; x ++) {
			if (banlist.length) {
				banlist = banlist + '|';
			}
			banlist = banlist + banarr[x];
		}
	}
	xmlhttpMove.open('POST', apiurl, true);
	xmlhttpMove.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
	xmlhttpMove.onreadystatechange = function() {
		if (xmlhttpMove.readyState == 4) {
			if (xmlhttpMove.status == 200) {
				var ss = new String();
				var xx;
				if (xmlhttpMove.responseText.search(/move:/) != -1 || xmlhttpMove.responseText.search(/egtb:/) != -1) {
					var rps = new RegExp(xmlhttpMove.responseText.substr(5, 4));
					for (var x = 0; x < movtable.length; x++) {
						if (movtable[x].search(rps) != -1) {
							ss = movtable[x];
							xx = x;
							x = movtable.length;
						}
					}
				} else if (xmlhttpMove.responseText.search(/search:/) != -1) {
					var rps = new RegExp(xmlhttpMove.responseText.substr(7, 4));
					for (var x = 0; x < movtable.length; x++) {
						if (movtable[x].search(rps) != -1) {
							ss = movtable[x];
							xx = x;
							x = movtable.length;
						}
					}
				} else if (Vlocalengine.checked) {
					ClearInner();
					Vout.innerHTML += '<span style="text-align:center; display:block;">Live AI running...<\/span>';
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
						cid = (8 - k[0]) + ',' + (9 - k[1]);
					}
					Vselect.style.left = document.getElementById(cid).style.left;
					Vselect.style.top = document.getElementById(cid).style.top;
					Vselect.src = '/file/select.gif';
					ChangeFen(xx);
				} else {
					Vwauto.checked = false;
					Vbauto.checked = false;
					if(wb == 0) {
						alert('No AI move for red!');
					} else {
						alert('No AI move for black!');
					}
				}
			} else {
				ClearInner();
				Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
			}
		}
	};
	if( Vautopolicy.checked ) {
		if( Vdtctb.checked )
			xmlhttpMove.send('action=query&learn=1&egtbmetric=dtc&board=' + fens + '&ban=' + banlist);
		else
			xmlhttpMove.send('action=query&learn=1&board=' + fens + '&ban=' + banlist);
	} else {
		if( Vdtctb.checked )
			xmlhttpMove.send('action=querybest&learn=1&egtbmetric=dtc&board=' + fens + '&ban=' + banlist);
		else
			xmlhttpMove.send('action=querybest&learn=1&board=' + fens + '&ban=' + banlist);
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
		var banarr = new Array();
		if (curstep >= 4) {
			var movelist = new String();
			for (var x = 0; x < curstep; x ++) {
				if (movelist.length) {
					movelist = movelist + '|';
				}
				movelist = movelist + prevmove[x][2];
			}
			var xmlhttpAuto = getXmlHttp();
			xmlhttpAuto.open('POST', apiurl, true);
			xmlhttpAuto.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xmlhttpAuto.onreadystatechange = function() {
				if (xmlhttpAuto.readyState == 4) {
					if (xmlhttpAuto.status == 200) {
						if(xmlhttpAuto.responseText.search(/move:/) != -1) {
							var rulelist = xmlhttpAuto.responseText.split('|');
							for (var x = 0; x < rulelist.length; x++) {
								if(rulelist[x].search(/,rule:ban/) != -1) {
									banarr.push(rulelist[x].split(',')[0]);
								}
							}
							AsyncGetAutoMove(banarr);
						} else {
							Vwauto.checked = false;
							Vbauto.checked = false;
							if(wb == 0) {
								alert('No AI move for red!');
							} else {
								alert('No AI move for black!');
							}
						}
					} else {
						ClearInner();
						Vout.innerHTML += '<span style="text-align:center; display:block;">Network error!<\/span>';
					}
				}
			};
			xmlhttpAuto.send('action=queryrule&board=' + prevmove[0][0] + '&movelist=' + movelist);
		} else {
			AsyncGetAutoMove(banarr);
		}
	}
}
function ToggleMetric() {
	if( Vdtctb.checked )
		Vdtmtb.checked = true;
	else
		Vdtctb.checked = true;
	RefreshInner();
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
		Vselect.src = '/file/select.gif';
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

