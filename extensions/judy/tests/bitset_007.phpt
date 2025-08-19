--TEST--
Check if Judy BITSET works with $a[] = $b (2)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::BITSET);

echo "Insert 5 values\n";
for ($i=0; $i<5; $i++) {
    $judy[] = 1;
	$judy[100] = 1;
	$judy[10] = 1;
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
k: 0, v: 1
k: 10, v: 1
k: 100, v: 1
k: 101, v: 1
k: 102, v: 1
k: 103, v: 1
k: 104, v: 1
Done
