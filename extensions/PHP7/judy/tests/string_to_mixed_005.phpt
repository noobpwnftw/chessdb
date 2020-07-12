--TEST--
Check for Judy STRING_TO_MIXED works with $a[] = $b (expect fatal error)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::STRING_TO_MIXED);
$judy[] = 1;

echo "Done\n";
?>
--EXPECTF--
Fatal error: main(): Judy STRING_TO_INT and STRING_TO_MIXED values cannot be set without key specifying in %s
