--TEST--
strings as values in Judy  
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

echo "BITSET\n";

$a = new Judy(Judy::BITSET);
$a[1] = "1"; 
$a[2] = "0"; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "INT_TO_INT\n";
$a = new Judy(Judy::INT_TO_INT);
$a[0] = "1";
$a[1] = "0"; 
$a[2] = "012"; 
$a[3] = "789"; 
$a[4] = "-5"; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "INT_TO_MIXED\n";
$a = new Judy(Judy::INT_TO_MIXED);
$a[0] = "1";
$a[1] = "0"; 
$a[2] = "012"; 
$a[3] = "789"; 
$a[4] = "-5"; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "STRING_TO_MIXED\n";
$a = new Judy(Judy::STRING_TO_MIXED);
$a[0] = "1";
$a[1] = "0"; 
$a[2] = "012"; 
$a[3] = "789"; 
$a[4] = "-5"; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

?>
--EXPECTF--
BITSET
int(1)
bool(true)
INT_TO_INT
int(0)
int(1)
int(1)
int(0)
int(2)
int(12)
int(3)
int(789)
int(4)
int(-5)
INT_TO_MIXED
int(0)
string(1) "1"
int(1)
string(1) "0"
int(2)
string(3) "012"
int(3)
string(3) "789"
int(4)
string(2) "-5"
STRING_TO_MIXED
string(1) "0"
string(1) "1"
string(1) "1"
string(1) "0"
string(1) "2"
string(3) "012"
string(1) "3"
string(3) "789"
string(1) "4"
string(2) "-5"
