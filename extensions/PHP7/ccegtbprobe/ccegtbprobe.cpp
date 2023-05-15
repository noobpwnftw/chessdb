#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

extern "C" {
#include "php.h"
}
#include "php_ccegtbprobe.h"

#include <unistd.h>
#include <cctype>
#include <string>
#include <stdlib.h>
#include "xiangqi.h"
#include "piece_set.h"
#include <memory.h>
#include <stdlib.h>
#include <fcntl.h> 
#include <sys/uio.h>
#include "LZMA/LzmaLib.h"
#include <pthread.h>

#define EGTB_LOGGING
#ifdef EGTB_LOGGING
#include <syslog.h>
#endif

#define EGTB_DTC_DIR_COUNT 1
static char egtb_dtc_dir[EGTB_DTC_DIR_COUNT][256] = {
												"/data/EGTB_DTC/"
												};
#define EGTB_DTM_DIR_COUNT 1
static char egtb_dtm_dir[EGTB_DTM_DIR_COUNT][256] = {
												"/data/EGTB_DTM/",
												};

zend_function_entry ccegtbprobe_functions[] = {
	PHP_FE(ccegtbprobe, NULL)
	{NULL, NULL, NULL}
};

zend_module_entry ccegtbprobe_module_entry = {
#if ZEND_MODULE_API_NO >= 20010901
	STANDARD_MODULE_HEADER,
#endif
	"ccegtbprobe",
	ccegtbprobe_functions,
	PHP_MINIT(ccegtbprobe),
	NULL,
	NULL,
	NULL,
	NULL,
#if ZEND_MODULE_API_NO >= 20010901
	"0.1",
#endif
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CCEGTBPROBE
extern "C" {
ZEND_GET_MODULE(ccegtbprobe)
}
#endif

static bool calc_egtb_index(const char* fen, char* names, int turn, S8& mirror, uint64& pos, bool& draw);
static bool probe_egtb(const char* file, U16& score, uint64 pos, S8 side, uint64& flags);
int probe(char* pFen, int turn, bool isdtm, bool& found, uint64& flags);

inline int uncompress_lzma(char* dest, size_t* destLen, const char* src, size_t* srcLen, const char* props)
{
	return LzmaUncompress((unsigned char*)dest, destLen, (const unsigned char*)src, srcLen, (const unsigned char*)props, 5);
}

typedef struct _probe_struct
{
	pthread_t tid;
	int move;
	int turn;
	bool isdtm;
	bool found;
	bool cap;
	int score;
	int check;
	uint64 flags;
	char fenstr[120];
} probe_struct;

void* probe_func(void* arg)
{
	probe_struct* my_probe_struct = (probe_struct*)arg;
	my_probe_struct->score = probe(my_probe_struct->fenstr, my_probe_struct->turn, my_probe_struct->isdtm, my_probe_struct->found, my_probe_struct->flags);
	return NULL;
}

PHP_MINIT_FUNCTION(ccegtbprobe)
{
	piece_index_init();
	if(group_init())
		return SUCCESS;
	else
		return FAILURE;
}

PHP_FUNCTION(ccegtbprobe)
{
	char* fenstr;
	size_t fenstr_len;
	zend_bool isdtm = 0;
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s|b", &fenstr, &fenstr_len, &isdtm) != FAILURE) {
		Position pos;
		if (pos.from_fen(fenstr) && pos.is_legal())
		{
			bool found = false;
			bool dtm = (isdtm != 0);
			uint64 flags;
			int root_score = probe(fenstr, pos.turn, dtm, found, flags);
			if (found)
			{
				int root_order;
				int root_check;
				if (dtm)
				{
					if (flags)
					{
						root_check = ((((root_score >> 15) & 1) | ((root_score >> 13) & 1)) << 1) - (((root_score >> 14) & 1) | ((root_score >> 12) & 1));
					}
					else
					{
						root_check = pos.in_check(pos.turn);
					}
					root_order = (root_score >> 11) & 1;
					int score = root_score & 0x7ff;
					if (score != 0)
					{
						if(root_order != 0)
							root_score = 30000 - score;
						else
							root_score = score - 30000;
					}
				}
				else
				{
					root_order = (root_score >> 10) & 0x3f;
					if (flags)
					{
						if((root_score & 512))
							root_order += 64;
						root_score = root_score & 0x1ff;
					}
					else
					{
						root_score = root_score & 0x3ff;
					}
					if (root_score != 0)
					{
						if (root_order > 0)
						{
							if((root_score & 1) == 0)
								root_score = 20000 - root_score;
							else
								root_score = root_score - 20000;
						}
						else
						{
							if ((root_score & 1) == 0)
								root_score = 30000 - root_score;
							else
								root_score = root_score - 30000;
						}
					}
				}
				Move_List list;
				pos.gen_legals(list);
				probe_struct* pprobe_structs[128];
				int probe_idx = 0;
				for (int i = 0; i < list.size(); i++)
				{
					pos.move_do(list.move(i));
					pprobe_structs[probe_idx] = (probe_struct*)malloc(sizeof(probe_struct));
					pprobe_structs[probe_idx]->found = false;
					pprobe_structs[probe_idx]->turn = pos.turn;
					pprobe_structs[probe_idx]->isdtm = dtm;
					pos.to_fen(pprobe_structs[probe_idx]->fenstr);
					pprobe_structs[probe_idx]->move = list.move(i);
					if (pos.stack[0].cap != 0)
						pprobe_structs[probe_idx]->cap = true;
					else
						pprobe_structs[probe_idx]->cap = false;
					pprobe_structs[probe_idx]->check = pos.stack[0].check;
					pthread_create(&pprobe_structs[probe_idx]->tid, NULL, probe_func, (void*)pprobe_structs[probe_idx]);
					probe_idx++;
					pos.move_undo();
				}
				bool bSuccess = true;
				for (int i = 0; i < probe_idx; i++)
				{
					pthread_join(pprobe_structs[i]->tid, NULL);
					if (!pprobe_structs[i]->found)
					{
						bSuccess = false;
#ifdef EGTB_LOGGING
						syslog(LOG_ERR|LOG_USER, "Error probing EGTB: %s", pprobe_structs[i]->fenstr);
#endif
					}
				}
				if (bSuccess)
				{
					array_init(return_value);
					for (int i = 0; i < probe_idx; i++)
					{
						int order = 0;
						int step = 0;
						int score = 0;
						if (dtm)
						{
							if (pprobe_structs[i]->flags)
							{
								pprobe_structs[i]->check = ((((pprobe_structs[i]->score >> 15) & 1) | ((pprobe_structs[i]->score >> 13) & 1)) << 1) - (((pprobe_structs[i]->score >> 14) & 1) | ((pprobe_structs[i]->score >> 12) & 1));
							}
							order = (pprobe_structs[i]->score >> 11) & 1;
							step = pprobe_structs[i]->score & 0x7ff;
							if (step != 0)
							{
								if (order != 0)
									score = step - 30000;
								else {
									score = 30000 - step;
								}
							}
						}
						else
						{
							order = (pprobe_structs[i]->score >> 10) & 0x3f;
							if (pprobe_structs[i]->flags)
							{
								if ((pprobe_structs[i]->score & 512))
									order += 64;
								step = pprobe_structs[i]->score & 0x1ff;
							}
							else
							{
								step = pprobe_structs[i]->score & 0x3ff;
							}
							if (step != 0)
							{
								if (order > 0)
								{
									if((step & 1) == 0)
										score = step - 20000;
									else
										score = 20000 - step;
								}
								else
								{
									if((step & 1) == 0)
										score = step - 30000;
									else
										score = 30000 - step;
								}
							}
						}
						char movestr[5];
						move_to_string(pprobe_structs[i]->move, movestr);
						zval moveinfo;
						array_init(&moveinfo);
						add_assoc_long(&moveinfo, "score", score);
						if(!dtm)
							add_assoc_long(&moveinfo, "order", order);
						add_assoc_bool(&moveinfo, "cap", pprobe_structs[i]->cap);
						add_assoc_long(&moveinfo, "check", pprobe_structs[i]->check);
						add_assoc_long(&moveinfo, "step", step);
						add_assoc_zval(return_value, movestr, &moveinfo);
						free(pprobe_structs[i]);
					}
					if (!dtm)
					{
						add_assoc_long(return_value, "score", root_score);
						add_assoc_long(return_value, "order", root_order);
					}
					else
					{
						add_assoc_long(return_value, "score", root_score);
						add_assoc_long(return_value, "check", root_check);
					}
				}
				else
				{
					for (int i = 0; i < probe_idx; i++)
					{
						free(pprobe_structs[i]);
					}
					RETURN_NULL();
				}
			}
			else
			{
				RETURN_NULL();
			}
		}
		else
		{
			RETURN_NULL();
		}
	}
	else
	{
		RETURN_NULL();
	}
}

