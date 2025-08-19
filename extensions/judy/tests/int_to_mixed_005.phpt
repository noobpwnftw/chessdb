--TEST--
Check for Judy INT_TO_MIXED __clone() method
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_MIXED);
$judy[1] = "hello world";

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
string(11) "hello world"
string(11) "hello world"
Clone OK
Done
