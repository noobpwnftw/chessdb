#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

extern "C" {
#include "php.h"
}
#include "php_cboard.h"

#include <cctype>
#include <string>
#include <stdlib.h>
#include <vector>

extern "C" {
#include "fen.h"
#include "position.h"
#include "generate.h"
#include "print.h"
#include "parse.h"
}

zend_function_entry cboard_functions[] = {
	PHP_FE(cbgetfen, NULL)
	PHP_FE(cbmovegen, NULL)
	PHP_FE(cbmovemake, NULL)
	PHP_FE(cbmovesan, NULL)

	PHP_FE(cbgetBWfen, NULL)
	PHP_FE(cbgetBWmove, NULL)

	PHP_FE(cbincheck, NULL)

	PHP_FE(cbfen2hexfen, NULL)
	PHP_FE(cbhexfen2fen, NULL)

	{NULL, NULL, NULL}
};

zend_module_entry cboard_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
	STANDARD_MODULE_HEADER,
#endif
	"cboard",
	cboard_functions,
	PHP_MINIT(cboard),
	NULL,
	NULL,
	NULL,
	NULL,
#if ZEND_MODULE_API_NO >= 20010901
	"0.1",
#endif
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CBOARD
extern "C" {
ZEND_GET_MODULE(cboard)
}
#endif

