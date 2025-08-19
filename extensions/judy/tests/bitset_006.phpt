--TEST--
Check if Judy BITSET works with $a[] = $b
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php
$judy = new Judy(Judy::BITSET);

echo "Insert 5 values\n";
for ($i=0; $i<5; $i++) {
    $judy[] = 1;
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
k: 1, v: 1
k: 2, v: 1
k: 3, v: 1
k: 4, v: 1
Done
