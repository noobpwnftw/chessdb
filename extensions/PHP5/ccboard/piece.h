#ifndef _PIECE_H_
#define _PIECE_H_
#include "color.h"

#define Piece_Color(piece)  (piece & 8 ? 1 : 0)
#define Piece_Type(piece)   (piece & 7)

//#define Index_To_Type(index) (Index2Type[index])
//#define Type_To_Index(type) (Type2Index[type])

#define Type_Number(type)           (TypeNb[type])
#define Piece_Is_Pawn(piece)        (!(piece ^ 8 ^ Pawn))
#define Piece_Is_Cannon(piece)      (!(piece ^ 8 ^ Cannon))
#define Piece_Is_Knight(piece)      (!(piece ^ 8 ^ Knight))
#define Piece_Is_Rook(piece)        (!(piece ^ 8 ^ Rook))
#define Piece_Is_Bishop(piece)      (!(piece ^ 8 ^ Bishop))
#define Piece_Is_Advisor(piece)     (!(piece ^ 8 ^ Advisor))
#define Piece_Is_King(piece)        (!(piece ^ 8 ^ King))
#define Piece_Is_Ok(piece)          (piece && (piece ^ 8) && !((piece | 15) ^ 15))
#define Index_Same_Color(ix1, ix2)  (IndexColor[ix1] == IndexColor[ix2])
#define Mvv_Lva(src, cap)           (IndexOrder[(cap)] * 5 - IndexOrder[(src)] + 4)

const sint32        Rook=1;
const sint32        Knight=2;
const sint32        Cannon=3;
const sint32        Pawn=4;
const sint32        Advisor=5;
const sint32        Bishop=6;
const sint32        King=7;

const sint32        RedRook=1;
const sint32        RedKnight=2;
const sint32        RedCannon=3;
const sint32        RedPawn=4;
const sint32        RedAdvisor=5;
const sint32        RedBishop=6;
const sint32        RedKing=7;

const sint32        BlackRook=9;
const sint32        BlackKnight=10;
const sint32        BlackCannon=11;
const sint32        BlackPawn=12;
const sint32        BlackAdvisor=13;
const sint32        BlackBishop=14;
const sint32        BlackKing=15;

const sint32        PieceNb=16;
const sint32        PieceNone=0;
extern const sint32 IndexOrder[33];
extern const sint8  PieceStr[34];
extern const sint32 IndexColor[33];
extern const sint32 Index2Type[33];
extern const sint32 Type2Index[16];
extern const sint32 TypeNb[16];
extern const sint32 RedPawnInc[3 + 1];
extern const sint32 BlackPawnInc[3 + 1];
extern const sint32 KnightInc[8 + 1];
extern const sint32 BishopInc[4 + 1];
extern const sint32 RookInc[4 + 1];
extern const sint32 CannonInc[4 + 1];
extern const sint32 AdvisorInc[4 + 1];
extern const sint32 KingInc[4 + 1];
#endif
