#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

extern "C" {
#include "php.h"
}
#include "php_ccboard.h"

#include <cctype>
#include <string>
#include <stdlib.h>
#include "xiangqi.h"
#include <vector>

zend_function_entry ccboard_functions[] = {
	PHP_FE(ccbgetfen, NULL)
	PHP_FE(ccbmovegen, NULL)
	PHP_FE(ccbmovemake, NULL)
	PHP_FE(ccbgetLRfen, NULL)
	PHP_FE(ccbgetBWfen, NULL)
	PHP_FE(ccbgetLRBWfen, NULL)
	PHP_FE(ccbgetLRmove, NULL)
	PHP_FE(ccbgetBWmove, NULL)
	PHP_FE(ccbgetLRBWmove, NULL)
	PHP_FE(ccbincheck, NULL)
	PHP_FE(ccbfen2hexfen, NULL)
	PHP_FE(ccbhexfen2fen, NULL)
	PHP_FE(ccbrulecheck, NULL)
	PHP_FE(ccbruleischase, NULL)
	{NULL, NULL, NULL}
};

zend_module_entry ccboard_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
	STANDARD_MODULE_HEADER,
#endif
	"ccboard",
	ccboard_functions,
	PHP_MINIT(ccboard),
	NULL,
	NULL,
	NULL,
	NULL,
#if ZEND_MODULE_API_NO >= 20010901
	"0.1",
#endif
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CCBOARD
extern "C" {
ZEND_GET_MODULE(ccboard)
}
#endif