int probe(char* pFen, int turn, bool isdtm, bool& found, uint64& flags)
{
	char egtb_file[256];
	char names[100];
	S8 mirror;
	U16 score = 0;
	uint64 pos = 0ULL;
	bool draw = false;
	if (calc_egtb_index(pFen, names, turn, mirror, pos, draw))
	{
		if (draw)
		{
			found = true;
			return score;
		}
		if (isdtm)
		{
			for (int i = 0; i < EGTB_DTM_DIR_COUNT; i++)
			{
				S8 side;
				sprintf(egtb_file, "%s%s.lzdtm", egtb_dtm_dir[i], names);
				if (turn == White)
				{
					if (!mirror)
						side = White;
					else
						side = Black;
				}
				else
				{
					if (!mirror)
						side = Black;
					else
						side = White;
				}
				if(probe_egtb(egtb_file, score, pos, side, flags))
				{
					found = true;
					return score;
				}
			}
		}
		else
		{
			for (int i = 0; i < EGTB_DTC_DIR_COUNT; i++)
			{
				S8 side;
				sprintf(egtb_file, "%s%s.lzdtc", egtb_dtc_dir[i], names);
				if (turn == White)
				{
					if (!mirror)
						side = White;
					else
						side = Black;
				}
				else
				{
					if (!mirror)
						side = Black;
					else
						side = White;
				}
				if (probe_egtb(egtb_file, score, pos, side, flags))
				{
					found = true;
					return score;
				}
			}
		}
	}
	return 0;
}

