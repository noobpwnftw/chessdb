--TEST--
Check for Judy INT_TO_INT works with $a[] = $b (2)
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::INT_TO_INT);

echo "Insert 5 values\n";
for ($i=0; $i<5; $i++) {
    $judy[] = $i;
	$judy[100] = $i;
	$judy[10] = $i;
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
k: 0, v: 0
k: 10, v: 4
k: 100, v: 4
k: 101, v: 1
k: 102, v: 2
k: 103, v: 3
k: 104, v: 4
Done
