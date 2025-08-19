--TEST--
Check for Judy ITERATOR using foreach() and STRING_TO_MIXED
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::STRING_TO_MIXED);

echo "Set 3 index\n";
$judy["one"] = 100;
$judy["two"] = "AAA";
$judy["three"] = 102;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);

?>
--EXPECT--
Set 3 index
k: one, v: 100
k: three, v: 102
k: two, v: AAA
