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
#include "judy_handlers.h"

/* {{{ judy_object_count
*/
int judy_object_count(zval *object, long *count)
{
	zval rv;

	/* calling the object's count() method */
	zend_call_method_with_0_params(object, NULL, NULL, "count", &rv);
	*count = Z_LVAL_P(&rv);

	/* destruct the zval returned value */
	zval_ptr_dtor(&rv);

	return SUCCESS;
}
/* }}} */

/* {{{ judy_object_clone
*/
zend_object *judy_object_clone(zval *this_ptr)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = php_judy_object(Z_OBJ_P(this_ptr));

	judy_object_new_ex(old_obj->std.ce, &new_obj);

	/* new Judy array to populate */
	Pvoid_t newJArray = (Pvoid_t) NULL;

	zend_objects_clone_members(&new_obj->std, &old_obj->std);

	if (old_obj->type == TYPE_BITSET) {
		/* Cloning Judy1 Array */

		/* Key/index */
		Word_t  kindex = 0;

		/* Insert return value */
		int     Rc_int = 0;

		J1F(Rc_int, old_obj->array, kindex);
		while (Rc_int == 1)
		{
			J1S(Rc_int, newJArray, kindex);
			J1N(Rc_int, newJArray, kindex);
		}
	} else if (old_obj->type == TYPE_INT_TO_INT || old_obj->type == TYPE_INT_TO_MIXED) {
		/* Cloning JudyL Array */

		/* Key/index */
		Word_t kindex = 0;

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		JLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JLI(newPValue, newJArray, kindex);
			if (newPValue != NULL && newPValue != PJERR) {
				*newPValue = *PValue;
				if (old_obj->type == TYPE_INT_TO_MIXED) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_DUP(value, *(zval **)PValue);
					*newPValue = value;
				}
			}
			JLN(PValue, old_obj->array, kindex)
		}
	} else if (old_obj->type == TYPE_STRING_TO_INT || old_obj->type == TYPE_STRING_TO_MIXED) {
		/* Cloning JudySL Array */

		/* Key/index */
		uint8_t kindex[PHP_JUDY_MAX_LENGTH];

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		/* smallest string is a null-terminated character */
		kindex[0] = '\0';

		JSLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JSLI(newPValue, newJArray, kindex);
			if (newPValue != NULL && newPValue != PJERR) {
				*newPValue = *PValue;
				if (old_obj->type == TYPE_STRING_TO_MIXED) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_DUP(value, *(zval **)PValue);
					*newPValue = value;
				}
			}
			JSLN(PValue, old_obj->array, kindex)
		}
	}

	new_obj->array = newJArray;
	new_obj->type = old_obj->type;

	return &new_obj->std;
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
