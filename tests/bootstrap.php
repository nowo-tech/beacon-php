<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Live parity tests: map BeaconBundle's src/ (read-only checkout) when BEACON_BUNDLE_SRC points at it.
$bundleSrc = getenv('BEACON_BUNDLE_SRC');
if (is_string($bundleSrc) && $bundleSrc !== '' && is_dir($bundleSrc)) {
    spl_autoload_register(static function (string $class) use ($bundleSrc): void {
        $prefix = 'Nowo\\BeaconBundle\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $bundleSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}
