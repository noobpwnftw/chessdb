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

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_judy.h"
#include "judy_handlers.h"
#include "judy_arrayaccess.h"
#include "judy_iterator.h"
#include "ext/spl/spl_iterators.h"

ZEND_DECLARE_MODULE_GLOBALS(judy)

/* declare judy class entry */
zend_class_entry *judy_ce;
zend_object_handlers judy_handlers;

	/* {{{ php_judy_init_globals
	*/
static void php_judy_init_globals(zend_judy_globals *judy_globals)
{
	judy_globals->max_length = 65536;
}
/* }}} */

/* {{{ judy_free_storage
   close all resources and the memory allocated for the object */
static void judy_object_free_storage(zend_object *object)
{
	judy_object *intern = php_judy_object(object);
	zend_object_std_dtor(&intern->std);
}
/* }}} */

/* {{{ judy_object_new_ex
*/
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr)
{
	judy_object *intern;

	intern = ecalloc(1, sizeof(judy_object) + zend_object_properties_size(ce));
	if (ptr) {
		*ptr = intern;
	}

	intern->next_empty_is_valid = 1;
	intern->next_empty = 0;

	zend_object_std_init(&(intern->std), ce);
	object_properties_init(&intern->std, ce);

	intern->std.handlers = &judy_handlers;
	return &intern->std;
}
/* }}} */

/* {{{ judy_object_new
*/
zend_object *judy_object_new(zend_class_entry *ce)
{
	return judy_object_new_ex(ce, NULL);
}
/* }}} */

PHP_JUDY_API zend_class_entry *php_judy_ce(void)
{
	return judy_ce;
}

/* {{{ PHP INI entries
*/
PHP_INI_BEGIN()
	STD_PHP_INI_ENTRY("judy.string.maxlength", "65536", PHP_INI_ALL, OnUpdateLong, max_length, zend_judy_globals, judy_globals)
PHP_INI_END()
/* }}} */

#define CHECK_ARRAY_AND_ARG_TYPE(_index_, _string_key_, _return_)	\
	switch (intern->type) {					\
		case TYPE_BITSET:					\
		case TYPE_INT_TO_INT:				\
		case TYPE_INT_TO_MIXED:				\
			_index_ = zval_get_long(offset);\
			_string_key_ = offset;			\
			break;							\
		case TYPE_STRING_TO_INT:			\
		case TYPE_STRING_TO_MIXED:			\
			if (Z_TYPE_P(offset) != IS_STRING) {	\
				ZVAL_COPY_VALUE(_string_key_, offset); \
				zval_copy_ctor(_string_key_);		\
				convert_to_string(_string_key_);	\
			} else {								\
				_string_key_ = offset;	\
			}								\
			break;							\
		default:							\
			php_error_docref(NULL, E_WARNING, "invalid Judy Array type, please report");	\
			_return_;						\
	}

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv) /* {{{ */
{
	long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval string_key, *pstring_key = &string_key;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	if (intern->array == NULL) {
		return NULL;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, return NULL);

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		ZVAL_BOOL(rv, Rc_int);
		return rv;
	}

	switch(intern->type) {
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLG(PValue, intern->array, j_index);
			break;
		case TYPE_STRING_TO_INT:
		case TYPE_STRING_TO_MIXED:
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			break;
	}

	if (PValue != NULL && PValue != PJERR) {
		if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT) {
			ZVAL_LONG(rv, (long)*PValue);
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			ZVAL_COPY_VALUE(rv, (zval *)*PValue);
			zval_copy_ctor(rv);
		}
		if (pstring_key != offset) {
			zval_dtor(pstring_key);
		}
		return rv;
	}
	if (pstring_key != offset) {
		zval_dtor(pstring_key);
	}
	return NULL;
}
/* }}} */

static zval *judy_object_read_dimension(zval *object, zval *offset, int type, zval *rv) /* {{{ */
{
	return judy_object_read_dimension_helper(object, offset, rv);
}
/* }}} */

