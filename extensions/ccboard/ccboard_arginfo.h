/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 352f9ca9f13e974d3ebbe66918474e27b049c931 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbgetfen, 0, 1, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbmovegen, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbmovemake, 0, 2, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, move, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_ccbgetLRfen arginfo_ccbgetfen

#define arginfo_ccbgetBWfen arginfo_ccbgetfen

#define arginfo_ccbgetLRBWfen arginfo_ccbgetfen

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbgetLRmove, 0, 1, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, move, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_ccbgetBWmove arginfo_ccbgetLRmove

#define arginfo_ccbgetLRBWmove arginfo_ccbgetLRmove

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbincheck, 0, 1, _IS_BOOL, 1)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_ccbfen2hexfen arginfo_ccbgetfen

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbhexfen2fen, 0, 1, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, hexfen, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbrulecheck, 0, 2, IS_LONG, 1)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, arr, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, verify, _IS_BOOL, 0, "false")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, check_times, IS_LONG, 0, "1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_ccbruleischase, 0, 2, IS_LONG, 1)
	ZEND_ARG_TYPE_INFO(0, fen, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, move, IS_STRING, 0)
ZEND_END_ARG_INFO()

