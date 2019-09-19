#ifndef _TYPE_H_
#define _TYPE_H_

typedef signed char             sint8;
typedef unsigned char           uint8;

typedef signed short            sint16;
typedef unsigned short          uint16;

typedef signed int              sint32;
typedef unsigned int            uint32;
typedef signed long             lsint32;
typedef unsigned long           luint32;

#ifdef _MSC_VER
typedef signed __int64          sint64;
typedef unsigned __int64        uint64;
#else
typedef signed long long int    sint64;
typedef unsigned long long int  uint64;
#endif
#endif