PHP_MINIT_FUNCTION(ccboard)
{
	init();
	return SUCCESS;
}
PHP_FUNCTION(ccbgetfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		Position pos;
		if(pos.from_fen(fenstr) && pos.is_legal())
		{
			char fen[100];
			pos.to_fen(fen);
			RETURN_STRING(fen);
		}
	}
	RETURN_NULL();
}
PHP_FUNCTION(ccbmovegen)
{
	char* fenstr;
	size_t fenstr_len;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		Position pos;
		if (pos.from_fen(fenstr) && pos.is_legal()) {
			Move_List list;
			pos.gen_legals(list);
			for (int i = 0; i < list.size(); i++)
			{
				char movestr[5];
				move_to_string(list.move(i), movestr);
				add_assoc_long(return_value, movestr, 0);
			}
		}
	}
}
PHP_FUNCTION(ccbincheck)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		Position pos;
		pos.from_fen(fenstr);
		RETURN_BOOL(pos.in_check(pos.turn));
	}
	RETURN_NULL();
}
PHP_FUNCTION(ccbmovemake)
{
	char* fenstr;
	size_t fenstr_len;
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ss", &fenstr, &fenstr_len, &movestr, &movestr_len) != FAILURE) {
		Position pos;
		pos.from_fen(fenstr);
		int move = move_from_string(movestr);
		char fen[100];
		pos.move_do(move);
		pos.to_fen(fen);
		RETURN_STRING(fen);
	}
	RETURN_NULL();
}
void mystrrev(char *s)
{
	if(NULL == s)
		return;

	char *pBegin = s;
	char *pEnd = s + strlen(s) - 1;
	char pTemp;
	while(pBegin < pEnd)
	{
		pTemp = *pBegin;
		*pBegin = *pEnd;
		*pEnd = pTemp;
		++pBegin, --pEnd;
	}
}
char char2bithex(char ch)
{
	switch(ch)
	{
		case '1':
			return '0';
		case 'p':
			return '1';
		case 'a':
			return '2';
		case 'b':
			return '3';
		case 'c':
			return '4';
		case 'n':
			return '5';
		case 'r':
			return '6';
		case 'k':
			return '7';
			
		case 'P':
			return '9';
		case 'A':
			return 'a';
		case 'B':
			return 'b';
		case 'C':
			return 'c';
		case 'N':
			return 'd';
		case 'R':
			return 'e';
		case 'K':
			return 'f';
		default:
			return '8';
	}
}
char bithex2char(unsigned char ch)
{
	switch(ch)
	{
		case '0':
			return '1';
		case '1':
			return 'p';
		case '2':
			return 'a';
		case '3':
			return 'b';
		case '4':
			return 'c';
		case '5':
			return 'n';
		case '6':
			return 'r';
		case '7':
			return 'k';
			
		case '9':
			return 'P';
		case 'a':
			return 'A';
		case 'b':
			return 'B';
		case 'c':
			return 'C';
		case 'd':
			return 'N';
		case 'e':
			return 'R';
		case 'f':
			return 'K';
	}
}
#define TRUNC(a, b) ((a) + ((b) - (((a) % (b)) ? ((a) % (b)) : (b))))
PHP_FUNCTION(ccbfen2hexfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		char bitstr[93];
		int index = 0;
		int tmpindex = 0;
		while(index < fenstr_len)
		{
			char curCh = fenstr[index];
			if(curCh == ' ')
			{
				if(fenstr[index+1] == 'b')
				{
					bitstr[tmpindex++] = '1';
				}
				break;
			}
			else if(curCh == '/')
			{
				index++;
			}
			else
			{
				bitstr[tmpindex++] = char2bithex(curCh);
				if(curCh >= '2' && curCh <= '9')
				{
					bitstr[tmpindex++] = curCh - 1;
				}
				index++;
			}
		}
		if(tmpindex % 2)
		{
			if(bitstr[tmpindex - 1] == '0')
			{
				bitstr[tmpindex - 1] = '\0';
			}
			else
			{
				bitstr[tmpindex++] = '0';
				bitstr[tmpindex] = '\0';
			}
		}
		else
		{
			bitstr[tmpindex] = '\0';
		}
		RETURN_STRING(bitstr);
	}
}
PHP_FUNCTION(ccbhexfen2fen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		int index = 0;
		char fen[100];
		int tmpidx = 0;
		for(int sq = 0; sq < 90; sq++)
		{
			if(sq != 0 && (sq % 9) == 0)
			{
				fen[tmpidx++] = '/';
			}
			char tmpch = '0';
			if(index < fenstr_len)
			{
				tmpch = fenstr[index++];
			}
			if(tmpch == '8')
			{
				tmpch = fenstr[index++];
				fen[tmpidx++] = tmpch + 1;
				sq += tmpch - '0';
			}
			else
			{
				fen[tmpidx++] = bithex2char(tmpch);
			}
		}
		fen[tmpidx] = '\0';

		if(index < fenstr_len && fenstr[index] != '0')
		{
			strcat(fen, " b");
		}
		else
		{
			strcat(fen, " w");
		}
		RETURN_STRING(fen);
	}
}
PHP_FUNCTION(ccbgetLRfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		char fen[100];
		char tmp[16];
		int index = 0;
		int tmpidx = 0;
		fen[0] = '\0';
		while(index < fenstr_len)
		{
			if(fenstr[index] == ' ')
			{
				tmp[tmpidx] = '\0';
				mystrrev(tmp);
				strcat(fen, tmp);
				if(fenstr[index+1] == 'w')
				{
					strcat(fen, " w");
				}
				else
				{
					strcat(fen, " b");
				}
				break;
			}
			else if(fenstr[index] == '/')
			{
				tmp[tmpidx] = '\0';
				mystrrev(tmp);
				strcat(fen, tmp);
				strcat(fen, "/");
				tmpidx = 0;
				index++;
			}
			else
			{
				tmp[tmpidx++] = fenstr[index++];
			}
		}
		RETURN_STRING(fen);
	}
	RETURN_FALSE;
}
PHP_FUNCTION(ccbgetBWfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		char fen[100];
		char tmp[100];
		int index = 0;
		int tmpidx = 0;
		fen[0] = '\0';
		while(index < fenstr_len)
		{
			if(fenstr[index] == ' ')
			{
				tmp[tmpidx] = '\0';
				strcat(tmp, "/");
				strcat(tmp, fen);
				strcpy(fen, tmp);
				if(fenstr[index+1] == 'w')
				{
					strcat(fen, " b");
				}
				else
				{
					strcat(fen, " w");
				}
				break;
			}
			else if(fenstr[index] == '/')
			{
				tmp[tmpidx] = '\0';
				if(strlen(fen) > 0)
				{
					strcat(tmp, "/");
					strcat(tmp, fen);
				}
				strcpy(fen, tmp);
				tmpidx = 0;
				index++;
			}
			else
			{
				tmp[tmpidx] = fenstr[index++];
				if(isupper(tmp[tmpidx]))
				{
					tmp[tmpidx] = tolower(tmp[tmpidx]);
				}
				else
				{
					tmp[tmpidx] = toupper(tmp[tmpidx]);
				}
				tmpidx++;
			}
		}
		RETURN_STRING(fen);
	}
}
PHP_FUNCTION(ccbgetLRBWfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		char fen[100];
		char tmp[100];
		int index = 0;
		int tmpidx = 0;
		fen[0] = '\0';
		while(index < fenstr_len)
		{
			if(fenstr[index] == ' ')
			{
				tmp[tmpidx] = '\0';
				mystrrev(tmp);
				strcat(tmp, "/");
				strcat(tmp, fen);
				strcpy(fen, tmp);
				if(fenstr[index+1] == 'w')
				{
					strcat(fen, " b");
				}
				else
				{
					strcat(fen, " w");
				}
				break;
			}
			else if(fenstr[index] == '/')
			{
				tmp[tmpidx] = '\0';
				mystrrev(tmp);
				if(strlen(fen) > 0)
				{
					strcat(tmp, "/");
					strcat(tmp, fen);
				}
				strcpy(fen, tmp);
				tmpidx = 0;
				index++;
			}
			else
			{
				tmp[tmpidx] = fenstr[index++];
				if(isupper(tmp[tmpidx]))
				{
					tmp[tmpidx] = tolower(tmp[tmpidx]);
				}
				else
				{
					tmp[tmpidx] = toupper(tmp[tmpidx]);
				}
				tmpidx++;
			}
		}
		RETURN_STRING(fen);
	}
}
const char MoveToLR[128] = {
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  'i',  'h',  'g',  'f',  'e',  'd',  'c',  'b',  'a',  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
};
const char MoveToBW[128] = {
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	'9',  '8',  '7',  '6',  '5',  '4',  '3',  '2',  '1',  '0',  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
};
const char MoveToLRBW[128] = {
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	'9',  '8',  '7',  '6',  '5',  '4',  '3',  '2',  '1',  '0',  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
	0,  'i',  'h',  'g',  'f',  'e',  'd',  'c',  'b',  'a',  0,  0,  0,  0,  0,  0,
	0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
};
PHP_FUNCTION(ccbgetLRmove)
{
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &movestr, &movestr_len) != FAILURE) {
		char move[5];
		move[4] = '\0';
		for(int i = 0; i < movestr_len; i++)
		{
			char tmp = MoveToLR[movestr[i]];
			if(tmp)
				move[i] = tmp;
			else
				move[i] = movestr[i];
		}
		RETURN_STRING(move);
	}
	RETURN_FALSE;
}
PHP_FUNCTION(ccbgetBWmove)
{
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &movestr, &movestr_len) != FAILURE) {
		char move[5];
		move[4] = '\0';
		for(int i = 0; i < movestr_len; i++)
		{
			char tmp = MoveToBW[movestr[i]];
			if(tmp)
				move[i] = tmp;
			else
				move[i] = movestr[i];
		}
		RETURN_STRING(move);
	}
	RETURN_FALSE;
}
PHP_FUNCTION(ccbgetLRBWmove)
{
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &movestr, &movestr_len) != FAILURE) {
		char move[5];
		move[4] = '\0';
		for(int i = 0; i < movestr_len; i++)
		{
			char tmp = MoveToLRBW[movestr[i]];
			if(tmp)
				move[i] = tmp;
			else
				move[i] = movestr[i];
		}
		RETURN_STRING(move);
	}
	RETURN_FALSE;
}
PHP_FUNCTION(ccbrulecheck)
{
	char* fenstr;
	size_t fenstr_len;
	zval* arr;
	zend_bool verify = 0;
	long check_times = 1;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sa|bl", &fenstr, &fenstr_len, &arr, &verify, &check_times) != FAILURE) {
		Position pos;
		pos.from_fen(fenstr);
		pos.key = pos.pos_key();
		HashTable* arr_hash = Z_ARRVAL_P(arr);
		HashPosition pointer;
		zval* data;
		for(zend_hash_internal_pointer_reset_ex(arr_hash, &pointer); data = zend_hash_get_current_data_ex(arr_hash, &pointer); zend_hash_move_forward_ex(arr_hash, &pointer)) {
			if (Z_TYPE_P(data) == IS_STRING) {
				int move = move_from_string(Z_STRVAL_P(data));
				if(!verify || (pos.move_is_pseudo(move) && (pos.in_check(pos.turn) ? pos.pseudo_is_legal_incheck(move) : pos.pseudo_is_legal(move))))
					pos.move_do(move);
				else
					RETURN_NULL();
			}
			else
				RETURN_NULL();
		}
		RETURN_LONG(pos.rep_check(check_times));
	}
	RETURN_NULL();
}
PHP_FUNCTION(ccbruleischase)
{
	char* fenstr;
	size_t fenstr_len;
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ss", &fenstr, &fenstr_len, &movestr, &movestr_len) != FAILURE) {
		Position pos;
		pos.from_fen(fenstr);
		int move = move_from_string(movestr);
		RETURN_LONG(pos.is_chase(move));
	}
	RETURN_NULL();
}