int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value) /* {{{ */
{
	long index;
	zval string_key, *pstring_key = &string_key;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	if (offset) {
		CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, return FAILURE);
	} else {
		if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
			php_error_docref(NULL, E_ERROR, "Judy STRING_TO_INT and STRING_TO_MIXED values cannot be set without key specifying");
			return FAILURE;
		}
	}

	if (intern->type == TYPE_BITSET) {
		int         Rc_int;

		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					J1L(Rc_int, intern->array, index);

					if (Rc_int == 1) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		if (zend_is_true(value)) {
			J1S(Rc_int, intern->array, index);
		} else {
			J1U(Rc_int, intern->array, index);
		}
		return Rc_int ? SUCCESS : FAILURE;
	} else if (intern->type == TYPE_INT_TO_INT) {
		Pvoid_t   *PValue;
		long value_long = zval_get_long(value);

		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					JLL(PValue, intern->array, index);

					if (PValue != NULL && PValue != PJERR) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		JLI(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			*PValue = (void *)value_long;
			return SUCCESS;
		}
		return FAILURE;
	} else if (intern->type == TYPE_INT_TO_MIXED) {
		Pvoid_t     *PValue;

		if (!offset || index <= -1) {
			if (intern->array){
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					JLL(PValue, intern->array, index);

					if (PValue != NULL && PValue != PJERR) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		JLI(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			zval *old_value, *new_value;
			if (*PValue != NULL) {
				old_value = (zval *)*PValue;
				zval_ptr_dtor(old_value);
				new_value = old_value;
			} else {
				new_value = ecalloc(1, sizeof(zval));
			}
			ZVAL_DUP(new_value, value);
			*PValue = new_value;
			return SUCCESS;
		}
		return FAILURE;
	} else if (intern->type == TYPE_STRING_TO_INT) {
		PWord_t     *PValue;
		int res;

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (PValue != NULL && PValue != PJERR) {
			*PValue = (void *)zval_get_long(value);
			intern->counter++;
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		if (pstring_key != offset) {
			zval_ptr_dtor(pstring_key);
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		Pvoid_t *PValue;
		int res;

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (PValue != NULL && PValue != PJERR) {
			zval *old_value, *new_value;
			if (*PValue != NULL) {
				old_value = (zval *)*PValue;
				zval_ptr_dtor(old_value);
				new_value = old_value;
			} else {
				new_value = ecalloc(1, sizeof(zval));
				intern->counter++;
			}
			ZVAL_DUP(new_value, value);
			*PValue = new_value;
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		if (pstring_key != offset) {
			zval_dtor(pstring_key);
		}
		return res;
	}
	return FAILURE;
}
/* }}} */

static void judy_object_write_dimension(zval *object, zval *offset, zval *value) /* {{{ */
{
	judy_object_write_dimension_helper(object, offset, value);
}
/* }}} */

int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty) /* {{{ */
{
	long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval string_key, *pstring_key = &string_key;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	if (intern->array == NULL) {
		return 0;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, return 0);

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		return Rc_int;
	}

	switch(intern->type) {
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLG(PValue, intern->array, j_index);
			break;
		case TYPE_STRING_TO_INT:
		case TYPE_STRING_TO_MIXED:
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			if (pstring_key != offset) {
				zval_dtor(pstring_key);
			}
			break;
	}

	if (PValue != NULL && PValue != PJERR) {
		if (!check_empty) {
			return 1;
		} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT) {
			if (*PValue) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			if (*PValue && zend_is_true((zval *)*PValue)) {
				return 1;
			}
			return 0;
		}
	}
	return 0;
}
/* }}} */

static int judy_object_has_dimension(zval *object, zval *offset, int check_empty) /* {{{ */
{
	return judy_object_has_dimension_helper(object, offset, check_empty);
}
/* }}} */

int judy_object_unset_dimension_helper(zval *object, zval *offset) /* {{{ */
{
	int Rc_int = 0;
	long index = 0;
	Word_t j_index;
	zval string_key, *pstring_key = &string_key;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	if (intern->array == NULL) {
		return FAILURE;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, return FAILURE);

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		J1U(Rc_int, intern->array, j_index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		if (intern->type == TYPE_INT_TO_INT) {
			JLD(Rc_int, intern->array, j_index);
		} else {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JLD(Rc_int, intern->array, j_index);
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		if (intern->type == TYPE_STRING_TO_INT) {
			JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		} else {
			Pvoid_t     *PValue;
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			if (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
		if (pstring_key != offset) {
			zval_dtor(pstring_key);
		}
	}
	return Rc_int ? SUCCESS : FAILURE;
}
/* }}} */

static void judy_object_unset_dimension(zval *object, zval *offset) /* {{{ */
{
	judy_object_unset_dimension_helper(object, offset);
}
/* }}} */

/* {{{ PHP_MINIT_FUNCTION
*/
PHP_MINIT_FUNCTION(judy)
{
	zend_class_entry ce;

	ZEND_INIT_MODULE_GLOBALS(judy, php_judy_init_globals, NULL);

	REGISTER_INI_ENTRIES();

	/* Judy class definition */

	INIT_CLASS_ENTRY(ce, "Judy", judy_class_methods);

	judy_ce = zend_register_internal_class_ex(&ce, NULL);
	judy_ce->create_object = judy_object_new;

	memcpy(&judy_handlers, zend_get_std_object_handlers(),
			sizeof(zend_object_handlers));

	/* set some internal handlers */
	judy_handlers.clone_obj = judy_object_clone;
	judy_handlers.count_elements = judy_object_count;
	judy_handlers.read_dimension = judy_object_read_dimension;
	judy_handlers.write_dimension = judy_object_write_dimension;
	judy_handlers.unset_dimension = judy_object_unset_dimension;
	judy_handlers.has_dimension = judy_object_has_dimension;
	judy_handlers.dtor_obj = zend_objects_destroy_object;
	judy_handlers.free_obj = judy_object_free_storage;
	judy_handlers.offset = XtOffsetOf(judy_object, std);

	/* implements some interface to provide access to judy object as an array */
	zend_class_implements(judy_ce, 2, zend_ce_arrayaccess, spl_ce_Countable);

	judy_ce->get_iterator = judy_get_iterator;

	REGISTER_JUDY_CLASS_CONST_LONG("BITSET", TYPE_BITSET);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_INT", TYPE_INT_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_MIXED", TYPE_INT_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT", TYPE_STRING_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED", TYPE_STRING_TO_MIXED);

	return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
*/
PHP_RINIT_FUNCTION(judy)
{
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MSHUTDOWN_FUNCTION
*/
PHP_MSHUTDOWN_FUNCTION(judy)
{
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
*/
PHP_MINFO_FUNCTION(judy)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "Judy support", "enabled");
	php_info_print_table_row(2, "PHP Judy version", PHP_JUDY_VERSION);
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ proto Judy::__construct(long type)
   Constructs a new Judy array of the given type */
PHP_METHOD(judy, __construct)
{
	long                    type;
	judy_type               jtype;

	JUDY_METHOD_GET_OBJECT

	JUDY_METHOD_ERROR_HANDLING;

	if (intern->type) {
		php_error_docref(NULL, E_ERROR, "Judy Array already instantiated");
	} else if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &type) == SUCCESS) {
		JTYPE(jtype, type);
		intern->counter = 0;
		intern->type = jtype;
		intern->array = (Pvoid_t) NULL;
	}

	zend_restore_error_handling(&error_handling);
}
/* }}} */

/* {{{ proto Judy::__destruct()
   Free Judy array and any other references */
PHP_METHOD(judy, __destruct)
{
	zval *object = getThis();

	/* calling the object's free() method */
	zend_call_method_with_0_params(object, NULL, NULL, "free", NULL);
}
/* }}} */

/* {{{ proto long Judy::free()
   Free the entire Judy Array. Return the number of bytes freed */
PHP_METHOD(judy, free)
{
	JUDY_METHOD_GET_OBJECT

		Word_t    Rc_word = 0;
	Word_t    index;
	uint8_t   kindex[PHP_JUDY_MAX_LENGTH];
	Word_t    *PValue;

	switch (intern->type)
	{
		case TYPE_BITSET:
			/* Free Judy1 Array */
			J1FA(Rc_word, intern->array);
			break;

		case TYPE_INT_TO_INT:
			/* Free JudyL Array */
			JLFA(Rc_word, intern->array);
			break;

		case TYPE_INT_TO_MIXED:
			index = 0;

			/* Del ref to zval objects */
			JLF(PValue, intern->array, index);
			while(PValue != NULL && PValue != PJERR)
			{
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JLN(PValue, intern->array, index);
			}

			/* Free JudyL Array */
			JLFA(Rc_word, intern->array);
			break;

		case TYPE_STRING_TO_INT:
			/* Free JudySL Array */
			JSLFA(Rc_word, intern->array);

			/* Reset counter */
			intern->counter = 0;
			break;

		case TYPE_STRING_TO_MIXED:
			kindex[0] = '\0';

			/* Del ref to zval objects */
			JSLF(PValue, intern->array, kindex);
			while(PValue != NULL && PValue != PJERR)
			{
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JSLN(PValue, intern->array, kindex);
			}

			/* Free JudySL Array */
			JSLFA(Rc_word, intern->array);

			/* Reset counter */
			intern->counter = 0;
			break;
	}

	RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long Judy::memoryUsage()
   Return the memory used by the Judy Array */
PHP_METHOD(judy, memoryUsage)
{
	Word_t     Rc_word;

	JUDY_METHOD_GET_OBJECT

		switch (intern->type)
		{
			case TYPE_BITSET:
				J1MU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			case TYPE_INT_TO_INT:
			case TYPE_INT_TO_MIXED:
				JLMU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			default:
				RETURN_NULL();
		}
}
/* }}} */

/* {{{ proto long Judy::count()
   Return the current size of the array. */
PHP_METHOD(judy, count)
{
	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED) {
			Word_t   idx1 = 0;
			Word_t   idx2 = -1;
			Word_t   Rc_word;

			if (zend_parse_parameters(ZEND_NUM_ARGS(), "|ll", &idx1, &idx2) == FAILURE) {
				RETURN_FALSE;
			}

			if (intern->type == TYPE_BITSET) {
				J1C(Rc_word, intern->array, idx1, idx2);
			} else {
				JLC(Rc_word, intern->array, idx1, idx2);
			}

			RETURN_LONG(Rc_word);
		} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
			RETURN_LONG(intern->counter);
		}
}
/* }}} */

/* {{{ proto long Judy::byCount(long nth_index)
   Locate the Nth index that is present in the Judy array (Nth = 1 returns the first index present).
   To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judy, byCount)
{

	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED) {
			long            nth_index;
			Word_t            index;

			if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &nth_index) == FAILURE) {
				RETURN_FALSE;
			}

			if (intern->type == TYPE_BITSET) {
				int Rc_int;
				J1BC(Rc_int, intern->array, nth_index, index);
				if (Rc_int == 1)
					RETURN_LONG(index);
			} else {
				PWord_t PValue;
				JLBC(PValue, intern->array, nth_index, index);
				if (PValue != NULL && PValue != PJERR)
					RETURN_LONG(index);
			}
		}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::first([mixed index])
   Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy, first)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1F(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index = 0;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null temrinated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLF(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::next(mixed index)
   Search (exclusive) for the next index present that is greater than the passed Index */
PHP_METHOD(judy, next)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t          index;
		int             Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1N(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLN(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null temrinated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLN(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::last([mixed index])
   Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judy, last)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t       index = -1;
		int          Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1L(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index = -1;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLL(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		uint8_t     *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			memset(key, 0xff, PHP_JUDY_MAX_LENGTH);
			key[PHP_JUDY_MAX_LENGTH-1] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLL(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::prev(mixed index)
   Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judy, prev)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t       index;
		int          Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1P(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLP(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null temrinated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLP(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto long Judy::firstEmpty([long index])
   Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judy, firstEmpty)
{
	Word_t         index = 0;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1FE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLFE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::lastEmpty([long index])
   Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judy, lastEmpty)
{
	Word_t         index = -1;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1LE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLLE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::nextEmpty(long index)
   Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judy, nextEmpty)
{
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1NE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLNE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::prevEmpty(long index)
   Search (exclusive) for the previous index absent that is less than the passed Index */
PHP_METHOD(judy, prevEmpty)
{
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1PE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLPE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto int Judy::getType()
   Return the current Judy Array type */
PHP_METHOD(judy, getType)
{
	JUDY_METHOD_GET_OBJECT
	RETURN_LONG(intern->type);
}
/* }}} */

/* {{{ proto string judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
	RETURN_STRING(PHP_JUDY_VERSION);
}
/* }}} */

/* {{{ proto int judy_type(Judy array)
   Return the php judy type for the given array */
PHP_FUNCTION(judy_type)
{
	zval *object;
	judy_object *array;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &object) == FAILURE) {
		RETURN_FALSE;
	}

	array = php_judy_object(Z_OBJ_P(object));
	RETURN_LONG(array->type);
}
/* }}} */

PHP_MINIT_FUNCTION(judy);
PHP_MSHUTDOWN_FUNCTION(judy);
PHP_RINIT_FUNCTION(judy);
PHP_MINFO_FUNCTION(judy);

/* PHP Judy Function */
PHP_FUNCTION(judy_version);
PHP_FUNCTION(judy_type);

/* {{{ PHP Judy Methods
*/
PHP_METHOD(judy, __construct);
PHP_METHOD(judy, __destruct);
PHP_METHOD(judy, getType);
PHP_METHOD(judy, free);
PHP_METHOD(judy, memoryUsage);
PHP_METHOD(judy, count);
PHP_METHOD(judy, byCount);
PHP_METHOD(judy, first);
PHP_METHOD(judy, next);
PHP_METHOD(judy, last);
PHP_METHOD(judy, prev);
PHP_METHOD(judy, firstEmpty);
PHP_METHOD(judy, nextEmpty);
PHP_METHOD(judy, lastEmpty);
PHP_METHOD(judy, prevEmpty);
/* }}} */

/* {{{ PHP Judy Methods for the Array Access Interface
*/
PHP_METHOD(judy, offsetSet);
PHP_METHOD(judy, offsetUnset);
PHP_METHOD(judy, offsetGet);
PHP_METHOD(judy, offsetExists);
/* }}} */

/* {{{ Judy function parameters
*/
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_type, 0, 0, 1)
	ZEND_ARG_INFO(0, array)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters
*/
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_count, 0, 0, 0)
	ZEND_ARG_INFO(0, index_start)
	ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_byCount, 0, 0, 1)
	ZEND_ARG_INFO(0, nth_index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_first, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_next, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_last, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prev, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_firstEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_nextEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_lastEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prevEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters the Array Access Interface
*/
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetSet, 0, 0, 2)
	ZEND_ARG_INFO(0, offset)
	ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetUnset, 0, 0, 1)
	ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetGet, 0, 0, 1)
	ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_offsetExists, 0, 0, 1)
	ZEND_ARG_INFO(0, offset)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ judy_functions[]
 *
 * Every user visible function must have an entry in judy_functions[].
 */
const zend_function_entry judy_functions[] = {
	/* PHP JUDY FUNCTIONS */
	PHP_FE(judy_version, NULL)
	PHP_FE(judy_type, arginfo_judy_type)
	{NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy_class_methods[].
 */
const zend_function_entry judy_class_methods[] = {
	/* PHP JUDY METHODS */
	PHP_ME(judy, __construct, 		NULL, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
	PHP_ME(judy, __destruct, 		NULL, ZEND_ACC_PUBLIC | ZEND_ACC_DTOR)
	PHP_ME(judy, getType, 			NULL, ZEND_ACC_PUBLIC)
	PHP_ME(judy, free, 				NULL, ZEND_ACC_PUBLIC)
	PHP_ME(judy, memoryUsage, 		NULL, ZEND_ACC_PUBLIC)
	PHP_ME(judy, count, 			arginfo_judy_count, ZEND_ACC_PUBLIC)
	PHP_ME(judy, byCount, 			arginfo_judy_byCount, ZEND_ACC_PUBLIC)
	PHP_ME(judy, first, 			arginfo_judy_first, ZEND_ACC_PUBLIC)
	PHP_ME(judy, next, 				arginfo_judy_next, ZEND_ACC_PUBLIC)
	PHP_ME(judy, last, 				arginfo_judy_last, ZEND_ACC_PUBLIC)
	PHP_ME(judy, prev, 				arginfo_judy_prev, ZEND_ACC_PUBLIC)
	PHP_ME(judy, firstEmpty, 		arginfo_judy_firstEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, nextEmpty, 		arginfo_judy_nextEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, lastEmpty, 		arginfo_judy_lastEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, prevEmpty, 		arginfo_judy_prevEmpty, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS / ARRAYACCESS INTERFACE */
	PHP_ME(judy, offsetSet, 		arginfo_judy_offsetSet, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetUnset, 		arginfo_judy_offsetUnset, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetGet, 		arginfo_judy_offsetGet, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetExists, 		arginfo_judy_offsetExists, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS ALIAS */
	PHP_MALIAS(judy, size, 			count, NULL, ZEND_ACC_PUBLIC)

	/* NULL TEMRINATED VECTOR */
	{NULL, NULL, NULL}
};
/* }}} */

static const zend_module_dep judy_deps[] = {
	ZEND_MOD_REQUIRED("spl")
	{NULL, NULL, NULL}
};

/* {{{ judy_module_entry
*/
zend_module_entry judy_module_entry = {
	STANDARD_MODULE_HEADER_EX, NULL,
	judy_deps,
	PHP_JUDY_EXTNAME,
	judy_functions,
	PHP_MINIT(judy),
	PHP_MSHUTDOWN(judy),
	PHP_RINIT(judy),
	NULL,
	PHP_MINFO(judy),
	PHP_JUDY_VERSION,
	STANDARD_MODULE_PROPERTIES
};
/* }}} */

#ifdef COMPILE_DL_JUDY
ZEND_GET_MODULE(judy)
#endif

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
