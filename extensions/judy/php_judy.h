/*
  +----------------------------------------------------------------------+
  | PHP Judy                                                             |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2010 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Nicolas Brousse <nicolas@brousse.info>                       |
  +----------------------------------------------------------------------+
*/

#ifndef PHP_JUDY_H
#define PHP_JUDY_H

#define PHP_JUDY_VERSION "1.0.2"
#define PHP_JUDY_EXTNAME "judy"

#include <Judy.h>

#include "php.h"
#include "php_ini.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/info.h"

extern zend_module_entry judy_module_entry;
#define phpext_judy_ptr &judy_module_entry

#ifdef PHP_WIN32
#    define PHP_JUDY_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#    define PHP_JUDY_API __attribute__ ((visibility("default")))
#else
#    define PHP_JUDY_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

extern const zend_function_entry judy_class_methods[];

/* {{{ judy_type
 internal Judy Array type (aka Judy1, JudyL and JudySL) */
typedef enum _judy_type {
    TYPE_BITSET=1,
    TYPE_INT_TO_INT,
    TYPE_INT_TO_MIXED,
    TYPE_STRING_TO_INT,
    TYPE_STRING_TO_MIXED
} judy_type;
/* }}} */

#define JTYPE(jtype, type) { \
    if (type != TYPE_BITSET && type != TYPE_INT_TO_INT \
                           && type != TYPE_INT_TO_MIXED \
                           && type != TYPE_STRING_TO_INT \
                           && type != TYPE_STRING_TO_MIXED) { \
        php_error_docref(NULL, E_WARNING, "Not a valid Judy type. Please check the documentation for valid Judy type constant."); \
    } \
    jtype = type; \
}

#define JUDY_METHOD_ERROR_HANDLING \
    zend_error_handling error_handling; \
    zend_replace_error_handling(EH_THROW, NULL, &error_handling);

#define JUDY_METHOD_GET_OBJECT \
    zval *object = getThis(); \
    judy_object *intern = php_judy_object(Z_OBJ_P(object));

typedef struct _judy_object {
	long            type;
	Pvoid_t         array;
	unsigned long   counter;
	Word_t			next_empty;
	zend_bool		next_empty_is_valid;
	zend_object     std;
} judy_object;

static inline judy_object *php_judy_object(zend_object *obj) {
	return (judy_object *)((char*)(obj) - XtOffsetOf(judy_object, std));
}

/* Max length, this must be a constant for it to work in
 * declarings as we cannot use runtime decided values at
 * compile time ofcourse
 *
 * TODO:	This needs to be handled better
 */
#define PHP_JUDY_MAX_LENGTH 65536

zend_object *judy_object_new(zend_class_entry *ce);
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr);

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv);
int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value);
int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty);
int judy_object_unset_dimension_helper(zval *object, zval *offset);

/* {{{ REGISTER_JUDY_CLASS_CONST_LONG */
#define REGISTER_JUDY_CLASS_CONST_LONG(const_name, value) \
    zend_declare_class_constant_long(judy_ce, const_name, sizeof(const_name)-1, (long) value);
/* }}} */

ZEND_BEGIN_MODULE_GLOBALS(judy)
    unsigned long    max_length;
ZEND_END_MODULE_GLOBALS(judy)

ZEND_EXTERN_MODULE_GLOBALS(judy)

#ifdef ZTS
#define JUDY_G(v) TSRMG(judy_globals_id, zend_judy_globals *, v)
#else
#define JUDY_G(v) (judy_globals.v)
#endif

/* Grabbing CE's so that other exts can use the date objects too */
PHP_JUDY_API zend_class_entry *php_judy_ce(void);

#endif    /* PHP_JUDY_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
