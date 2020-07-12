--TEST--
Check for Judy infinite loop when using foreach() and INT_TO_INT with index 0 and -1
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
Ref. https://github.com/orieg/php-judy/issues/3
*/
$judy = new Judy(Judy::INT_TO_INT);
echo "Set INT_TO_INT Judy object\n";
$judy[1]  = 457;
$judy[0]  = 456;
$judy[-1]  = 123;
$judy[-2]  = 122;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);
?>
--EXPECT--
Set INT_TO_INT Judy object
k: 0, v: 456
k: 1, v: 457
k: 2, v: 123
k: 3, v: 122
