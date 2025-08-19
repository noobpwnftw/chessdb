#include <assert.h>
#include <stdlib.h>
#include <memory.h>

#include "carray.h"
#include "calloc.h"

void chess_array_init(ChessArray* array, size_t elem_size)
{
    array->elem_size = elem_size;
    array->size = 0;
    array->max_size = 0;
}

void chess_array_cleanup(ChessArray* array)
{
    if (array->max_size > 0)
        chess_free(array->data);
}

size_t chess_array_size(const ChessArray* array)
{
    return array->size;
}

const void* chess_array_data(const ChessArray* array)
{
    assert(array->size > 0);
    return array->data;
}

const void* chess_array_elem(const ChessArray* array, size_t index)
{
    assert(index < array->size);
    return (const char*)array->data + (index * array->elem_size);
}

void chess_array_clear(ChessArray* array)
{
    if (array->max_size > 0)
    {
        chess_free(array->data);
        array->size = 0;
        array->max_size = 0;
    }
}

void chess_array_set_elem(ChessArray* array, size_t index, const void* value)
{
    void* dest;
    assert(index < array->size);
    dest = (char*)array->data + (index * array->elem_size);
    memcpy(dest, value, array->elem_size);
}

static void expand(ChessArray* array)
{
    size_t new_size = array->max_size ? array->max_size * 2 : 8;
    if (array->max_size > 0)
        array->data = chess_realloc(array->data, new_size * array->elem_size);
    else
        array->data = chess_alloc(new_size * array->elem_size);
    array->max_size = new_size;
}

void chess_array_push(ChessArray* array, const void* elem)
{
    if (array->size == array->max_size)
    {
        expand(array);
    }
    assert(array->size < array->max_size);
    chess_array_set_elem(array, array->size++, elem);
}

void chess_array_pop(ChessArray* array, void* elem)
{
    assert(array->size > 0);
    if (elem)
    {
        size_t index = array->size - 1;
        void* src = (char*)array->data + (index * array->elem_size);
        memcpy(elem, src, array->elem_size);
    }
    array->size--;
}

void chess_array_prune(ChessArray* array, size_t size)
{
    assert(size <= array->size);
    array->size = size;
}
