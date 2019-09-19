#ifndef _UTIL_H_
#define _UTIL_H_

#include <stddef.h>

typedef signed char S8,INT8,int8, sint8;
typedef unsigned char U8,UINT8,uint8;
typedef signed short S16,INT16,SINT16,int16, sint16;
typedef unsigned short U16,UINT16,uint16;
typedef signed int S32,INT,INT32,SINT32, int32, sint32;
typedef unsigned int U32,UINT,UINT32, uint32;
typedef signed long long S64,INT64, int64, sint64;
typedef unsigned long long U64,UINT64, uint64;
typedef volatile int lock_t, LOCK;


#define INLINE  inline

template<typename T>
INLINE const T& Max(const T& a, const T& b)
{
	return a > b ? a : b;
};
template<typename T>
INLINE const T& Min(const T& a, const T& b)
{
	return a < b ? a : b;
};
template<typename T>
INLINE const T Abs(const T& a)
{
	return a >= 0 ? a : -a;
};
template<typename T>
INLINE bool Mid(const T& m, const T& a, const T& b)
{
	return ((m > a && m < b) || (m > b && m < a));
};

#endif