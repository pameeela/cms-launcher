<?php declare(strict_types=1);











namespace Composer\Util;

use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\DownloadManager;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

class SyncHelper
{











public static function downloadAndInstallPackageSync(Loop $loop, $downloader, string $path, PackageInterface $package, ?PackageInterface $prevPackage = null): void
{
assert($downloader instanceof DownloaderInterface || $downloader instanceof DownloadManager);

$type = $prevPackage !== null ? 'update' : 'install';

try {
self::await($loop, $downloader->download($package, $path, $prevPackage));

self::await($loop, $downloader->prepare($type, $package, $path, $prevPackage));

if ($type === 'update' && $prevPackage !== null) {
self::await($loop, $downloader->update($package, $prevPackage, $path));
} else {
self::await($loop, $downloader->install($package, $path));
}
} catch (\Exception $e) {
self::await($loop, $downloader->cleanup($type, $package, $path, $prevPackage));
throw $e;
}

self::await($loop, $downloader->cleanup($type, $package, $path, $prevPackage));
}







public static function await(Loop $loop, ?PromiseInterface $promise = null): void
{
if ($promise !== null) {
$loop->wait([$promise]);
}
}
}
