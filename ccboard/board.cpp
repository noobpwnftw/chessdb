#include <memory.h>
#include <string.h>
#include "board.h"
#include "piece.h"
#include "data.h"
#include "move.h"

#define Is_White_Side(sq)           ((sq) > I4)
#define Is_Black_Side(sq)           ((sq) < A5)

/* */
void Board::clear(void)
{
    int i;
    for(i=0; i < SquareNb; ++i)
    {
        if(Square_To_90(i) < 0)
            square[i]= -1;
        else
            square[i]=0;
    }

    for(i=0; i < 33; ++i)
    {
        piece[i]=SquareNone;
    }

    for(i=0; i < 16; ++i)
    {
        number[i]=0;
    };
    turn=TurnNone;
    for(i=0; i < 9; ++i)
    {
        rank[i]=file[i]=0;
    }

    rank[9]=0;
}

/* */
bool Board::init(void)
{
    return init("rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w");
}

/* */
int Board::char2type(char a)
{
    switch(a)
    {
        case 'K':
            return RedKing;
        case 'A':
            return RedAdvisor;
        case 'B':
        case 'E':
            return RedBishop;
        case 'N':
        case 'H':
            return RedKnight;
        case 'R':
            return RedRook;
        case 'C':
            return RedCannon;
        case 'P':
            return RedPawn;
        case 'k':
            return BlackKing;
        case 'a':
            return BlackAdvisor;
        case 'b':
        case 'e':
            return BlackBishop;
        case 'n':
        case 'h':
            return BlackKnight;
        case 'r':
            return BlackRook;
        case 'c':
            return BlackCannon;
        case 'p':
            return BlackPawn;
    }

    return 0;
}

/* */
bool Board::init(const char *fen)
{
    clear();

    int len=strlen(fen);
    int i, type, row, col, sq=0, sq256;
    for(i=0; i < len && sq < 90; ++i)
    {
        if(fen[i] >= '0' && fen[i] <= '9')
            sq+=fen[i] - '0';
        else if((fen[i] >= 'a' && fen[i] <= 'z') || (fen[i] >= 'A' && fen[i] <= 'Z'))
        {
            if((type=char2type(fen[i])) != 0)
            {
				sq256 = Square_From_90(sq);
				switch(type)
				{
				case RedKing:
					if (!King_In_City(sq256) || !Is_White_Side(sq256) || number[type] > 0)
					{
						return false;
					}
					break;
				case RedAdvisor:
					if (!Advisor_In_City(sq256) || !Is_White_Side(sq256) || number[type] > 1)
					{
						return false;
					}
					break;
				case RedBishop:
					if (!Bishop_In_City(sq256) || !Is_White_Side(sq256) || number[type] > 1)
					{
						return false;
					}
					break;
				case BlackKing:
					if (!King_In_City(sq256) || !Is_Black_Side(sq256) || number[type] > 0)
					{
						return false;
					}
					break;
				case BlackAdvisor:
					if (!Advisor_In_City(sq256) || !Is_Black_Side(sq256) || number[type] > 1)
					{
						return false;
					}
					break;
				case BlackBishop:
					if (!Bishop_In_City(sq256) || !Is_Black_Side(sq256) || number[type] > 1)
					{
						return false;
					}
					break;
				case RedPawn:
					if(!Red_Pawn_Valid(sq256) || number[type] > 4)
					{
						return false;
					}
					break;
				case BlackPawn:
					if(!Black_Pawn_Valid(sq256) || number[type] > 4)
					{
						return false;
					}
					break;
				case RedCannon:
				case BlackCannon:
				case RedKnight:
				case BlackKnight:
				case RedRook:
				case BlackRook:
					if(number[type] > 1)
					{
						return false;
					}
					break;
				}
                piece[Type2Index[type] + number[type]]= sq256;
                square[sq256]=Type2Index[type] + number[type];
                number[type]++;
                col=Square_File(sq256) - 3;
                row=Square_Rank(sq256) - 3;
                file[col]^=1 << row;
                rank[row]^=1 << col;
            }

            sq++;
        }
    }

    if(sq == 90)
    {
        for(; i < len; i++)
        {
            if(fen[i] == 'W' || fen[i] == 'w' || fen[i] == 'R' || fen[i] == 'r')
            {
                turn=TurnRed;
                break;
            }
            else if(fen[i] == 'B' || fen[i] == 'b')
            {
                turn=TurnBlack;
                break;
            }
        }
        if(turn == TurnNone)
            return false;
        if(number[BlackKing] == 0 || number[RedKing] == 0)
            return false;
        int king_sq1=piece[Type2Index[BlackKing]];
        int king_sq2=piece[Type2Index[RedKing]];
        if(Equal_File(king_sq1, king_sq2))
        {
            int col=Square_File(king_sq1) - 3;
            int row=Square_Rank(king_sq1) - 3;
            int colbit=file[col];
            if(RookFileCapMin[row][colbit] + col == king_sq2 || RookFileCapMax[row][colbit] + col == king_sq2) return false;
        }
        return true;
    }
    return false;
}


