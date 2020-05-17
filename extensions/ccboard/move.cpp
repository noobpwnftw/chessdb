#include <ctype.h>
#include <string.h>
#include "move.h"
#include "square.h"

/* */
bool movetostr(Move &mv, char *str)
{
    int file, rank;
    if(ismoveeq(mv, NullMove))
    {
        str[0]='N';
        str[1]='U';
        str[2]='L';
        str[3]='L';
        return true;
    }

    if(Square_To_90(mv.wmv.src) < 0 || Square_To_90(mv.wmv.dst) < 0) return false;
    file=Square_File(mv.wmv.src);
    rank=Square_Rank(mv.wmv.src);
    str[0]='a' + file - FileA;
    str[1]='0' + 9 - (rank - Rank0);
    file=Square_File(mv.wmv.dst);
    rank=Square_Rank(mv.wmv.dst);
    str[2]='a' + file - FileA;
    str[3]='0' + 9 - (rank - Rank0);
    str[4]='\0';
    return true;
}

/* */
bool movefromstr(Move &mv, char *str)
{
    if(strlen(str) < 4) return false;

    int file, rank;
    str[0]=tolower(str[0]);
    str[2]=tolower(str[2]);
    file=str[0] - 'a' + FileA;
    rank= -str[1] + '0' + Rank0 + 9;
    mv.wmv.src=Square_Make(file, rank);
    file=str[2] - 'a' + FileA;
    rank= -str[3] + '0' + Rank0 + 9;
    mv.wmv.dst=Square_Make(file, rank);
    if(Square_To_90(mv.wmv.src) < 0 || Square_To_90(mv.wmv.dst) < 0) return false;
    return true;
}
