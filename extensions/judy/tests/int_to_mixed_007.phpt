--TEST--
Check for Judy INT_TO_MIXED works with $a[] = $b (2)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::INT_TO_MIXED);

echo "Insert 5 values\n";
for ($i=0; $i<5; $i++) {
    $judy[] = 'mix' . $i;
	$judy[100] = 'mix' . $i;
	$judy[10] = 'mix' . $i;
}

print "Loop on Judy array\n";
foreach($judy as $k=>$v) {
    print "k: $k, v: $v\n";
}
echo "Done\n";
?>
--EXPECT--
Insert 5 values
Loop on Judy array
k: 0, v: mix0
k: 10, v: mix4
k: 100, v: mix4
k: 101, v: mix1
k: 102, v: mix2
k: 103, v: mix3
k: 104, v: mix4
Done
