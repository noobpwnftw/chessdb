#ifndef CHESSLIB_ALLOC_H_
#define CHESSLIB_ALLOC_H_

#include <stddef.h>

void* chess_alloc(size_t size);
void* chess_realloc(void* ptr, size_t size);
void chess_free(void* ptr);

int chess_alloc_count(void);

#endif /* CHESSLIB_ALLOC_H_ */
