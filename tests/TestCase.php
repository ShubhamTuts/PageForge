<?php
namespace PageForge\Tests;

final class TestCase {
    public static function same($expected, $actual, string $message = ''): void {
        if ($expected !== $actual) {
            throw new \RuntimeException($message !== '' ? $message : sprintf(
                "Expected %s, got %s",
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public static function true($actual, string $message = ''): void {
        self::same(true, $actual, $message);
    }
}