static bool calc_egtb_index(const char* fen, char* names, int turn, S8& mirror, uint64& pos, bool& draw)
{
	S8 piece, color;
	S8 number[16] = {0};
	S8 piece_list[16][8] = {0};

	int w_score = 0, b_score = 0;
	S8 piece_cnt = 0;
	S8 sq = 89;

	for (int i = 0; fen[i] && sq >= 0; ++i)
	{
		if (isdigit(fen[i]))
		{
			sq -= fen[i] - '0';
		}
		else
		{
			if ((piece = piece_from_char(fen[i])) != 0)
			{
				color = piece_color(piece);
				piece_list[piece][number[piece]] = sq;
				number[piece] ++;
				piece_cnt++;
				if (piece_color(piece) == White)
				{
					w_score += Piece_Order_Value[piece];
				}
				else
				{
					b_score += Piece_Order_Value[piece];
				}
				sq --;
			}
		}
	}

	if (number[WhitePawn] >= 4 || number[BlackPawn] >= 4)
	{
		return false;
	}
	if (number[WhitePawn] + number[BlackPawn] + number[WhiteRook] + number[BlackRook] + number[WhiteKnight] + number[BlackKnight] + number[WhiteCannon] + number[BlackCannon] == 0)
	{
		draw = true;
		return true;
	}
	bool mirr = (b_score > w_score);

	if (b_score == w_score && turn == Black)
	{
		mirr = true;
	}

	mirror = mirr;

	S8 set_id[10] = {0};
	S8 square_list[10][16] = {0};
	S8 ix = 0, id = 0;
	S8 nam_ix = 0;
	if (mirr)
	{
		for (int i = 0; i < 2; ++i)
		{
			ix = 0;
			color = color_opp(i);
			id = WSet_Defend + 5*i;
			set_id[id] = defend_set(number[piece_make(color, Advisor)], number[piece_make(color, Bishop)], i);
			piece = piece_make(color, King);
			square_list[id][ix++] = sq_rank_mirror(piece_list[piece][0]);
			names[nam_ix++] = piece_to_char(King);

			piece = piece_make(color, Advisor);
			for (int j = 0; j < number[piece]; ++j)
			{
				square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
				names[nam_ix++] = piece_to_char(Advisor);
			}
			piece = piece_make(color, Bishop);
			for (int j = 0; j < number[piece]; ++j)
			{
				square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
				names[nam_ix++] = piece_to_char(Bishop);
			}
			piece = piece_make(color, Rook);
			if (number[piece])
			{
				ix = 0;
				id = WSet_Rook + 5*i;
				set_id[id] = Rook_Set_ID[i][number[piece]];
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
					names[nam_ix++] = piece_to_char(Rook);
				}
			}
			piece++;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Knight + 5*i;
				set_id[id] = Knight_Set_ID[i][number[piece]];
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
					names[nam_ix++] = piece_to_char(Knight);
				}
			}
			piece++;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Cannon + 5*i;
				set_id[id] = Cannon_Set_ID[i][number[piece]];
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
					names[nam_ix++] = piece_to_char(Cannon);
				}
			}
			piece += 3;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Pawn + 5*i;
				set_id[id] = Pawn_Set_ID[i][number[piece]];
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = sq_rank_mirror(piece_list[piece][j]);
					names[nam_ix++] = piece_to_char(Pawn);
				}
			}
		}
	}
	else
	{
		for (int i = 0; i < 2; ++i)
		{
			ix = 0;
			id = WSet_Defend + 5*i;
			set_id[id] = defend_set(number[piece_make(i, Advisor)], number[piece_make(i, Bishop)], i);
			piece = piece_make(i, King);
			square_list[id][ix++] = piece_list[piece][0];
			names[nam_ix++] = piece_to_char(King);

			piece = piece_make(i, Advisor);
			for (int j = 0; j < number[piece]; ++j)
			{
				square_list[id][ix++] = piece_list[piece][j];
				names[nam_ix++] = piece_to_char(Advisor);
			}
			piece = piece_make(i, Bishop);
			for (int j = 0; j < number[piece]; ++j)
			{
				square_list[id][ix++] = piece_list[piece][j];
				names[nam_ix++] = piece_to_char(Bishop);
			}
			piece = piece_make(i, Rook);
			if (number[piece])
			{
				ix = 0;
				id = WSet_Rook + 5*i;
				set_id[id] = piece_set(piece, number[piece]);
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = piece_list[piece][j];
					names[nam_ix++] = piece_to_char(Rook);
				}
			}
			piece++;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Knight + 5*i;
				set_id[id] = piece_set(piece, number[piece]);
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = piece_list[piece][j];
					names[nam_ix++] = piece_to_char(Knight);
				}
			}
			piece++;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Cannon + 5*i;
				set_id[id] = piece_set(piece, number[piece]);
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = piece_list[piece][j];
					names[nam_ix++] = piece_to_char(Cannon);
				}
			}
			piece += 3;
			if (number[piece])
			{
				ix = 0;
				id = WSet_Pawn + 5*i;
				set_id[id] = piece_set(piece, number[piece]);
				for (int j = 0; j < number[piece]; ++j)
				{
					square_list[id][ix++] = piece_list[piece][j];
					names[nam_ix++] = piece_to_char(Pawn);
				}
			}
		}
	}
	names[nam_ix] = 0;

	int compress_id = 0;
	const Group_Info * info;
	float ratio, best_ratio = 100.0;
	for (int i = 0; i < 10; ++i)
	{
		if (set_id[i] == 0)
			continue;
		info = get_set_info(set_id[i]);
		ratio = (float)info->compress_size / info->table_size;
		if (ratio < best_ratio)
		{
			best_ratio = ratio;
			compress_id = i;
		}
	}

	info = get_set_info(set_id[compress_id]);
	uint64 weight = 1;
	uint32 compress_ix = info->get_list_pos(square_list[compress_id]);
	if ((compress_ix & 0xffff) < info->compress_size)
	{
		for (int i = 0; i < 10; ++i)
		{
			if (set_id[i] == 0)
				continue;
			info = get_set_info(set_id[i]);
			if (i == compress_id){
				pos += (compress_ix & 0xffff) * weight;
				weight *= info->compress_size;
			}
			else{
				pos += (info->get_list_pos(square_list[i]) & 0xffff) * weight;
				weight *= info->table_size;
			}
		}
	}
	else
	{
		for (int i = 0; i < 10; ++i)
		{
			if (set_id[i] == 0)
				continue;
			info = get_set_info(set_id[i]);
			if (i == compress_id){
				pos += ((compress_ix>>16) & 0xffff) * weight;
				weight *= info->compress_size;
			}
			else{
				pos += ((info->get_list_pos(square_list[i])>>16) & 0xffff) * weight;
				weight *= info->table_size;
			}
		}
	}
	return true;
}
static bool probe_egtb(const char* file_name, U16& score, uint64 pos, S8 side, uint64& flags)
{
	int fd = open(file_name, O_RDONLY);
	if (fd == -1)
		return false;

	iovec iov;
	uint64 data_offset = 0;
	uint32 magic;
	uint32 key;
	uint8 table_num;
	char ctrlbuf[sizeof(uint32) * 2];
	iov.iov_base = (void*)ctrlbuf;
	iov.iov_len = sizeof(ctrlbuf);
	if (readv(fd, &iov, 1) != sizeof(ctrlbuf))
	{
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error reading EGTB ctrl header: %s", file_name);
#endif
		return false;
	}
	magic = ((uint32*)ctrlbuf)[0];
	key = ((uint32*)ctrlbuf)[1];
	table_num = key & 3;
	if (side == Black && table_num != 2)
	{
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "EGTB table side not exist: %s", file_name);
#endif
		return false;
	}
	data_offset += sizeof(uint32) * 2;
	bool is_singular[2];
	int single_val[2];
	uint32 tail_size[2];
	uint32 block_size[2];
	uint32 block_cnt[2];
	uint64 data_size[2];
	uint64 data_start[2];
	for (int i = 0; i < table_num; ++i)
	{
		char headbuf[sizeof(uint8) * 2];
		iov.iov_base = (void*)headbuf;
		iov.iov_len = sizeof(headbuf);
		if (readv(fd, &iov, 1) != sizeof(headbuf))
		{
			close(fd);
#ifdef EGTB_LOGGING
			syslog(LOG_ERR|LOG_USER, "Error reading EGTB table header: %s", file_name);
#endif
			return false;
		}
		if (headbuf[0] & 0x80)
		{
			is_singular[i] = true;
			single_val[i] = headbuf[1];
			data_offset += sizeof(uint8) * 2;
		}
		else
		{
			is_singular[i] = false;
			single_val[i] = headbuf[1];
			data_offset += sizeof(uint8) * 2;
			char offsetbuf[sizeof(uint32) * 3 + sizeof(uint64)];
			iov.iov_base = (void*)offsetbuf;
			iov.iov_len = sizeof(offsetbuf);
			if (readv(fd, &iov, 1) != sizeof(offsetbuf))
			{
				close(fd);
#ifdef EGTB_LOGGING
				syslog(LOG_ERR|LOG_USER, "Error reading EGTB offset header: %s", file_name);
#endif
				return false;
			}
			tail_size[i] = ((uint32*)offsetbuf)[0];
			block_size[i] = ((uint32*)offsetbuf)[1];
			block_cnt[i] = ((uint32*)offsetbuf)[2];
			data_size[i] = *(uint64*)(offsetbuf + sizeof(uint32) * 3);
			data_offset += sizeof(uint32) * 3 + sizeof(uint64) + block_cnt[i] * sizeof(UINT64);
		}
	}
	if (is_singular[side])
	{
		close(fd);
		score = single_val[side];
		flags = 0;
		return true;
	}
	for (int i = 0; i < table_num; ++i)
	{
		if (is_singular[i])
		{
			continue;
		}
		data_offset = (data_offset + 0x3F) & ~0x3F;
		data_start[i] = data_offset;
		data_offset += data_size[i];
	}
	flags = single_val[side];
	uint64 i = (pos * 2) / uint64(block_size[side]);
	if (lseek64(fd, i * sizeof(UINT64) + (side == Black ? (is_singular[White] ? 0 : block_cnt[White] * sizeof(UINT64)) : 0), SEEK_CUR) == -1)
	{
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error seeking EGTB block header: %s", file_name);
#endif
		return false;
	}
	char databuf[sizeof(UINT64)];
	iov.iov_base = (void*)databuf;
	iov.iov_len = sizeof(databuf);
	if (readv(fd, &iov, 1) != sizeof(databuf))
	{
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error reading EGTB block header: %s", file_name);
#endif
		return false;
	}
	size_t size = int(((UINT64*)databuf)[0] & 0xfffff);
	uint64 offset = (((UINT64*)databuf)[0] >> 20);
	if (lseek64(fd, data_start[side] + offset, SEEK_SET) == -1)
	{
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error seeking EGTB block: %s", file_name);
#endif
		return false;
	}
	char* lp_src = (char*)malloc(size);
	iov.iov_base = (void*)lp_src;
	iov.iov_len = size;
	if (readv(fd, &iov, 1) != size)
	{
		free(lp_src);
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error reading EGTB block: %s", file_name);
#endif
		return false;
	}

	char* lp_dst = (char*)malloc(block_size[side]);
	size_t decode_size = (i == block_cnt[side] - 1) ? tail_size[side] : block_size[side];
	size -= 5;
	int ret = uncompress_lzma(lp_dst, &decode_size, lp_src, &size, (lp_src+size));
	if (ret != SZ_OK)
	{
		free(lp_dst);
		free(lp_src);
		close(fd);
#ifdef EGTB_LOGGING
		syslog(LOG_ERR|LOG_USER, "Error uncompressing EGTB block: %s", file_name);
#endif
		return false;
	}
	U16* entry = (U16*)lp_dst;
	int new_index = int(pos - uint64(block_size[side]) / 2 * i);
	score = entry[new_index];
	free(lp_dst);
	free(lp_src);
	close(fd);
	return true;
}
