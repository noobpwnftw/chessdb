<?php

ini_set("memory_limit", "128M");

function __convert($size)
{
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}

$count = array(100, 500, 1000, 5000, 10000, 50000, 100000, 500000);

foreach($count as $v) {
    echo "## Count: $v\n";
    echo "-- ARRAY \n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    $s=microtime(true);
    $array = array();
    for ($i=0; $i<$v; $i++)
        $array["$i"] = rand();
    unset($array["102"]);
    var_dump($array["100"]);
    var_dump($array["102"]);
    $e=microtime(true);
    echo "Count: ".count($array)."\n";
    echo "Elapsed time: ".($e - $s)." sec.\n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    unset($array);

    echo "\n-- Judy STRING_TO_INT \n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";

    $s=microtime(true);
    $judy = new Judy(Judy::STRING_TO_INT);
    for ($i=0; $i<$v; $i++)
        $judy["$i"] = rand();
    var_dump($judy[100]);
    unset($judy["102"]);
    var_dump($judy["102"]);
    echo "Size: ".$judy->size()."\n";
    $e=microtime(true);
    echo "Elapsed time: ".($e - $s)." sec.\n";
    echo "Mem usage: ". __convert(memory_get_usage()) . "\n";
    echo "Mem real: ". __convert(memory_get_usage(true)) . "\n";
    echo "\n";

    unset($judy);
}
