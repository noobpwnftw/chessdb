# Minimum CMake version
cmake_minimum_required(VERSION 3.15)
project(fqdb LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_POSITION_INDEPENDENT_CODE ON)

add_executable(fqdb
    src/main.cpp
)

target_include_directories(fqdb PRIVATE ${CMAKE_SOURCE_DIR})

find_package(Threads REQUIRED)
target_link_libraries(fqdb PRIVATE Threads::Threads jemalloc)

if(UNIX AND NOT APPLE)
  find_library(RT_LIB rt)
  if(RT_LIB)
    target_link_libraries(fqdb PRIVATE ${RT_LIB})
  endif()
endif()
