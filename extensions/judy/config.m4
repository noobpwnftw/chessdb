dnl
dnl $Id$
dnl

PHP_ARG_WITH(judy, for Judy support,
[  --with-judy[=DIR]       Include Judy support.
                          DIR is the Judy install prefix [default=BUNDLED]])

judy_sources="judy_handlers.c judy_arrayaccess.c judy_iterator.c"

if test "$PHP_JUDY" != "no"; then

  dnl # --with-judy -> check with-path
  SEARCH_PATH="/usr/local /usr"
  SEARCH_FOR="/include/Judy.h"
  AC_MSG_CHECKING([for Judy library to use])
  if test -r $PHP_JUDY/$SEARCH_FOR; then
    JUDY_DIR=$PHP_JUDY
  else
    for i in $SEARCH_PATH ; do
      if test -r $i/$SEARCH_FOR; then
        JUDY_DIR=$i
      fi
    done
  fi

  if test -z "$JUDY_DIR"; then
    AC_MSG_RESULT([not found])
    AC_MSG_ERROR([Please install the Judy libraries])
  else
    AC_MSG_RESULT([found in $JUDY_DIR])
  fi

  PHP_ADD_INCLUDE($JUDY_DIR/include)

  dnl # --with-judy -> check for lib and symbol presence
  LIBNAME=Judy
  LIBSYMBOL=Judy1Set

  PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
  [
    PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $JUDY_DIR/$PHP_LIBDIR, JUDY_SHARED_LIBADD)
    AC_DEFINE(HAVE_JUDYLIB,1,[ ])
  ],[
    AC_MSG_ERROR([wrong Judy lib version or lib not found using -L$JUDY_DIR/$PHP_LIBDIR -lJudy])
  ],[
    -L$JUDY_DIR/$PHP_LIBDIR -lJudy
  ])

  PHP_INSTALL_HEADERS([ext/judy], [php_judy.h judy_handlers.h judy_arrayaccess.h judy_iterator.h])
  PHP_NEW_EXTENSION(judy, php_judy.c $judy_sources, $ext_shared)
  PHP_ADD_BUILD_DIR($ext_builddir/lib, 1)
  PHP_SUBST(JUDY_SHARED_LIBADD)

fi
