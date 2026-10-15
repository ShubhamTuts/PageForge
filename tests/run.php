<?php

require_once __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $test_file) {
    require_once $test_file;
}

$failures = 0;
$functions = get_defined_functions();

foreach ($functions['user'] as $function) {
    if (strpos($function, 'test_pageforge_') !== 0) {
        continue;
    }

    try {
        $function();
        printf("PASS %s\n", $function);
    } catch (\Throwable $exception) {
        $failures++;
        printf("FAIL %s: %s\n", $function, $exception->getMessage());
    }
}

exit($failures > 0 ? 1 : 0);
