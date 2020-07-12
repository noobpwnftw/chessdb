--TEST--
Check for Judy ITERATOR using foreach() and INT_TO_MIXED
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--INI--
date.timezone=UTC
--FILE--
<?php
/*
Ref. https://github.com/orieg/php-judy/issues/2
*/
$judy = new Judy(Judy::INT_TO_MIXED);
echo "Set INT_TO_MIXED Judy object\n";
$judy[125]  = new DateTime('2012-02-14');
$judy[521]  = new DateTime('1983-07-01');

foreach($judy as $k=>$v)
    print "k: $k, v: ".$v->format('Y')."\n";

unset($judy);
?>
--EXPECT--
Set INT_TO_MIXED Judy object
k: 125, v: 2012
k: 521, v: 1983
