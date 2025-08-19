--TEST--
Check for Judy BITSET __clone() method
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);
$judy[1] = true;

$judy2 = clone $judy;

var_dump($judy[1]);
var_dump($judy2[1]);

if ($judy == $judy2)
    echo "Clone OK\n";
else
    echo "Clone NOK\n";

echo "Done\n";
?>
--EXPECT--
bool(true)
bool(true)
Clone OK
Done
