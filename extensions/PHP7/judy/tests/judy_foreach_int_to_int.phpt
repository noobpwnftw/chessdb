--TEST--
Check for Judy ITERATOR using foreach() and INT_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_INT);

echo "Set 3 index\n";
$judy[1] = 100;
$judy[2] = 101;
$judy[3] = 102;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);

?>
--EXPECT--
Set 3 index
k: 1, v: 100
k: 2, v: 101
k: 3, v: 102
