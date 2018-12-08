dnl $Id$
dnl config.m4 for extension xxhash

dnl Comments in this file start with the string 'dnl'.
dnl Remove where necessary. This file will not work
dnl without editing.

dnl If your extension references something external, use with:

dnl PHP_ARG_WITH(xxhash, for xxhash support,
dnl Make sure that the comment is aligned:
dnl [  --with-xxhash             Include xxhash support])

dnl Otherwise use enable:

PHP_ARG_ENABLE(xxhash, whether to enable xxhash support,
dnl Make sure that the comment is aligned:
[  --enable-xxhash            Enable xxhash support])

if test "$PHP_XXHASH" != "no"; then
  dnl Write more examples of tests here...

  PHP_NEW_EXTENSION(xxhash, php_xxhash.c, $ext_shared)
fi
