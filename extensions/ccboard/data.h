#ifndef _DATA_H_
#define _DATA_H_
#include "type.h"

extern const sint32 NLegalDt[512];
extern sint32       KLegalDt[512];
extern sint32       ALegalDt[512];
extern sint32       BLegalDt[512];

extern sint32       RankNonCapMax[9][512];
extern sint32       RankNonCapMin[9][512];
extern sint32       FileNonCapMax[10][1024];
extern sint32       FileNonCapMin[10][1024];

extern sint32       RookRankCapMax[9][512];
extern sint32       RookRankCapMin[9][512];
extern sint32       RookFileCapMax[10][1024];
extern sint32       RookFileCapMin[10][1024];

extern sint32       CannonRankCapMax[9][512];
extern sint32       CannonRankCapMin[9][512];
extern sint32       CannonFileCapMax[10][1024];
extern sint32       CannonFileCapMin[10][1024];

extern sint32       KingMoves[256][8];
extern sint32       AdvisorMoves[256][8];
extern sint32       BishopMoves[256][8];
extern sint32       ElephantEyes[256][4];
extern sint32       KnightMoves[256][12];
extern sint32       HorseLegs[256][8];
extern sint32       FileNonCapMoves[10][1024][12];
extern sint32       RankNonCapMoves[9][512][12];
extern sint32       RookFileCapMoves[10][1024][4];
extern sint32       RookRankCapMoves[9][512][4];
extern sint32       CannonFileCapMoves[10][1024][4];
extern sint32       CannonRankCapMoves[9][512][4];
extern sint32       PawnMoves[2][256][4];

extern void         InitData(void);
#endif