PHP_MINIT_FUNCTION(cboard)
{
	chess_generate_init();
	return SUCCESS;
}
PHP_FUNCTION(cbgetfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		ChessPosition cp;
		if(chess_fen_load(fenstr, &cp))
		{
			char fen[CHESS_FEN_MAX_LENGTH];
			chess_fen_save(&cp, fen);
			RETURN_STRING(fen);
		}
	}
	RETURN_NULL();
}
PHP_FUNCTION(cbmovegen)
{
	char* fenstr;
	size_t fenstr_len;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		ChessPosition cp;
		if(chess_fen_load(fenstr, &cp)) {
			ChessMoveGenerator generator;
			ChessMove move;
			chess_move_generator_init(&generator, &cp);
			while ((move = chess_move_generator_next(&generator)))
			{
				char movestr[6];
				chess_print_move(move, movestr);
				add_assoc_long(return_value, movestr, 0);
			}
		}
	}
}
PHP_FUNCTION(cbincheck)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		ChessPosition cp;
		if(chess_fen_load(fenstr, &cp)) {
			RETURN_BOOL(chess_position_is_check(&cp));
		}
	}
	RETURN_NULL();
}
PHP_FUNCTION(cbmovemake)
{
	char* fenstr;
	size_t fenstr_len;
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "ss", &fenstr, &fenstr_len, &movestr, &movestr_len) != FAILURE) {
		ChessPosition cp;
		ChessMove move;
		if(chess_fen_load(fenstr, &cp) && chess_parse_move(movestr, &cp, &move) == CHESS_PARSE_MOVE_OK) {
			char fen[CHESS_FEN_MAX_LENGTH];
			chess_position_make_move(&cp, move);
			chess_fen_save(&cp, fen);
			RETURN_STRING(fen);
		}
	}
	RETURN_NULL();
}
PHP_FUNCTION(cbmovesan)
{
	char* fenstr;
	size_t fenstr_len;
	zval* arr;
	array_init(return_value);
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "sa", &fenstr, &fenstr_len, &arr) != FAILURE) {
		ChessPosition cp;
		if(chess_fen_load(fenstr, &cp)) {
			HashTable* arr_hash = Z_ARRVAL_P(arr);
			HashPosition pointer;
			zval* data;
			for(zend_hash_internal_pointer_reset_ex(arr_hash, &pointer); data = zend_hash_get_current_data_ex(arr_hash, &pointer); zend_hash_move_forward_ex(arr_hash, &pointer)) {
				if (Z_TYPE_P(data) == IS_STRING) {
					ChessMove move;
					if(chess_parse_move(Z_STRVAL_P(data), &cp, &move) == CHESS_PARSE_MOVE_OK) {
						char san[10];
						chess_print_move_san(move, &cp, san);
						add_next_index_string(return_value, san);
						chess_position_make_move(&cp, move);
					}
					else
						break;
				}
			}
		}
	}
}
char char2bithex(char ch)
{
	switch(ch)
	{
		case '1':
			return '0';
		case '2':
			return '1';
		case '3':
			return '2';
		case 'p':
			return '3';
		case 'n':
			return '4';
		case 'b':
			return '5';
		case 'r':
			return '6';
		case 'q':
			return '7';
			
		case 'k':
			return '9';
		case 'P':
			return 'a';
		case 'N':
			return 'b';
		case 'B':
			return 'c';
		case 'R':
			return 'd';
		case 'Q':
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
			return '2';
		case '2':
			return '3';
		case '3':
			return 'p';
		case '4':
			return 'n';
		case '5':
			return 'b';
		case '6':
			return 'r';
		case '7':
			return 'q';
			
		case '9':
			return 'k';
		case 'a':
			return 'P';
		case 'b':
			return 'N';
		case 'c':
			return 'B';
		case 'd':
			return 'R';
		case 'e':
			return 'Q';
		case 'f':
			return 'K';
	}
}
char extra2bithex(char ch)
{
	switch(ch)
	{
		case '-':
			return '0';
		case 'K':
			return 'a';
		case 'Q':
			return 'b';
		case 'k':
			return 'c';
		case 'q':
			return 'd';
		case 'a':
			return '1';
		case 'b':
			return '2';
		case 'c':
			return '3';
		case 'd':
			return '4';
		case 'e':
			return '5';
		case 'f':
			return '6';
		case 'g':
			return '7';
		case 'h':
			return '8';
		case ' ':
			return '9';
		case 'B':
		case 'C':
		case 'D':
		case 'E':
		case 'F':
		case 'G':
			return 'e';
		default:
			return ch;
	}
}
char bithex2extra(unsigned char ch)
{
	switch(ch)
	{
		case '0':
			return '-';
		case 'a':
			return 'K';
		case 'b':
			return 'Q';
		case 'c':
			return 'k';
		case 'd':
			return 'q';
		case '1':
			return 'a';
		case '2':
			return 'b';
		case '3':
			return 'c';
		case '4':
			return 'd';
		case '5':
			return 'e';
		case '6':
			return 'f';
		case '7':
			return 'g';
		case '8':
			return 'h';
		case '9':
			return ' ';
	}
}
#define TRUNC(a, b) ((a) + ((b) - (((a) % (b)) ? ((a) % (b)) : (b))))
PHP_FUNCTION(cbfen2hexfen)
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
				else
				{
					bitstr[tmpindex++] = '0';
				}
				index += 3;
				while(index < fenstr_len)
				{
					bitstr[tmpindex++] = extra2bithex(fenstr[index++]);
					if(bitstr[tmpindex - 1] == 'e')
					{
						bitstr[tmpindex++] = extra2bithex(tolower(fenstr[index - 1]));
					}
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
				if(curCh >= '4' && curCh <= '8')
				{
					bitstr[tmpindex++] = curCh - 4;
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
PHP_FUNCTION(cbhexfen2fen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		int index = 0;
		char fen[CHESS_FEN_MAX_LENGTH];
		int tmpidx = 0;
		for(int sq = 0; sq < 64; sq++)
		{
			if(sq != 0 && (sq % 8) == 0)
			{
				fen[tmpidx++] = '/';
			}
			char tmpch = '0';
			if(index < fenstr_len)
			{
				tmpch = fenstr[index++];
			}
			if(tmpch == '1')
			{
				sq += 1;
			}
			else if(tmpch == '2')
			{
				sq += 2;
			}
			if(tmpch == '8')
			{
				tmpch = fenstr[index++];
				fen[tmpidx++] = tmpch + 4;
				sq += tmpch - '0' + 3;
			}
			else
			{
				fen[tmpidx++] = bithex2char(tmpch);
			}
		}
		fen[tmpidx] = '\0';

		if(fenstr[index++] != '0')
		{
			strcat(fen, " b ");
		}
		else
		{
			strcat(fen, " w ");
		}
		tmpidx += 3;
		do
		{
			if(fenstr[index] == 'e')
			{
				index++;
				fen[tmpidx++] = toupper(bithex2extra(fenstr[index++]));
			}
			else
			{
				fen[tmpidx++] = bithex2extra(fenstr[index++]);
			}
			
		}
		while(fen[tmpidx - 1] != ' ');
		if(index < fenstr_len)
		{
			fen[tmpidx++] = bithex2extra(fenstr[index++]);
			if(fen[tmpidx - 1] != '-')
				fen[tmpidx++] = fenstr[index];
			fen[tmpidx] = '\0';
		}
		else
		{
			fen[tmpidx++] = '-';
			fen[tmpidx] = '\0';
		}
		RETURN_STRING(fen);
	}
}

const char MoveToBW[128] = {
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  '8',  '7',  '6',  '5',  '4',  '3',  '2',  '1',  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
        0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,
};
PHP_FUNCTION(cbgetBWfen)
{
	char* fenstr;
	size_t fenstr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &fenstr, &fenstr_len) != FAILURE) {
		char fen[CHESS_FEN_MAX_LENGTH];
		char tmp[CHESS_FEN_MAX_LENGTH];
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
					strcat(fen, " b ");
				}
				else
				{
					strcat(fen, " w ");
				}
				index += 3;
				tmpidx = 0;
				char tmp2[3];
				int tmpidx2 = 0;
				do
				{
					if(isupper(fenstr[index]))
						tmp2[tmpidx2++] = tolower(fenstr[index++]);
					else
						tmp[tmpidx++] = toupper(fenstr[index++]);
				}
				while(fenstr[index] != ' ');
				tmp[tmpidx] = '\0';
				tmp2[tmpidx2++] = ' ';
				tmp2[tmpidx2] = '\0';
				strcat(fen, tmp);
				strcat(fen, tmp2);
				index++;

				while(index < fenstr_len)
				{
					char tmp = MoveToBW[fenstr[index]];
					if(tmp)
						fen[index] = tmp;
					else
						fen[index] = fenstr[index];
					index++;
				}
				fen[index] = '\0';
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
PHP_FUNCTION(cbgetBWmove)
{
	char* movestr;
	size_t movestr_len;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &movestr, &movestr_len) != FAILURE) {
		char move[6];
		move[4] = '\0';
		for(int i = 0; i < movestr_len; i++)
		{
			if(i < 4)
			{
				char tmp = MoveToBW[movestr[i]];
				if(tmp)
					move[i] = tmp;
				else
					move[i] = movestr[i];
			}
			else
			{
				move[i] = movestr[i];
				move[5] = '\0';
			}
		}
		RETURN_STRING(move);
	}
	RETURN_FALSE;
}
