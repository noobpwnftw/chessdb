--TEST--
Check for Judy INT_TO_INT firstEmpty/nextEmpty/lastEmpty/prevEmpty methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_INT);

// Init array

echo "Insert 500 index with a rand value\n";
for ($i=100; $i<600; $i++) {
        $value = rand();
        $judy[$i] = $value;
        if(!$judy[$i])
            echo "Failed to insert index $i (value: $value)\n";
}

$unset = array(150, 232, 346, 427, 589);
foreach($unset as $i) {
    echo "Delete index $i\n";
    unset($judy[$i]);
    if($judy[$i])
        echo "Failed to unset index $i\n";
}

// First Empty

$firstIndexDefault = $judy->firstEmpty();
echo "First empty index set: $firstIndexDefault\n";

$firstIndex50 = $judy->firstEmpty(50);
echo "First empty index set from index 50: $firstIndex50\n";

$firstIndex500 = $judy->firstEmpty(500);
echo "First empty index set from index 500: $firstIndex500\n";

// Last Empty

$lastIndexDefault = $judy->lastEmpty();
echo "Last empty index set: $lastIndexDefault\n";

$lastIndex1000 = $judy->lastEmpty(1000);
echo "Last empty index set from index 1000: $lastIndex1000\n";

$lastIndex500 = $judy->lastEmpty(500);
echo "Last empty index set from index 500: $lastIndex500\n";

// Next Empty

echo "Testing nextEmpty()\n";
$index = $firstIndexDefault;
while ($index < $lastIndex500) {
    $parent_index = $index;
    $index = $judy->nextEmpty($parent_index);
    if (empty($index) || $index < $firstIndexDefault) {
        echo "Failed to get next index from parent index ($parent_index)\n";
        break;
    }
}

// Prev Empty

echo "Testing prevEmpty()\n";
$index = 600;
while ($index > $firstIndexDefault) {
    $parent_index = $index;
    $index = $judy->prevEmpty($parent_index);
    if ($index < 0) {
        echo "Failed to get previous index from parent index ($parent_index)\n";
        break;
    }
}

echo "Done\n";
?>
--EXPECT--
Insert 500 index with a rand value
Delete index 150
Delete index 232
Delete index 346
Delete index 427
Delete index 589
First empty index set: 0
First empty index set from index 50: 50
First empty index set from index 500: 589
Last empty index set: -1
Last empty index set from index 1000: 1000
Last empty index set from index 500: 427
Testing nextEmpty()
Testing prevEmpty()
Done
