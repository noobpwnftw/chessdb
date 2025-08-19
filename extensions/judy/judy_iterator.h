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

#ifndef JUDY_ITERATOR_H
#define JUDY_ITERATOR_H

#include "php_judy.h"

#define JUDY_ITERATOR_GET_OBJECT \
	judy_iterator 	*it = (judy_iterator*) iterator; \
	zval			*intern = (zval*) &it->intern.data; \
	judy_object 	*object = php_judy_object(Z_OBJ_P(intern));

/* {{{ judy_iterator
 define an overloaded structure */
typedef struct {
	zend_object_iterator	intern;
	zval					key;
	zval					data;
} judy_iterator;
/* }}} */

/* judy_get_iterator */
zend_object_iterator *judy_get_iterator(zend_class_entry *ce, zval *object, int by_ref);

/* {{{ judy iterator handlers
 */
void judy_iterator_dtor(zend_object_iterator *iterator);
int judy_iterator_valid(zend_object_iterator *iterator);
zval *judy_iterator_current_data(zend_object_iterator *iterator);
void judy_iterator_current_key(zend_object_iterator *iterator, zval *key);
void judy_iterator_move_forward(zend_object_iterator *iterator);
void judy_iterator_rewind(zend_object_iterator *iterator);
/* }}} */

#endif /* JUDY_ITERATOR_H */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */
