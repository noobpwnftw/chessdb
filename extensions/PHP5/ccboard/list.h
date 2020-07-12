#ifndef _LIST_H_
#define _LIST_H_
#include "move.h"

struct List
{
    int size;
    Move *start, *last;
    Move move[120];

    void clear(void)
    {
        size=0;
        start=last=0;
    }
    List()
    {
        clear();
    }
    Move *next(void)
    {
        if(!start || start > last) return 0;
        start++;
        return start - 1;
    }
};
#endif
