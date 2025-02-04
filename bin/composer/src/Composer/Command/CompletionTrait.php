<?php declare(strict_types=1);











namespace Composer\Command;

use Composer\Composer;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RootPackageRepository;
use Symfony\Component\Console\Completion\CompletionInput;






trait CompletionTrait
{



abstract public function requireComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): Composer;






private function suggestPreferInstall(): array
{
return ['dist', 'source', 'auto'];
}




private function suggestRootRequirement(): \Closure
{
return function (CompletionInput $input): array {
$composer = $this->requireComposer();

return array_merge(array_keys($composer->getPackage()->getRequires()), array_keys($composer->getPackage()->getDevRequires()));
};
}




private function suggestInstalledPackage(bool $includeRootPackage = true, bool $includePlatformPackages = false): \Closure
{
return function (CompletionInput $input) use ($includeRootPackage, $includePlatformPackages): array {
$composer = $this->requireComposer();
$installedRepos = [];

if ($includeRootPackage) {
$installedRepos[] = new RootPackageRepository(clone $composer->getPackage());
}

$locker = $composer->getLocker();
if ($locker->isLocked()) {
$installedRepos[] = $locker->getLockedRepository(true);
} else {
$installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
}

$platformHint = [];
if ($includePlatformPackages) {
if ($locker->isLocked()) {
$platformRepo = new PlatformRepository([], $locker->getPlatformOverrides());
} else {
$platformRepo = new PlatformRepository([], $composer->getConfig()->get('platform'));
}
if ($input->getCompletionValue() === '') {

$hintsToFind = ['ext-' => 0, 'lib-' => 0, 'php' => 99, 'composer' => 99];
foreach ($platformRepo->getPackages() as $pkg) {
foreach ($hintsToFind as $hintPrefix => $hintCount) {
if (str_starts_with($pkg->getName(), $hintPrefix)) {
if ($hintCount === 0 || $hintCount >= 99) {
$platformHint[] = $pkg->getName();
$hintsToFind[$hintPrefix]++;
} elseif ($hintCount === 1) {
unset($hintsToFind[$hintPrefix]);
$platformHint[] = substr($pkg->getName(), 0, max(strlen($pkg->getName()) - 3, strlen($hintPrefix) + 1)).'...';
}
continue 2;
}
}
}
} else {
$installedRepos[] = $platformRepo;
}
}

$installedRepo = new InstalledRepository($installedRepos);

return array_merge(
array_map(static function (PackageInterface $package) {
return $package->getName();
}, $installedRepo->getPackages()),
$platformHint
);
};
}




private function suggestInstalledPackageTypes(bool $includeRootPackage = true): \Closure
{
return function (CompletionInput $input) use ($includeRootPackage): array {
$composer = $this->requireComposer();
$installedRepos = [];

if ($includeRootPackage) {
$installedRepos[] = new RootPackageRepository(clone $composer->getPackage());
}

$locker = $composer->getLocker();
if ($locker->isLocked()) {
$installedRepos[] = $locker->getLockedRepository(true);
} else {
$installedRepos[] = $composer->getRepositoryManager()->getLocalRepository();
}

$installedRepo = new InstalledRepository($installedRepos);

return array_values(array_unique(
array_map(static function (PackageInterface $package) {
return $package->getType();
}, $installedRepo->getPackages())
));
};
}




private function suggestAvailablePackage(int $max = 99): \Closure
{
return function (CompletionInput $input) use ($max): array {
if ($max < 1) {
return [];
}

$composer = $this->requireComposer();
$repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());

$results = [];
$showVendors = false;
if (!str_contains($input->getCompletionValue(), '/')) {
$results = $repos->search('^' . preg_quote($input->getCompletionValue()), RepositoryInterface::SEARCH_VENDOR);
$showVendors = true;
}


if (\count($results) <= 1) {
$results = $repos->search('^'.preg_quote($input->getCompletionValue()), RepositoryInterface::SEARCH_NAME);
$showVendors = false;
}

$results = array_column($results, 'name');

if ($showVendors) {
$results = array_map(static function (string $name): string {
return $name.'/';
}, $results);


usort($results, static function (string $a, string $b) {
$lenA = \strlen($a);
$lenB = \strlen($b);
if ($lenA === $lenB) {
return $a <=> $b;
}

return $lenA - $lenB;
});

$pinned = [];


$completionInput = $input->getCompletionValue().'/';
if (false !== ($exactIndex = array_search($completionInput, $results, true))) {
$pinned[] = $completionInput;
array_splice($results, $exactIndex, 1);
}

return array_merge($pinned, array_slice($results, 0, $max - \count($pinned)));
}

return array_slice($results, 0, $max);
};
}





private function suggestAvailablePackageInclPlatform(): \Closure
{
return function (CompletionInput $input): array {
if (Preg::isMatch('{^(ext|lib|php)(-|$)|^com}', $input->getCompletionValue())) {
$matches = $this->suggestPlatformPackage()($input);
} else {
$matches = [];
}

return array_merge($matches, $this->suggestAvailablePackage(99 - \count($matches))($input));
};
}




private function suggestPlatformPackage(): \Closure
{
return function (CompletionInput $input): array {
$repos = new PlatformRepository([], $this->requireComposer()->getConfig()->get('platform'));

$pattern = BasePackage::packageNameToRegexp($input->getCompletionValue().'*');

return array_filter(array_map(static function (PackageInterface $package) {
return $package->getName();
}, $repos->getPackages()), static function (string $name) use ($pattern): bool {
return Preg::isMatch($pattern, $name);
});
};
}
}
