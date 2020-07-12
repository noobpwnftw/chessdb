--TEST--
Check for Judy BITSET set/unset/test methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::BITSET);

// Set

echo "Set 100 index\n";
for ($i=0; $i<100; $i++) {
        $judy[$i] = true;
        if(!$judy[$i])
            echo "Failed to set index $i\n";
}

// Test index set

echo "Test 100 index are set\n";
for ($i=0; $i<100; $i++) {
        if(!$judy[$i])
            echo "Test index $i returned false\n";
}

// Unset

echo "Unset 100 index\n";
for ($i=0; $i<100; $i++) {
        unset($judy[$i]);
        if ($judy[$i])
            echo "Failed to unset index $i\n";
}

// Test index unset

echo "Test 100 index are not set\n";
for ($i=0; $i<100; $i++) {
        if($judy[$i])
            echo "Test index $i returned true\n";
}

echo "Done\n";
?>
--EXPECT--
Set 100 index
Test 100 index are set
Unset 100 index
Test 100 index are not set
Done
