#include "calloc.h"

#include <stdlib.h>

static int alloc_count = 0;

void* chess_alloc(size_t size)
{
    alloc_count++;
    return malloc(size);
}

void* chess_realloc(void* ptr, size_t size)
{
    return realloc(ptr, size);
}

void chess_free(void* ptr)
{
    alloc_count--;
    free(ptr);
}

int chess_alloc_count(void)
{
    return alloc_count;
}
