--TEST--
Check for Error with Judy::__construct() when object has been already instantiated
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);
$judy->__construct(Judy::INT_TO_INT);
?>
--EXPECTF--
Fatal error: Judy::__construct(): Judy Array already instantiated in %s
