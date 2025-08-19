--TEST--
Check for Judy count() method when two instances STRING_TO_MIXED are declared
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
/*
Ref. https://github.com/orieg/php-judy/issues/1
*/

echo "Instantiate first object: \$judy1\n";
$judy1 = new Judy(Judy::STRING_TO_MIXED);
echo "Instantiate second object: \$judy2\n";
$judy2 = new Judy(Judy::STRING_TO_MIXED);

echo "Test Size Zero\n";
echo "\$judy1->count(): ". $judy1->count()."\n";
echo "\$judy2->count(): ". $judy2->count()."\n";

echo "Test Size Consistent\n";
$judy1->offsetSet("foo", "bar");
$judy1->offsetSet("another", "value");

echo "\$judy1->count(): ". $judy1->count()."\n";
echo "\$judy2->count(): ". $judy2->count()."\n";

echo "Test Size Sum\n";
$judy2->offsetSet("third", "..");
echo "\$judy1->count(): ". $judy1->count()."\n";
echo "\$judy2->count(): ". $judy2->count()."\n";

unset($judy1);
unset($judy2);

?>
--EXPECT--
Instantiate first object: $judy1
Instantiate second object: $judy2
Test Size Zero
$judy1->count(): 0
$judy2->count(): 0
Test Size Consistent
$judy1->count(): 2
$judy2->count(): 0
Test Size Sum
$judy1->count(): 2
$judy2->count(): 1
