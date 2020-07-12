#include "data.h"
#include "piece.h"
#include "square.h"
/*$off*/
const sint32 NLegalDt[512]=
{
    0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,-16,0,-16,0,0,0,0,0,0,0,
    0,0,0,0,0,-1,0,0,0,1,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,-1,0,0,0,1,0,0,0,0,0,0,
    0,0,0,0,0,0,16,0,16,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0
};
/*$on*/

sint32          RankNonCapMax[9][512];
sint32          RankNonCapMin[9][512];
sint32          FileNonCapMax[10][1024];
sint32          FileNonCapMin[10][1024];

sint32          RookRankCapMax[9][512];
sint32          RookRankCapMin[9][512];
sint32          RookFileCapMax[10][1024];
sint32          RookFileCapMin[10][1024];

sint32          CannonRankCapMax[9][512];
sint32          CannonRankCapMin[9][512];
sint32          CannonFileCapMax[10][1024];
sint32          CannonFileCapMin[10][1024];

sint32          KingMoves[256][8];
sint32          AdvisorMoves[256][8];
sint32          BishopMoves[256][8];
sint32          ElephantEyes[256][4];
sint32          KnightMoves[256][12];
sint32          HorseLegs[256][8];
sint32          FileNonCapMoves[10][1024][12];
sint32          RankNonCapMoves[9][512][12];
sint32          RookFileCapMoves[10][1024][4];
sint32          RookRankCapMoves[9][512][4];
sint32          CannonFileCapMoves[10][1024][4];
sint32          CannonRankCapMoves[9][512][4];
sint32          PawnMoves[2][256][4];
sint32          PawnChecks[2][256][4];

sint32          KLegalDt[512];
sint32          ALegalDt[512];
sint32          BLegalDt[512];

//sint32          PinNb[10][10][1024];
//sint32          RankMoveNb[9][512];
//sint32          FileMoveNb[10][1024];

