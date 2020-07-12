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

#include "php_judy.h"
#include "judy_arrayaccess.h"

/* {{{ proto int Judy::offsetSet(mixed offset, mixed value)
   Set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetSet)
{
	zval *offset, *value;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zz", &offset, &value) == FAILURE) {
		RETURN_FALSE;
	}

	if (judy_object_write_dimension_helper(getThis(), offset, value) == SUCCESS) {
		RETURN_TRUE;
	}
	RETURN_FALSE;
}
/* }}} */

/* {{{ proto int Judy::offsetUnset(mixed offset)
   Unset the given offset in the Judy Array */
PHP_METHOD(judy, offsetUnset)
{
	zval *offset;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
		RETURN_FALSE;
	}

	if (judy_object_unset_dimension_helper(getThis(), offset) == SUCCESS) {
		RETURN_TRUE;
	}
	RETURN_FALSE;
}
/* }}} */

/* {{{ proto mixed Judy::offsetGet(mixed offset)
   Fetch the given offset in the Judy Array */
PHP_METHOD(judy, offsetGet)
{
	zval *offset, result, *result_ptr;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
		RETURN_FALSE;
	}

	result_ptr = judy_object_read_dimension_helper(getThis(), offset, &result);
	if (!result_ptr) {
		RETURN_FALSE;
	}
	RETURN_ZVAL(&result, 1, 0);
}
/* }}} */

/* {{{ proto int Judy::offsetExists(mixed offset)
   Check if the the given offset exists in the Judy Array */
PHP_METHOD(judy, offsetExists)
{
	zval *offset;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &offset) == FAILURE) {
		RETURN_FALSE;
	}

	if (judy_object_has_dimension_helper(getThis(), offset, 0)) {
		RETURN_TRUE;
	}
	RETURN_FALSE;
}
/* }}} */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
