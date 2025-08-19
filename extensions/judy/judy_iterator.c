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
#include "judy_iterator.h"

/* {{{ judy iterator handlers table
*/
zend_object_iterator_funcs judy_iterator_funcs = {
	judy_iterator_dtor,
	judy_iterator_valid,
	judy_iterator_current_data,
	judy_iterator_current_key,
	judy_iterator_move_forward,
	judy_iterator_rewind,
	NULL /* invalidate current */
};
/* }}} */

/* {{{ judy_get_iterator
*/
zend_object_iterator *judy_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
{
	judy_iterator *it = emalloc(sizeof(judy_iterator));

	if (by_ref) {
		zend_error(E_ERROR, "An iterator cannot be used with foreach by reference");
	}

	Z_ADDREF_P(object);

	ZVAL_COPY_VALUE(&it->intern.data, object);

	zend_iterator_init((zend_object_iterator*)it);
	it->intern.funcs = &judy_iterator_funcs;
	ZVAL_UNDEF(&it->key);
	ZVAL_UNDEF(&it->data);

	return &it->intern;
}
/* }}} */

/* {{{ judy_iterator_data_dtor
*/
void judy_iterator_data_dtor(judy_iterator *it)
{
	zval_ptr_dtor(&it->key);
	ZVAL_UNDEF(&it->key);
	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);
}
/* }}} */

/* {{{ judy_iterator_dtor
*/
void judy_iterator_dtor(zend_object_iterator *iterator)
{
	judy_iterator   *it = (judy_iterator*) iterator;
	zval            *intern = (zval*) &it->intern.data;

	judy_iterator_data_dtor(it);

	zval_ptr_dtor(&it->intern.data);
}
/* }}} */

/* {{{ judy_iterator_valid
*/
int judy_iterator_valid(zend_object_iterator *iterator)
{
	JUDY_ITERATOR_GET_OBJECT

	if (Z_ISUNDEF_P(&it->key) && Z_ISUNDEF_P(&it->data)) {
		return FAILURE;
	}

	if (object->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, object->array, (Word_t) Z_LVAL_P(&it->key));
		if (Rc_int == 1) {
			return SUCCESS;
		}
	} else if (object->type == TYPE_INT_TO_INT || object->type == TYPE_INT_TO_MIXED) {
		Word_t    *PValue;

		JLG(PValue, object->array, (Word_t) Z_LVAL_P(&it->key));
		if (PValue != NULL && PValue != PJERR) {
			return SUCCESS;
		}
	} else if (object->type == TYPE_STRING_TO_INT || object->type == TYPE_STRING_TO_MIXED) {
		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Word_t      *PValue;

		if (Z_TYPE_P(&it->key) == IS_STRING) {
			int key_len;
			key_len = Z_STRLEN_P(&it->key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&it->key);
			memcpy(key, Z_STRVAL_P(&it->key), key_len);
			key[key_len] = '\0';
		} else if (Z_TYPE_P(&it->key) == IS_NULL) {
			key[0] = '\0';
		} else {
			return FAILURE;
		}

		JSLG(PValue, object->array, key);
		if (PValue != NULL && PValue != PJERR) {
			return SUCCESS;
		}
	}

	return FAILURE;
}
/* }}} */

/* {{{ judy_iterator_current_data
*/
zval *judy_iterator_current_data(zend_object_iterator *iterator)
{
	judy_iterator *it = (judy_iterator*) iterator;
	return &it->data;
}
/* }}} */

/* {{{ judy_iterator_current_key
*/
void judy_iterator_current_key(zend_object_iterator *iterator, zval *key)
{
	judy_iterator 	*it = (judy_iterator*) iterator;

	ZVAL_ZVAL(key, &it->key, 1, 0);
}
/* }}} */

