# php-xxhash

PHP extension to add support for the [xxhash - r42](https://github.com/Cyan4973/xxHash) extremely fast hashing algorithm.  _xxhash_ is designed to be fast enough to use in real-time streaming applications.


## How To Install

```
   phpize
   ./configure --enable-xxhash
   make
   sudo make install
```

## How To Use

This extension adds three new PHP functions:

```
    // 32 bit version (all values are positive)
    int xxhash32(string $data);
    
    // 64 bit version (can return negative values since PHP doesn't support unsigned long values)
    long xxhash64(string $data);
    
    // 64 bit version (all values are positive but returned as strings)
    string xxhash64Unsigned(string $data);
```

They will checksum the string, and return the checksum.

## License

BSD 2-clause license.