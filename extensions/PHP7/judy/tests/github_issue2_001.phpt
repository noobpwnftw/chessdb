--TEST--
Check for Judy with foreach() and vardump() method with one instance INT_TO_INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
/*
Ref. https://github.com/orieg/php-judy/issues/2
*/

echo "Instantiate object: \$judy\n";
$judy       = new Judy(Judy::INT_TO_INT);
$judy[125]  = 17; 
$judy[521]  = 71; 

echo "echo values\n";
echo "${judy[125]}\n";
echo "${judy[521]}\n";

echo "var_dump(\$judy)\n";
var_dump($judy);

echo "var_dump(\$judy[125])\n";
var_dump($judy[125]);

echo "foreach loop with var_dump()\n";
foreach ($judy as $k => $v) {
    echo "$k => $v\n";
    var_dump($v);
}

unset($judy);
?>
--EXPECT--
Instantiate object: $judy
echo values
17
71
var_dump($judy)
object(Judy)#1 (0) {
}
var_dump($judy[125])
int(17)
foreach loop with var_dump()
125 => 17
int(17)
521 => 71
int(71)
