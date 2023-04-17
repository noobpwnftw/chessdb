#ifndef PHP_CCEGTBPROBE_H
#define PHP_CCEGTBPROBE_H

extern zend_module_entry ccegtbprobe_module_entry;
#define phpext_ccegtbprobe_ptr &ccegtbprobe_module_entry

PHP_MINIT_FUNCTION(ccegtbprobe);
PHP_FUNCTION(ccegtbprobe);

#endif
