#ifndef PHP_CBOARD_H
#define PHP_CBOARD_H

extern zend_module_entry cboard_module_entry;
#define phpext_cboard_ptr &cboard_module_entry

PHP_MINIT_FUNCTION(cboard);
PHP_FUNCTION(cbgetfen);
PHP_FUNCTION(cbmovegen);
PHP_FUNCTION(cbmovemake);
PHP_FUNCTION(cbincheck);

PHP_FUNCTION(cbgetBWfen);
PHP_FUNCTION(cbgetBWmove);

PHP_FUNCTION(cbfen2hexfen);
PHP_FUNCTION(cbhexfen2fen);

#endif
