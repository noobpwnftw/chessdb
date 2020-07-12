--TEST--
Check for Judy ITERATOR using foreach() and BITSET
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);
echo "Set 10 index\n";
for ($i=0; $i<10; $i++) {
        $judy[$i] = true;
        if(!$judy[$i])
            echo "Failed to set index $i\n";
}

unset($judy[5]);
if (!$judy[5])
    echo "Unset index 5\n";

echo "Append TRUE to the array using last index + 1\n";
$judy[] = true;

foreach($judy as $k=>$v)
    print "k: $k, v: $v\n";

unset($judy);

?>
--EXPECT--
Set 10 index
Unset index 5
Append TRUE to the array using last index + 1
k: 0, v: 1
k: 1, v: 1
k: 2, v: 1
k: 3, v: 1
k: 4, v: 1
k: 6, v: 1
k: 7, v: 1
k: 8, v: 1
k: 9, v: 1
k: 10, v: 1
