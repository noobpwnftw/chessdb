--TEST--
Check for Judy TYPES using Judy::getType and judy_type()
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);
if ($judy->getType() === judy_type($judy) &&
    $judy->getType() === Judy::BITSET) {
    echo "Judy BITSET type OK\n";
} else {
    echo "Judy BITSET type check fail\n";
}
unset($judy);

$judy = new Judy(Judy::INT_TO_INT);
if ($judy->getType() === judy_type($judy) &&
    $judy->getType() === Judy::INT_TO_INT) {
    echo "Judy INT_TO_INT type OK\n";
} else {
    echo "Judy INT_TO_INT type check fail\n";
}
unset($judy);

$judy = new Judy(Judy::INT_TO_MIXED);
if ($judy->getType() === judy_type($judy) &&
    $judy->getType() === Judy::INT_TO_MIXED) {
    echo "Judy INT_TO_MIXED type OK\n";
} else {
    echo "Judy INT_TO_MIXED type check fail\n";
}
unset($judy);

$judy = new Judy(Judy::STRING_TO_INT);
if ($judy->getType() === judy_type($judy) &&
    $judy->getType() === Judy::STRING_TO_INT) {
    echo "Judy STRING_TO_INT type OK\n";
} else {
    echo "Judy STRING_TO_INT type check fail\n";
}
unset($judy);

$judy = new Judy(Judy::STRING_TO_MIXED);
if ($judy->getType() === judy_type($judy) &&
    $judy->getType() === Judy::STRING_TO_MIXED) {
    echo "Judy STRING_TO_MIXED type OK\n";
} else {
    echo "Judy STRING_TO_MIXED type check fail\n";
}
unset($judy);

?>
--EXPECT--
Judy BITSET type OK
Judy INT_TO_INT type OK
Judy INT_TO_MIXED type OK
Judy STRING_TO_INT type OK
Judy STRING_TO_MIXED type OK
