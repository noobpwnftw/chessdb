--TEST--
Check for Judy INT_TO_INT free/count/byCount methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::INT_TO_INT);

// Init array

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        $judy[$i] = $value;
        if (!$judy[$i])
            echo "Failed to insert index $i (value: $value)\n";
}

// Count

echo "Half count: ".$judy->count(0, 49)."\n";
echo "Count Method: ".$judy->count()."\n";
echo "Count Function: ".count($judy)."\n";

unset($judy[50]);
if (!$judy[50])
    echo "Unset index 50\n";

echo "First half count: ".$judy->count(0, 49)."\n";
echo "Second half count: ".$judy->count(50, 100)."\n";
echo "Count Method: ".$judy->count()."\n";
echo "Count Function: ".count($judy)."\n";

// By count

if (($index = $judy->byCount(50)) !== null)
    echo "By count (50th): $index\n";
else
    echo "By count (50th set index) failed\n";

if (($index = $judy->byCount(51)) !== null)
    echo "By count (51th): $index\n";
else
    echo "By count (51th set index) failed\n";

// Free

if (!$judy->free())
    echo "Failed to free Judy array\n";
else
    echo "Freeing Judy array\n";

echo "Count: ".$judy->count()."\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Half count: 50
Count Method: 100
Count Function: 100
Unset index 50
First half count: 50
Second half count: 49
Count Method: 99
Count Function: 99
By count (50th): 49
By count (51th): 51
Freeing Judy array
Count: 0
Done
