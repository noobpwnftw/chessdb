PHP_ARG_ENABLE(cboard, whether to enable CBoard,
[  --enable-cboard        Enable CBoard extension])

if test "$PHP_CBOARD" != "no"; then
  AC_DEFINE(HAVE_CBOARD,1,[ ])
  PHP_REQUIRE_CXX()
  PHP_ADD_LIBRARY(stdc++, "", EXTRA_LDFLAGS)
  PHP_NEW_EXTENSION(cboard, cboard.cpp, $ext_shared, , -std=c++17)
fi
