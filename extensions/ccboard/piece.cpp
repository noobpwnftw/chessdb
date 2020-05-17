#include "piece.h"

const sint32    RedPawnInc[3 + 1]={ -16, -1, 1, 0 };
const sint32    BlackPawnInc[3 + 1]={ 16, -1, 1, 0 };
const sint32    KnightInc[8 + 1]={ -33, -31, -18, -14, +14, +18, +31, +33, 0 };
const sint32    BishopInc[4 + 1]={ -34, -30, +30, +34, 0 };
const sint32    RookInc[4 + 1]={ -16, -1, +1, +16, 0 };
const sint32    CannonInc[4 + 1]={ -16, -1, +1, +16, 0 };
const sint32    AdvisorInc[4 + 1]={ -17, -15, +15, +17, 0 };
const sint32    KingInc[4 + 1]={ -16, -1, +1, +16, 0 };
const sint32    Index2Type[33]=
{
    PieceNone,
    RedRook,
    RedRook,
    RedKnight,
    RedKnight,
    RedCannon,
    RedCannon,
    RedPawn,
    RedPawn,
    RedPawn,
    RedPawn,
    RedPawn,
    RedAdvisor,
    RedAdvisor,
    RedBishop,
    RedBishop,
    RedKing,
    BlackRook,
    BlackRook,
    BlackKnight,
    BlackKnight,
    BlackCannon,
    BlackCannon,
    BlackPawn,
    BlackPawn,
    BlackPawn,
    BlackPawn,
    BlackPawn,
    BlackAdvisor,
    BlackAdvisor,
    BlackBishop,
    BlackBishop,
    BlackKing
};
const sint32    IndexColor[33]=
{
    TurnNone,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnRed,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack,
    TurnBlack
};
const sint8     PieceStr[34]="!RRNNCCPPPPPAABBKrrnnccpppppaabbk";

const sint32    Type2Index[16]={ 0, 1, 3, 5, 7, 12, 14, 16, 0, 17, 19, 21, 23, 28, 30, 32 };

const sint32    IndexOrder[33]=
{
    -1,
    3,
    3,
    2,
    2,
    2,
    2,
    0,
    0,
    0,
    0,
    0,
    1,
    1,
    1,
    1,
    4,
    3,
    3,
    2,
    2,
    2,
    2,
    0,
    0,
    0,
    0,
    0,
    1,
    1,
    1,
    1,
    4
};
