--TEST--
Check for Judy STRING_TO_MIXED __clone() method
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::STRING_TO_MIXED);
$judy["myindex"] = "myvalue";

$judy2 = clone $judy;

var_dump($judy["myindex"]);
var_dump($judy2["myindex"]);

if ($judy == $judy2)
    echo "Clone OK\n";
else
    echo "Clone NOK\n";

echo "Done\n";
?>
--EXPECT--
string(7) "myvalue"
string(7) "myvalue"
Clone OK
Done
