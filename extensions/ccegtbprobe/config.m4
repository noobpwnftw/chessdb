PHP_ARG_ENABLE(ccegtbprobe, whether to enable CCEGTBProbe,
[  --enable-ccegtbprobe   Enable CCEGTBProbe extension])

if test "$PHP_CCEGTBPROBE" != "no"; then
  AC_DEFINE(HAVE_CCEGTBPROBE,1,[ ])
  PHP_REQUIRE_CXX()
  PHP_ADD_LIBRARY(stdc++, "", EXTRA_LDFLAGS)
  PHP_NEW_EXTENSION(ccegtbprobe, ccegtbprobe.cpp piece_set.cpp, $ext_shared)
fi
