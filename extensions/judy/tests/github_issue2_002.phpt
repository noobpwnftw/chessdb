--TEST--
Check for Judy ITERATOR using foreach() and INT_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
Ref. https://github.com/orieg/php-judy/issues/2
*/
$judy = new Judy(Judy::INT_TO_INT);
echo "Set INT_TO_INT Judy object\n";
$judy[125]  = 17;
$judy[521]  = 71;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);
?>
--EXPECT--
Set INT_TO_INT Judy object
k: 125, v: 17
k: 521, v: 71
