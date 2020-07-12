--TEST--
Check for Judy STRING_TO_MIXED first/next/last/prev methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::STRING_TO_MIXED);

// Init array

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        $judy["$i"] = "$value";
        if(!$judy["$i"])
            echo "Failed to insert index $i (value: $value)\n";
}

// First

$firstIndexDefault = $judy->first();
echo "First index set: $firstIndexDefault\n";

$firstIndex50 = $judy->first("50");
echo "First index set from index 50: $firstIndex50\n";

// Last

$lastIndexDefault = $judy->last();
echo "Last index set: $lastIndexDefault\n";

$lastIndex1000 = $judy->last("1000");
echo "Last index set from index 1000: $lastIndex1000\n";

// Next

echo "Testing next()\n";
$index = $firstIndexDefault;
while ($index < $lastIndexDefault) {
    $parent_index = $index;
    $index = $judy->next($parent_index);
    if ($index === null) {
        echo "Failed to get next index from parent index ($parent_index)\n";
        break;
    }
}

// Prev

echo "Testing prev()\n";
$index = $lastIndexDefault;
while ($index > $firstIndexDefault) {
    $parent_index = $index;
    $index = $judy->prev($parent_index);
    if ($index === null) {
        echo "Failed to get previous index from parent index ($parent_index)\n";
        break;
    }
}

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
First index set: 0
First index set from index 50: 50
Last index set: 99
Last index set from index 1000: 10
Testing next()
Testing prev()
Done
