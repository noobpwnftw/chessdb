--TEST--
strings as keys in Judy  
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php

echo "BITSET\n";

$a = new Judy(Judy::BITSET);
$a[""] = 1; 
$a["test"] = 1; 
$a["012"] = 1; 
$a["789"] = 1; 
$a["-1"] = 1; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "INT_TO_INT\n";
$a = new Judy(Judy::INT_TO_INT);
$a[""] = 1; 
$a["test"] = 1; 
$a["012"] = 1; 
$a["789"] = 1; 
$a["-1"] = 1; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "INT_TO_MIXED\n";
$a = new Judy(Judy::INT_TO_MIXED);
$a[""] = 1; 
$a["test"] = 1; 
$a["012"] = 1; 
$a["789"] = 1; 
$a["-1"] = 1; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

echo "STRING_TO_MIXED\n";
$a = new Judy(Judy::STRING_TO_MIXED);
$a[""] = 1; 
$a["test"] = 1; 
$a["012"] = 1; 
$a["789"] = 1; 
$a["-1"] = 1; 

foreach($a as $key=>$val) {
	var_dump($key, $val);
}

?>
--EXPECTF--
BITSET
int(0)
bool(true)
int(12)
bool(true)
int(789)
bool(true)
int(790)
bool(true)
INT_TO_INT
int(0)
int(1)
int(12)
int(1)
int(789)
int(1)
int(790)
int(1)
INT_TO_MIXED
int(0)
int(1)
int(12)
int(1)
int(789)
int(1)
int(790)
int(1)
STRING_TO_MIXED
string(0) ""
int(1)
string(2) "-1"
int(1)
string(3) "012"
int(1)
string(3) "789"
int(1)
string(4) "test"
int(1)
