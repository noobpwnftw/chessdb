#ifndef _MOVE_H_
#define _MOVE_H_
#include "type.h"

struct Move
{
    union
    {
        uint16 mv;
        struct
        {
            uint8  src, dst;
        };
    } wmv;
};
const int   Rap=1;
const int   MeBan=2;
const int   OppBan=3;
const int   MeCheck=4;
const int   OppCheck=5;
const Move  NullMove={0};
const int   MoveNone=0;
const int   CheckValue=0x1000000;

bool movetostr(Move &mv, char *str);
bool movefromstr(Move &mv, char *str);

inline bool ismoveeq(const Move &move1, const Move &move2)
{
    return(move1.wmv.mv == move2.wmv.mv);
}

inline bool ismoveneq(const Move &move1, const Move &move2)
{
    return(move1.wmv.mv != move2.wmv.mv);
}

#endif
