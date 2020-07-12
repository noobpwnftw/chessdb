--TEST--
Check for Judy INT_TO_INT with unsigned and signed INT
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_INT);

$judy[-2] = 9;
$judy[0] = 0;
$judy[1] = 17;

$judy[-1] = -17;
if ($judy[2] == -17)
    echo "\$judy[-1] set value for latest index, ie \$judy[2]\n";
else
    echo "\$judy[2] should be equal to -17 but got ${judy[2]}\n";

$judy[2] = -12;
if ($judy[2] == -12)
    echo "\$judy[2] has been reset to -12\n";
else
    echo "\$judy[2] should be equal to -12 but got ${judy[2]}\n";

$judy[3] = 8;
$judy[4] = -19;
$judy[-5] = 6;
$judy[-6] = 7;

print "Loop on Judy array with uint/int\n";
foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";
echo "Done\n";
?>
--EXPECT--
$judy[-1] set value for latest index, ie $judy[2]
$judy[2] has been reset to -12
Loop on Judy array with uint/int
k: 0, v: 0
k: 1, v: 17
k: 2, v: -12
k: 3, v: 8
k: 4, v: -19
k: 5, v: 6
k: 6, v: 7
Done