void Board::gen(List *list)
{
    int i, index, src, dst, row, col, filebit, rankbit;
    list->clear();
    for(i=(turn ? 17 : 1); i < (turn ? 33 : 17); ++i)
    {
        if(piece[i] == SquareNone) continue;
        src=piece[i];
        switch(Index2Type[i])
        {
            case RedRook:
            case BlackRook:
                row=Square_Rank(src) - 3;
                col=Square_File(src) - 3;
                filebit=file[col];
                rankbit=rank[row];
                index=0;
                while(RookFileCapMoves[row][filebit][index])
                {
                    dst=RookFileCapMoves[row][filebit][index] + col;
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                index=0;
                while(RookRankCapMoves[col][rankbit][index])
                {
                    dst=RookRankCapMoves[col][rankbit][index] + (row << 4);
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                index=0;
                while(FileNonCapMoves[row][filebit][index])
                {
                    dst=FileNonCapMoves[row][filebit][index] + col;
                    list->move[list->size].wmv.src=src;
                    list->move[list->size].wmv.dst=dst;
                    list->size++;
                    ++index;
                }

                index=0;
                while(RankNonCapMoves[col][rankbit][index])
                {
                    dst=RankNonCapMoves[col][rankbit][index] + (row << 4);
                    list->move[list->size].wmv.src=src;
                    list->move[list->size].wmv.dst=dst;
                    list->size++;
                    ++index;
                }
                break;
            case RedCannon:
            case BlackCannon:
                row=Square_Rank(src) - 3;
                col=Square_File(src) - 3;
                filebit=file[col];
                rankbit=rank[row];
                index=0;
                while(CannonFileCapMoves[row][filebit][index])
                {
                    dst=CannonFileCapMoves[row][filebit][index] + col;
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                index=0;
                while(CannonRankCapMoves[col][rankbit][index])
                {
                    dst=CannonRankCapMoves[col][rankbit][index] + (row << 4);
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                index=0;
                while(FileNonCapMoves[row][filebit][index])
                {
                    dst=FileNonCapMoves[row][filebit][index] + col;
                    list->move[list->size].wmv.src=src;
                    list->move[list->size].wmv.dst=dst;
                    list->size++;
                    ++index;
                }

                index=0;
                while(RankNonCapMoves[col][rankbit][index])
                {
                    dst=RankNonCapMoves[col][rankbit][index] + (row << 4);
                    list->move[list->size].wmv.src=src;
                    list->move[list->size].wmv.dst=dst;
                    list->size++;
                    ++index;
                }
                break;
            case RedKnight:
            case BlackKnight:
                index=0;
                while(KnightMoves[src][index])
                {
                    if(square[HorseLegs[src][index]] == PieceNone)
                    {
                        dst=KnightMoves[src][index];
                        if(!Index_Same_Color(square[src], square[dst]))
                        {
                            list->move[list->size].wmv.src=src;
                            list->move[list->size].wmv.dst=dst;
                            list->size++;
                        }
                    }

                    ++index;
                }
                break;
            case RedPawn:
                index=0;
                while(PawnMoves[TurnRed][src][index])
                {
                    dst=PawnMoves[TurnRed][src][index];
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }
                break;
            case BlackPawn:
                index=0;
                while(PawnMoves[TurnBlack][src][index])
                {
                    dst=PawnMoves[TurnBlack][src][index];
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }
                break;
            case RedAdvisor:
            case BlackAdvisor:
                index=0;
                while(AdvisorMoves[src][index])
                {
                    dst=AdvisorMoves[src][index];
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }
                break;
            case RedBishop:
            case BlackBishop:
                index=0;
                while(BishopMoves[src][index])
                {
                    if(square[ElephantEyes[src][index]] == PieceNone)
                    {
                        dst=BishopMoves[src][index];
                        if(!Index_Same_Color(square[src], square[dst]))
                        {
                            list->move[list->size].wmv.src=src;
                            list->move[list->size].wmv.dst=dst;
                            list->size++;
                        }
                    }

                    ++index;
                }
                break;
            case RedKing:
                index=0;
                while(KingMoves[src][index])
                {
                    dst=KingMoves[src][index];
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                dst=piece[Type2Index[BlackKing]];
                if(Equal_File(src, dst))
                {
                    row=Square_Rank(src) - 3;
                    col=Square_File(src) - 3;
                    if(RookFileCapMin[row][file[col]] + col == dst)
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }
                }
                break;
            case BlackKing:
                index=0;
                while(KingMoves[src][index])
                {
                    dst=KingMoves[src][index];
                    if(!Index_Same_Color(square[src], square[dst]))
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }

                    ++index;
                }

                dst=piece[Type2Index[RedKing]];
                if(Equal_File(src, dst))
                {
                    row=Square_Rank(src) - 3;
                    col=Square_File(src) - 3;
                    if(RookFileCapMax[row][file[col]] + col == dst)
                    {
                        list->move[list->size].wmv.src=src;
                        list->move[list->size].wmv.dst=dst;
                        list->size++;
                    }
                }
                break;
        }
    }

    if(list->size != 0)
    {
        list->start= &list->move[0];
        list->last= &(list->move[list->size - 1]);
    }
}


void Board::getfen(char *fen) const
{
    int i, n=0, index=0;
    for(i=0; i < 90; i++)
    {
        if(i != 0 && i % 9 == 0)
        {
            if(n != 0)
            {
                fen[index++]=n + '0';
                n=0;
            }

            fen[index++]='/';
        }

        if(square[Square_From_90(i)] != PieceNone)
        {
            if(n != 0)
            {
                fen[index++]=n + '0';
                n=0;
            }

            fen[index++]=PieceStr[square[Square_From_90(i)]];
        }
        else
            ++n;
    }

    if(i == 90 && n != 0) fen[index++]=n + '0';
    fen[index++]=' ';
    if(turn == TurnRed)
        fen[index++]='w';
    else if(turn == TurnBlack)
        fen[index++]='b';
    fen[index]='\0';
}
void Board::makemove(const Move &move)
{
    int row, col, index;
	lastmove = move;
	lastcpt=square[move.wmv.dst];
    if(square[move.wmv.dst] != PieceNone)
    {
        index=square[move.wmv.dst];
        number[Index2Type[index]]--;
        index=square[move.wmv.src];
        row=Square_Rank(move.wmv.src) - 3;
        col=Square_File(move.wmv.src) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        piece[square[move.wmv.dst]]=SquareNone;
        piece[square[move.wmv.src]]=move.wmv.dst;
        square[move.wmv.dst]=square[move.wmv.src];
        square[move.wmv.src]=PieceNone;
    }
    else
    {
        index=square[move.wmv.src];
        row=Square_Rank(move.wmv.src) - 3;
        col=Square_File(move.wmv.src) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        row=Square_Rank(move.wmv.dst) - 3;
        col=Square_File(move.wmv.dst) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        piece[square[move.wmv.src]]=move.wmv.dst;
        square[move.wmv.dst]=square[move.wmv.src];
        square[move.wmv.src]=PieceNone;
    }

    turn^=1;
}
void Board::unmakemove(void)
{
    int row, col, index;
    if(lastcpt != PieceNone)
    {
        number[Index2Type[lastcpt]]++;
        index=square[lastmove.wmv.dst];
        row=Square_Rank(lastmove.wmv.src) - 3;
        col=Square_File(lastmove.wmv.src) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        piece[lastcpt]=lastmove.wmv.dst;
        piece[square[lastmove.wmv.dst]]=lastmove.wmv.src;
        square[lastmove.wmv.src]=square[lastmove.wmv.dst];
        square[lastmove.wmv.dst]=lastcpt;
    }
    else
    {
        index=square[lastmove.wmv.dst];
        row=Square_Rank(lastmove.wmv.src) - 3;
        col=Square_File(lastmove.wmv.src) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        row=Square_Rank(lastmove.wmv.dst) - 3;
        col=Square_File(lastmove.wmv.dst) - 3;
        file[col]^=1 << row;
        rank[row]^=1 << col;
        piece[square[lastmove.wmv.dst]]=lastmove.wmv.src;
        square[lastmove.wmv.src]=square[lastmove.wmv.dst];
        square[lastmove.wmv.dst]=PieceNone;
    }

    turn^=1;
}

bool Board::incheck(int side) const
{
    int sq, rook_ix, cannon_ix, knight_ix, king_sq, row, col, rowbit, colbit;

    if(side)
    {
        king_sq=piece[Type2Index[BlackKing]];
        rook_ix=Type2Index[RedRook];
        knight_ix=Type2Index[RedKnight];
        cannon_ix=Type2Index[RedCannon];
    }
    else
    {
        king_sq=piece[Type2Index[RedKing]];
        rook_ix=Type2Index[BlackRook];
        knight_ix=Type2Index[BlackKnight];
        cannon_ix=Type2Index[BlackCannon];
    }

    if(piece[Type2Index[BlackKing]] == 0 || piece[Type2Index[RedKing]] == 0) return true;
    col=Square_File(king_sq) - 3;
    row=Square_Rank(king_sq) - 3;
    rowbit=rank[row];
    colbit=file[col];

    //车将军
    if((sq=piece[rook_ix]) != 0)
    {
        if(Equal_File(sq, king_sq))
        {
            if(RookFileCapMin[row][colbit] + col == sq || RookFileCapMax[row][colbit] + col == sq) return true;
        }

        if(Equal_Rank(sq, king_sq))
        {
            if(RookRankCapMin[col][rowbit] + (row << 4) == sq || RookRankCapMax[col][rowbit] + (row << 4) == sq)
                return true;
        }
    }

    if((sq=piece[rook_ix + 1]) != 0)
    {
        if(Equal_File(sq, king_sq))
        {
            if(RookFileCapMin[row][colbit] + col == sq || RookFileCapMax[row][colbit] + col == sq) return true;
        }

        if(Equal_Rank(sq, king_sq))
        {
            if(RookRankCapMin[col][rowbit] + (row << 4) == sq || RookRankCapMax[col][rowbit] + (row << 4) == sq)
                return true;
        }
    }

    //炮将军
    if((sq=piece[cannon_ix]) != 0)
    {
        if(Equal_File(king_sq, sq))
        {
            if(CannonFileCapMin[row][colbit] + col == sq || CannonFileCapMax[row][colbit] + col == sq) return true;
        }

        if(Equal_Rank(king_sq, sq))
        {
            if(CannonRankCapMin[col][rowbit] + (row << 4) == sq || CannonRankCapMax[col][rowbit] + (row << 4) == sq)
                return true;
        }
    }

    if((sq=piece[cannon_ix + 1]) != 0)
    {
        if(Equal_File(king_sq, sq))
        {
            if(CannonFileCapMin[row][colbit] + col == sq || CannonFileCapMax[row][colbit] + col == sq) return true;
        }

        if(Equal_Rank(king_sq, sq))
        {
            if(CannonRankCapMin[col][rowbit] + (row << 4) == sq || CannonRankCapMax[col][rowbit] + (row << 4) == sq)
                return true;
        }
    }

    //马将军
    if((sq=piece[knight_ix]) != 0)
    {
        if(square[sq + NLegalDt[king_sq - sq + 256]] == SquareNone) return true;
    }

    if((sq=piece[knight_ix + 1]) != 0)
    {
        if(square[sq + NLegalDt[king_sq - sq + 256]] == SquareNone) return true;
    }

    //对脸和兵将军
    if(side)
    {
        sq=RookFileCapMax[row][colbit] + col;
        if(square[sq] == 16) return true;
        if(Index2Type[square[king_sq - 1]] == RedPawn ||
                   Index2Type[square[king_sq + 1]] == RedPawn ||
                   Index2Type[square[king_sq + 16]] == RedPawn)
            return true;
    }
    else
    {
        sq=RookFileCapMin[row][colbit] + col;
        if(square[sq] == 32) return true;
        if(Index2Type[square[king_sq - 1]] == BlackPawn ||
                   Index2Type[square[king_sq + 1]] == BlackPawn ||
                   Index2Type[square[king_sq - 16]] == BlackPawn)
            return true;
    }

    return false;
}