/* {{{ judy_iterator_move_forward
*/
void judy_iterator_move_forward(zend_object_iterator *iterator)
{
	JUDY_ITERATOR_GET_OBJECT

	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);

	if (object->type == TYPE_BITSET) {

		Word_t          index;
		int             Rc_int;

		if (Z_ISUNDEF_P(&it->key)) {
			index = 0;
			J1F(Rc_int, object->array, index);
		} else {
			index = Z_LVAL_P(&it->key);
			J1N(Rc_int, object->array, index);
		}

		if (Rc_int) {
			zval_dtor(&it->key);
			ZVAL_LONG(&it->key, index);
			ZVAL_BOOL(&it->data, 1);
		} else {
			judy_iterator_data_dtor(it);
		}

	} else if (object->type == TYPE_INT_TO_INT || object->type == TYPE_INT_TO_MIXED) {

		Word_t          index;
		Pvoid_t          *PValue = NULL;

		if (Z_ISUNDEF_P(&it->key)) {
			index = 0;
			JLF(*PValue, object->array, index);
		} else {
			index = Z_LVAL_P(&it->key);
			JLN(PValue, object->array, index);
		}

		zval_dtor(&it->key);
		ZVAL_LONG(&it->key, index);

		JLG(PValue, object->array, index);
		if (PValue != NULL && PValue != PJERR) {
			if (object->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&it->data, (long)*PValue);
			} else {
				zval *value = *(zval **)PValue;

				ZVAL_COPY_VALUE(&it->data, value);
				zval_copy_ctor(&it->data);
			}
		} else {
			judy_iterator_data_dtor(it);
		}

	} else if (object->type == TYPE_STRING_TO_INT || object->type == TYPE_STRING_TO_MIXED) {

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		/* JudySL require null temrinated strings */
		if (Z_TYPE_P(&it->key) == IS_STRING) {
			int key_len;
			key_len = Z_STRLEN_P(&it->key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&it->key);
			memcpy(key, Z_STRVAL_P(&it->key), key_len);
			key[key_len] = '\0';

			JSLN(PValue, object->array, key);
		} else {
			key[0] = '\0';
			JSLF(PValue, object->array, key);
		}

		if ((PValue != NULL && PValue != PJERR)) {

			zval_dtor(&it->key);
			ZVAL_STRING(&it->key, (char *)key);

			if (object->type == TYPE_STRING_TO_INT) {
				ZVAL_LONG(&it->data, (long)*PValue);
			} else {
				zval *value = *(zval **)PValue;

				ZVAL_COPY_VALUE(&it->data, value);
				zval_copy_ctor(&it->data);
			}
		} else {
			judy_iterator_data_dtor(it);
		}
	}
}
/* }}} */

/* {{{ judy_iterator_rewind
*/
void judy_iterator_rewind(zend_object_iterator *iterator)
{
	JUDY_ITERATOR_GET_OBJECT

	zval_ptr_dtor(&it->data);
	ZVAL_UNDEF(&it->data);

	if (object->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		J1F(Rc_int, object->array, index);
		zval_dtor(&it->key);
		ZVAL_LONG(&it->key, index);
		ZVAL_BOOL(&it->data, 1);

	} else if (object->type == TYPE_INT_TO_INT || object->type == TYPE_INT_TO_MIXED) {

		Word_t          index   = 0;
		Pvoid_t          *PValue = NULL;

		JLF(PValue, object->array, index);
		zval_dtor(&it->key);
		ZVAL_LONG(&it->key, index);

		JLG(PValue, object->array, index);
		if (PValue != NULL && PValue != PJERR) {
			if (object->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&it->data, (long)*PValue);
			} else {
				zval *value = *(zval **)PValue;

				ZVAL_COPY_VALUE(&it->data, value);
				zval_copy_ctor(&it->data);
			}
		}

	} else if (object->type == TYPE_STRING_TO_INT || object->type == TYPE_STRING_TO_MIXED) {

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		/* JudySL require null temrinated strings */
		key[0] = '\0';
		JSLF(PValue, object->array, key);

		if (PValue != NULL && PValue != PJERR) {
			zval_dtor(&it->key);
			ZVAL_STRING(&it->key, (const char *) key);
			if (object->type == TYPE_STRING_TO_INT) {
				ZVAL_LONG(&it->data, (long)*PValue);
			} else {
				zval *value = *(zval **)PValue;

				ZVAL_COPY_VALUE(&it->data, value);
				zval_copy_ctor(&it->data);
			}
		}
	}
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
