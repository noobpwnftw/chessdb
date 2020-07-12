#ifndef PHP_XXHASH_H
#define PHP_XXHASH_H

#define PHP_XXHASH_VERSION "1.0.1"

extern zend_module_entry xxhash_module_entry;
#define phpext_xxhash_ptr &xxhash_module_entry

#if defined(PHP_WIN32) && defined(XXHASH_EXPORTS)
#define PHP_XXHASH_API __declspec(dllexport)
#else
#define PHP_XXHASH_API PHPAPI
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

PHP_MINIT_FUNCTION(xxhash);
PHP_MSHUTDOWN_FUNCTION(xxhash);
PHP_RINIT_FUNCTION(xxhash);
PHP_RSHUTDOWN_FUNCTION(xxhash);
PHP_MINFO_FUNCTION(xxhash);

PHP_FUNCTION(xxhash32);
PHP_FUNCTION(xxhash64);
PHP_FUNCTION(xxhash64Unsigned);

#endif /* PHP_XXHASH_H */

