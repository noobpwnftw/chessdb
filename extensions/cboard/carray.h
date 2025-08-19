#ifndef CHESSLIB_ARRAY_H_
#define CHESSLIB_ARRAY_H_

typedef struct ChessArray
{
    size_t elem_size;
    size_t size;
    size_t max_size;
    void* data;
} ChessArray;

void chess_array_init(ChessArray*, size_t elem_size);
void chess_array_cleanup(ChessArray*);

size_t chess_array_size(const ChessArray*);
const void* chess_array_data(const ChessArray*);
const void* chess_array_elem(const ChessArray*, size_t index);

void chess_array_clear(ChessArray*);
void chess_array_set_elem(ChessArray*, size_t index, const void* elem);
void chess_array_push(ChessArray*, const void* elem);
void chess_array_pop(ChessArray*, void* elem);
void chess_array_prune(ChessArray*, size_t size);

#endif /* CHESSLIB_ARRAY_H_ */
