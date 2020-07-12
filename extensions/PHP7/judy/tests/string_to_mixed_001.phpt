--TEST--
Check for Judy STRING_TO_MIXED set/unset/get methods
--SKIPIF--
<?php if (!extension_loaded("judy")) print "skip"; ?>
--FILE--
<?php 
$judy = new Judy(Judy::STRING_TO_MIXED);

// Insert 

echo "Insert 100 index with a rand value\n";
for ($i=0; $i<100; $i++) {
        $value = rand();
        $judy["$i"] = "$value";
        if(!$judy["$i"])
            echo "Failed to insert index $i (value: $value)\n";
}

// Get

echo "Get 100 index\n";
for ($i=0; $i<100; $i++) {
        if($judy["$i"] === null)
            echo "Get index $i returned null\n";
}

// Remove

echo "Remove 100 index\n";
for ($i=0; $i<100; $i++) {
        unset($judy["$i"]);
        if($judy["$i"])
            echo "Failed to remove index $i\n";
}

// Get

echo "Get 100 index (should be empty)\n";
for ($i=0; $i<100; $i++) {
        if(($v = $judy["$i"]) !== null)
            echo "Get index $i returned $v\n";
}

echo "Testing values are properly inserted and returned\n";
$judy["test string index"] = 987;
$v = $judy["test string index"];
if ($v != 987)
    echo "Value doesn't match to the one inserted (expected 987 got $v)\n";

echo "Done\n";
?>
--EXPECT--
Insert 100 index with a rand value
Get 100 index
Remove 100 index
Get 100 index (should be empty)
Testing values are properly inserted and returned
Done
