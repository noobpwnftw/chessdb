#ifndef _SQUARE_H_
#define _SQUARE_H_
#include "color.h"

//#define Square_Side(square)         ((square & 0x80) ? TurnRed : TurnBlack)
#define Square_Make(file, rank)     (((rank) << 4) | (file))
#define Square_File(square)         ((square) & 0xF)
#define Square_Rank(square)         ((square) >> 4)
#define Square_From_90(square)      (SquareFrom90[square])
#define Square_To_90(square)        (SquareTo90[square])
#define Equal_Rank(sq1, sq2)        ((sq1 & 0xf0) == (sq2 & 0xf0))
#define Equal_File(sq1, sq2)        ((sq1 & 0x0f) == (sq2 & 0x0f))
#define In_City(sq)                 (CityHist[sq] & 3)
#define King_In_City(sq)            (CityHist[sq] & 1)
#define Advisor_In_City(sq)         (CityHist[sq] & 2)
#define Bishop_In_City(sq)          (CityHist[sq] & 4)
#define Red_Pawn_Valid(sq)          (PawnHist[0][sq])
#define Black_Pawn_Valid(sq)          (PawnHist[1][sq])

const sint32        FileA=0x3;
const sint32        Rank0=0x3;
const sint32        SquareNone=0;
const sint32        SquareNb=256;
const sint32        A0=0x33, B0=0x34, C0=0x35, D0=0x36, E0=0x37, F0=0x38, G0=0x39, H0=0x3A, I0=0x3B;
const sint32        A1=0x43, B1=0x44, C1=0x45, D1=0x46, E1=0x47, F1=0x48, G1=0x49, H1=0x4A, I1=0x4B;
const sint32        A2=0x53, B2=0x54, C2=0x55, D2=0x56, E2=0x57, F2=0x58, G2=0x59, H2=0x5A, I2=0x5B;
const sint32        A3=0x63, B3=0x64, C3=0x65, D3=0x66, E3=0x67, F3=0x68, G3=0x69, H3=0x6A, I3=0x6B;
const sint32        A4=0x73, B4=0x74, C4=0x75, D4=0x76, E4=0x77, F4=0x78, G4=0x79, H4=0x7A, I4=0x7B;
const sint32        A5=0x83, B5=0x84, C5=0x85, D5=0x86, E5=0x87, F5=0x88, G5=0x89, H5=0x8A, I5=0x8B;
const sint32        A6=0x93, B6=0x94, C6=0x95, D6=0x96, E6=0x97, F6=0x98, G6=0x99, H6=0x9A, I6=0x9B;
const sint32        A7=0xA3, B7=0xA4, C7=0xA5, D7=0xA6, E7=0xA7, F7=0xA8, G7=0xA9, H7=0xAA, I7=0xAB;
const sint32        A8=0xB3, B8=0xB4, C8=0xB5, D8=0xB6, E8=0xB7, F8=0xB8, G8=0xB9, H8=0xBA, I8=0xBB;
const sint32        A9=0xC3, B9=0xC4, C9=0xC5, D9=0xC6, E9=0xC7, F9=0xC8, G9=0xC9, H9=0xCA, I9=0xCB;

extern const sint32 SquareFrom90[90];
extern const sint32 SquareTo90[SquareNb];
extern const sint32 CityHist[256];
extern const sint32 PawnHist[2][256];
#endif