static void InitDt(void)
{
    int i;
    for(i=0; i < 512; ++i)
    {
        KLegalDt[i]=0;
        ALegalDt[i]=0;
        BLegalDt[i]=0;
    }

    i=0;
    while(KingInc[i])
    {
        KLegalDt[KingInc[i] + 256]=KingInc[i];
        ++i;
    }

    i=0;
    while(AdvisorInc[i])
    {
        ALegalDt[AdvisorInc[i] + 256]=AdvisorInc[i];
        ++i;
    }

    i=0;
    while(BishopInc[i])
    {
        BLegalDt[BishopInc[i] + 256]=BishopInc[i];
        ++i;
    }
}
void InitData(void)
{
    sint32  SrcSq, DstSq, Index, i, j, k;

    for(i=0; i < 9; i++)
    {
        for(j=0; j < 512; j++)
        {
            Index=0;
            RankNonCapMax[i][j]=i + A0;
            for(k=i + 1; k <= 8; k++)
            {
                if(j & (1 << k))
                {
                    break;
                }

                RankNonCapMoves[i][j][Index]=k + A0;
                Index++;
                RankNonCapMax[i][j]=k + A0;
            }

            RankNonCapMin[i][j]=i + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    break;
                }

                RankNonCapMoves[i][j][Index]=k + A0;
                Index++;
                RankNonCapMin[i][j]=k + A0;
            }

            RankNonCapMoves[i][j][Index]=0;
            Index=0;
            RookRankCapMax[i][j]=i + A0;
            for(k=i + 1; k <= 8; k++)
            {
                if(j & (1 << k))
                {
                    RookRankCapMoves[i][j][Index]=k + A0;
                    Index++;
                    RookRankCapMax[i][j]=k + A0;
                    break;
                }
            }

            RookRankCapMin[i][j]=i + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    RookRankCapMoves[i][j][Index]=k + A0;
                    Index++;
                    RookRankCapMin[i][j]=k + A0;
                    break;
                }
            }

            RookRankCapMoves[i][j][Index]=0;
            Index=0;
            CannonRankCapMax[i][j]=i + A0;
            for(k=i + 1; k <= 8; k++)
            {
                if(j & (1 << k))
                {
                    k++;
                    break;
                }
            }

            for(; k <= 8; k++)
            {
                if(j & (1 << k))
                {
                    CannonRankCapMoves[i][j][Index]=k + A0;
                    Index++;
                    CannonRankCapMax[i][j]=k + A0;
                    break;
                }
            }

            CannonRankCapMin[i][j]=i + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    k--;
                    break;
                }
            }

            for(; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    CannonRankCapMoves[i][j][Index]=k + A0;
                    Index++;
                    CannonRankCapMin[i][j]=k + A0;
                    break;
                }
            }

            CannonRankCapMoves[i][j][Index]=0;
        }
    }

    for(i=0; i < 10; i++)
    {
        for(j=0; j < 1024; j++)
        {
            Index=0;
            FileNonCapMax[i][j]=(i << 4) + A0;
            for(k=i + 1; k <= 9; k++)
            {
                if(j & (1 << k))
                {
                    break;
                }

                FileNonCapMoves[i][j][Index]=(k << 4) + A0;
                Index++;
                FileNonCapMax[i][j]=(k << 4) + A0;
            }

            FileNonCapMin[i][j]=(i << 4) + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    break;
                }

                FileNonCapMoves[i][j][Index]=(k << 4) + A0;
                Index++;
                FileNonCapMin[i][j]=(k << 4) + A0;
            }

            FileNonCapMoves[i][j][Index]=0;
            Index=0;
            RookFileCapMax[i][j]=(i << 4) + A0;
            for(k=i + 1; k <= 9; k++)
            {
                if(j & (1 << k))
                {
                    RookFileCapMoves[i][j][Index]=(k << 4) + A0;
                    Index++;
                    RookFileCapMax[i][j]=(k << 4) + A0;
                    break;
                }
            }

            RookFileCapMin[i][j]=(i << 4) + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    RookFileCapMoves[i][j][Index]=(k << 4) + A0;
                    Index++;
                    RookFileCapMin[i][j]=(k << 4) + A0;
                    break;
                }
            }

            RookFileCapMoves[i][j][Index]=0;
            Index=0;
            CannonFileCapMax[i][j]=(i << 4) + A0;
            for(k=i + 1; k <= 9; k++)
            {
                if(j & (1 << k))
                {
                    k++;
                    break;
                }
            }

            for(; k <= 9; k++)
            {
                if(j & (1 << k))
                {
                    CannonFileCapMoves[i][j][Index]=(k << 4) + A0;
                    Index++;
                    CannonFileCapMax[i][j]=(k << 4) + A0;
                    break;
                }
            }

            CannonFileCapMin[i][j]=(i << 4) + A0;
            for(k=i - 1; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    k--;
                    break;
                }
            }

            for(; k >= 0; k--)
            {
                if(j & (1 << k))
                {
                    CannonFileCapMoves[i][j][Index]=(k << 4) + A0;
                    Index++;
                    CannonFileCapMin[i][j]=(k << 4) + A0;
                    break;
                }
            }

            CannonFileCapMoves[i][j][Index]=0;
        }
    }

    for(SrcSq=0; SrcSq < 256; SrcSq++)
    {
        if(In_City(SrcSq))
        {
            Index=0;
            for(i=0; i < 4; i++)
            {
                DstSq=SrcSq + KingInc[i];
                if(In_City(DstSq))
                {
                    KingMoves[SrcSq][Index]=DstSq;
                    Index++;
                }
            }

            KingMoves[SrcSq][Index]=0;

            Index=0;
            for(i=0; i < 4; i++)
            {
                DstSq=SrcSq + AdvisorInc[i];
                if(In_City(DstSq))
                {
                    AdvisorMoves[SrcSq][Index]=DstSq;
                    Index++;
                }
            }

            AdvisorMoves[SrcSq][Index]=0;
        }

        if(SquareTo90[SrcSq] != -1)
        {
            Index=0;
            for(i=0; i < 4; i++)
            {
                DstSq=SrcSq + BishopInc[i];
                if(SquareTo90[DstSq] != -1 && !((SrcSq ^ DstSq) & 0x80))
                {
                    BishopMoves[SrcSq][Index]=DstSq;
                    ElephantEyes[SrcSq][Index]=(SrcSq + DstSq) >> 1;
                    Index++;
                }
            }

            BishopMoves[SrcSq][Index]=0;

            Index=0;
            for(i=0; i < 8; i++)
            {
                DstSq=SrcSq + KnightInc[i];
                if(SquareTo90[DstSq] != -1)
                {
                    KnightMoves[SrcSq][Index]=DstSq;
                    HorseLegs[SrcSq][Index]=SrcSq + NLegalDt[DstSq - SrcSq + 256];
                    Index++;
                }
            }

            KnightMoves[SrcSq][Index]=0;

            for(i=0; i <= 1; i++)
            {
                Index=0;
                DstSq=i ? SrcSq + 16 : SrcSq - 16;
                if(SquareTo90[DstSq] != -1)
                {
                    PawnMoves[i][SrcSq][Index]=DstSq;
                    Index++;
                }

                if(i ? (SrcSq & 0x80) : !(SrcSq & 0x80))
                {
                    for(j= -1; j <= 1; j+=2)
                    {
                        DstSq=SrcSq + j;
                        if(SquareTo90[DstSq] != -1)
                        {
                            PawnMoves[i][SrcSq][Index]=DstSq;
                            Index++;
                        }
                    }
                }

                PawnMoves[i][SrcSq][Index]=0;
            }

            for(i=0; i <= 1; i++)
            {
                Index=0;
                if(i == 0)
                {
                    if(SrcSq < A6)
                    {
                        DstSq=SrcSq + 16;
                        if(SquareTo90[DstSq] != -1)
                        {
                            PawnChecks[i][SrcSq][Index]=DstSq;
                            Index++;
                        }

                        if(!(SrcSq & 0x80))
                        {
                            for(j= -1; j <= 1; j+=2)
                            {
                                DstSq=SrcSq + j;
                                if(SquareTo90[DstSq] != -1)
                                {
                                    PawnChecks[i][SrcSq][Index]=DstSq;
                                    Index++;
                                }
                            }
                        }
                    }

                    PawnChecks[i][SrcSq][Index]=0;
                }
                else
                {
                    if(SrcSq > I3)
                    {
                        DstSq=SrcSq - 16;
                        if(SquareTo90[DstSq] != -1)
                        {
                            PawnChecks[i][SrcSq][Index]=DstSq;
                            Index++;
                        }

                        if(SrcSq & 0x80)
                        {
                            for(j= -1; j <= 1; j+=2)
                            {
                                DstSq=SrcSq + j;
                                if(SquareTo90[DstSq] != -1)
                                {
                                    PawnChecks[i][SrcSq][Index]=DstSq;
                                    Index++;
                                }
                            }
                        }
                    }

                    PawnChecks[i][SrcSq][Index]=0;
                }
            }
        }
    }
    InitDt();
}
