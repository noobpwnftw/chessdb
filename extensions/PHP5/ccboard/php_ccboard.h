#ifndef PHP_CCBOARD_H
#define PHP_CCBOARD_H

extern zend_module_entry ccboard_module_entry;
#define phpext_ccboard_ptr &ccboard_module_entry

PHP_MINIT_FUNCTION(ccboard);
PHP_FUNCTION(ccbgetfen);
PHP_FUNCTION(ccbmovegen);
PHP_FUNCTION(ccbmovemake);
PHP_FUNCTION(ccbincheck);

PHP_FUNCTION(ccbgetLRfen);
PHP_FUNCTION(ccbgetBWfen);
PHP_FUNCTION(ccbgetLRBWfen);

PHP_FUNCTION(ccbgetLRmove);
PHP_FUNCTION(ccbgetBWmove);
PHP_FUNCTION(ccbgetLRBWmove);

PHP_FUNCTION(ccbfen2hexfen);
PHP_FUNCTION(ccbhexfen2fen);
#endif
