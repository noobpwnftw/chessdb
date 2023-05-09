# Minimum CMake version
cmake_minimum_required(VERSION 3.15)
project(fqdb LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Set default build type to Release if not specified
if(NOT CMAKE_BUILD_TYPE AND NOT CMAKE_CONFIGURATION_TYPES)
  message(STATUS "No build type specified. Setting to Release by default.")
  set(CMAKE_BUILD_TYPE "Release" CACHE STRING "Choose the type of build." FORCE)

  # Provide options for CMAKE_BUILD_TYPE
  set_property(CACHE CMAKE_BUILD_TYPE PROPERTY STRINGS "Debug" "Release" "RelWithDebInfo" "MinSizeRel")
endif()

add_subdirectory(abseil-cpp)

add_executable(fqdb
    src/main.cpp
)

target_include_directories(fqdb PRIVATE ${CMAKE_SOURCE_DIR})

find_package(Threads REQUIRED)
target_link_libraries(fqdb PRIVATE Threads::Threads jemalloc absl::btree)

target_compile_options(fqdb PRIVATE
    $<$<CONFIG:Release>:-O3 -fomit-frame-pointer>
)

if(UNIX AND NOT APPLE)
  find_library(RT_LIB rt)
  if(RT_LIB)
    target_link_libraries(fqdb PRIVATE ${RT_LIB})
  endif()
endif()
