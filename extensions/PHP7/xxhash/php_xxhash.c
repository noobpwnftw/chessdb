#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "php_xxhash.h"

#include "xxhash.c"

#ifdef COMPILE_DL_XXHASH
ZEND_GET_MODULE(xxhash)
#endif

PHP_MINIT_FUNCTION(xxhash)
{
	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(xxhash)
{
	return SUCCESS;
}

PHP_RINIT_FUNCTION(xxhash)
{
	return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(xxhash)
{
	return SUCCESS;
}

PHP_MINFO_FUNCTION(xxhash)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "xxhash support", "enabled");
	php_info_print_table_row(2, "extension version", PHP_XXHASH_VERSION);
	php_info_print_table_row(2, "xxhash release", "http://code.google.com/p/xxhash/source/detail?r=6");
	php_info_print_table_end();
}

PHP_FUNCTION(xxhash32)
{
	char *arg1 = NULL;
	char *ret1 = NULL;
	size_t arg1_len;
	unsigned int sum;

	/* parse the parameters */
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &arg1, &arg1_len) == FAILURE || arg1_len < 1)
	{
		RETURN_NULL();
	}

	/* compute the checksum */
	sum = XXH32(arg1, arg1_len, 0);

	/* return the checksum */
	RETURN_LONG((long)sum);
}

PHP_FUNCTION(xxhash64)
{
	char *arg1 = NULL;
	char *ret1 = NULL;
	size_t arg1_len;
	unsigned long long sum;

	/* parse the parameters */
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &arg1, &arg1_len) == FAILURE || arg1_len < 1)
	{
		RETURN_NULL();
	}

	/* compute the checksum */
	sum = XXH64(arg1, arg1_len, 0);

	/* return the checksum */
	/* Negative values can be returned since we cannot return  unsigned long to php */
	RETURN_LONG(sum); 
}

PHP_FUNCTION(xxhash64Unsigned)
{
	char *arg1 = NULL;
	char *ret1 = NULL;
	size_t arg1_len;
	unsigned long long sum;

	/* parse the parameters */
	if (zend_parse_parameters(ZEND_NUM_ARGS() TSRMLS_CC, "s", &arg1, &arg1_len) == FAILURE || arg1_len < 1)
	{
		RETURN_NULL();
	}

	/* compute the checksum */
	sum = XXH64(arg1, arg1_len, 0);
	
	//Since php doesn't have unsinged long values, the value will be returned as string
	char numberAsAString[20];
	sprintf(numberAsAString, "%llu",sum);
	RETURN_STRING(numberAsAString);
}
zend_function_entry xxhash_functions[] = {
	PHP_FE(xxhash32, NULL)
	PHP_FE(xxhash64, NULL)
	PHP_FE(xxhash64Unsigned, NULL)
	{NULL, NULL, NULL}
};

zend_module_entry xxhash_module_entry = {
	STANDARD_MODULE_HEADER,
	"xxhash",
	xxhash_functions,
	PHP_MINIT(xxhash),
	PHP_MSHUTDOWN(xxhash),
	NULL,
	NULL,
	PHP_MINFO(xxhash),
	PHP_XXHASH_VERSION,
	STANDARD_MODULE_PROPERTIES
};

