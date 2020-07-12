--TEST--
Check for Judy count() method when using INT_TO_INT in a loop
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
/*
Ref. https://github.com/orieg/php-judy/issues/15
*/

echo "Instantiate first object: \$judy1\n";
$judy1 = new Judy(Judy::INT_TO_INT);

echo "Assign a value while in a loop of 1\n";
for ($i = 0;$i < 1;$i++) {
    $judy1[5] = 100;
}
echo "\$judy1->count(): ". $judy1->size()."\n";

echo "Assign a value while in a loop of 1000\n";
for ($i = 0;$i < 1000;$i++) {
    $judy1[5] = 100;
}
echo "\$judy1->count(): ". $judy1->size()."\n";

foreach ($judy1 as $k => $v) {
    echo "\$judy1[".$k."] = ".$v."\n";
}

unset($judy1);

?>
--EXPECT--
Instantiate first object: $judy1
Assign a value while in a loop of 1
$judy1->count(): 1
Assign a value while in a loop of 1000
$judy1->count(): 1
$judy1[5] = 100
