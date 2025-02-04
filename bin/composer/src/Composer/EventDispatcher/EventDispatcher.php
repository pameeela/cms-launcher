<?php declare(strict_types=1);











namespace Composer\EventDispatcher;

use Composer\DependencyResolver\Transaction;
use Composer\Installer\InstallerEvent;
use Composer\IO\BufferIO;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\PartialComposer;
use Composer\Pcre\Preg;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Util\Platform;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Script;
use Composer\Installer\PackageEvent;
use Composer\Installer\BinaryInstaller;
use Composer\Util\ProcessExecutor;
use Composer\Script\Event as ScriptEvent;
use Composer\Autoload\ClassLoader;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\ExecutableFinder;














class EventDispatcher
{

protected $composer;

protected $io;

protected $loader;

protected $process;

protected $listeners = [];

protected $runScripts = true;

private $eventStack;








public function __construct(PartialComposer $composer, IOInterface $io, ?ProcessExecutor $process = null)
{
$this->composer = $composer;
$this->io = $io;
$this->process = $process ?? new ProcessExecutor($io);
$this->eventStack = [];
}






public function setRunScripts(bool $runScripts = true): self
{
$this->runScripts = $runScripts;

return $this;
}









public function dispatch(?string $eventName, ?Event $event = null): int
{
if (null === $event) {
if (null === $eventName) {
throw new \InvalidArgumentException('If no $event is passed in to '.__METHOD__.' you have to pass in an $eventName, got null.');
}
$event = new Event($eventName);
}

return $this->doDispatch($event);
}










public function dispatchScript(string $eventName, bool $devMode = false, array $additionalArgs = [], array $flags = []): int
{
assert($this->composer instanceof Composer, new \LogicException('This should only be reached with a fully loaded Composer'));

return $this->doDispatch(new Script\Event($eventName, $this->composer, $this->io, $devMode, $additionalArgs, $flags));
}













public function dispatchPackageEvent(string $eventName, bool $devMode, RepositoryInterface $localRepo, array $operations, OperationInterface $operation): int
{
assert($this->composer instanceof Composer, new \LogicException('This should only be reached with a fully loaded Composer'));

return $this->doDispatch(new PackageEvent($eventName, $this->composer, $this->io, $devMode, $localRepo, $operations, $operation));
}












public function dispatchInstallerEvent(string $eventName, bool $devMode, bool $executeOperations, Transaction $transaction): int
{
assert($this->composer instanceof Composer, new \LogicException('This should only be reached with a fully loaded Composer'));

return $this->doDispatch(new InstallerEvent($eventName, $this->composer, $this->io, $devMode, $executeOperations, $transaction));
}









protected function doDispatch(Event $event)
{
if (Platform::getEnv('COMPOSER_DEBUG_EVENTS')) {
$details = null;
if ($event instanceof PackageEvent) {
$details = (string) $event->getOperation();
} elseif ($event instanceof CommandEvent) {
$details = $event->getCommandName();
} elseif ($event instanceof PreCommandRunEvent) {
$details = $event->getCommand();
}
$this->io->writeError('Dispatching <info>'.$event->getName().'</info>'.($details ? ' ('.$details.')' : '').' event');
}

$listeners = $this->getListeners($event);

$this->pushEvent($event);

$autoloadersBefore = spl_autoload_functions();

try {
$returnMax = 0;
foreach ($listeners as $callable) {
$return = 0;
$this->ensureBinDirIsInPath();

$additionalArgs = $event->getArguments();
if (is_string($callable) && str_contains($callable, '@no_additional_args')) {
$callable = Preg::replace('{ ?@no_additional_args}', '', $callable);
$additionalArgs = [];
}
$formattedEventNameWithArgs = $event->getName() . ($additionalArgs !== [] ? ' (' . implode(', ', $additionalArgs) . ')' : '');
if (!is_string($callable)) {
if (!is_callable($callable)) {
$className = is_object($callable[0]) ? get_class($callable[0]) : $callable[0];

throw new \RuntimeException('Subscriber '.$className.'::'.$callable[1].' for event '.$event->getName().' is not callable, make sure the function is defined and public');
}
if (is_array($callable) && (is_string($callable[0]) || is_object($callable[0])) && is_string($callable[1])) {
$this->io->writeError(sprintf('> %s: %s', $formattedEventNameWithArgs, (is_object($callable[0]) ? get_class($callable[0]) : $callable[0]).'->'.$callable[1]), true, IOInterface::VERBOSE);
}
$return = false === $callable($event) ? 1 : 0;
} elseif ($this->isComposerScript($callable)) {
$this->io->writeError(sprintf('> %s: %s', $formattedEventNameWithArgs, $callable), true, IOInterface::VERBOSE);

$script = explode(' ', substr($callable, 1));
$scriptName = $script[0];
unset($script[0]);

$index = array_search('@additional_args', $script, true);
if ($index !== false) {
$args = array_splice($script, $index, 0, $additionalArgs);
} else {
$args = array_merge($script, $additionalArgs);
}
$flags = $event->getFlags();
if (isset($flags['script-alias-input'])) {
$argsString = implode(' ', array_map(static function ($arg) { return ProcessExecutor::escape($arg); }, $script));
$flags['script-alias-input'] = $argsString . ' ' . $flags['script-alias-input'];
unset($argsString);
}
if (strpos($callable, '@composer ') === 0) {
$exec = $this->getPhpExecCommand() . ' ' . ProcessExecutor::escape(Platform::getEnv('COMPOSER_BINARY')) . ' ' . implode(' ', $args);
if (0 !== ($exitCode = $this->executeTty($exec))) {
$this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code '.$exitCode.'</error>', $callable, $event->getName()), true, IOInterface::QUIET);

throw new ScriptExecutionException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
}
} else {
if (!$this->getListeners(new Event($scriptName))) {
$this->io->writeError(sprintf('<warning>You made a reference to a non-existent script %s</warning>', $callable), true, IOInterface::QUIET);
}

try {

$scriptEvent = new Script\Event($scriptName, $event->getComposer(), $event->getIO(), $event->isDevMode(), $args, $flags);
$scriptEvent->setOriginatingEvent($event);
$return = $this->dispatch($scriptName, $scriptEvent);
} catch (ScriptExecutionException $e) {
$this->io->writeError(sprintf('<error>Script %s was called via %s</error>', $callable, $event->getName()), true, IOInterface::QUIET);
throw $e;
}
}
} elseif ($this->isPhpScript($callable)) {
$className = substr($callable, 0, strpos($callable, '::'));
$methodName = substr($callable, strpos($callable, '::') + 2);

if (!class_exists($className)) {
$this->io->writeError('<warning>Class '.$className.' is not autoloadable, can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
continue;
}
if (!is_callable($callable)) {
$this->io->writeError('<warning>Method '.$callable.' is not callable, can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
continue;
}

try {
$return = false === $this->executeEventPhpScript($className, $methodName, $event) ? 1 : 0;
} catch (\Exception $e) {
$message = "Script %s handling the %s event terminated with an exception";
$this->io->writeError('<error>'.sprintf($message, $callable, $event->getName()).'</error>', true, IOInterface::QUIET);
throw $e;
}
} elseif ($this->isCommandClass($callable)) {
$className = $callable;
if (!class_exists($className)) {
$this->io->writeError('<warning>Class '.$className.' is not autoloadable, can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
continue;
}
if (!is_a($className, Command::class, true)) {
$this->io->writeError('<warning>Class '.$className.' does not extend '.Command::class.', can not call '.$event->getName().' script</warning>', true, IOInterface::QUIET);
continue;
}
if (defined('Composer\Script\ScriptEvents::'.str_replace('-', '_', strtoupper($event->getName())))) {
$this->io->writeError('<warning>You cannot bind '.$event->getName().' to a Command class, use a non-reserved name</warning>', true, IOInterface::QUIET);
continue;
}

$app = new Application();
$app->setCatchExceptions(false);
if (method_exists($app, 'setCatchErrors')) {
$app->setCatchErrors(false);
}
$app->setAutoExit(false);
$cmd = new $className($event->getName());
$app->add($cmd);
$app->setDefaultCommand((string) $cmd->getName(), true);
try {
$args = implode(' ', array_map(static function ($arg) { return ProcessExecutor::escape($arg); }, $additionalArgs));


if ($this->io instanceof ConsoleIO) {
$reflProp = new \ReflectionProperty($this->io, 'output');
if (\PHP_VERSION_ID < 80100) {
$reflProp->setAccessible(true);
}
$output = $reflProp->getValue($this->io);
} else {
$output = new ConsoleOutput();
}
$return = $app->run(new StringInput($event->getFlags()['script-alias-input'] ?? $args), $output);
} catch (\Exception $e) {
$message = "Script %s handling the %s event terminated with an exception";
$this->io->writeError('<error>'.sprintf($message, $callable, $event->getName()).'</error>', true, IOInterface::QUIET);
throw $e;
}
} else {
$args = implode(' ', array_map(['Composer\Util\ProcessExecutor', 'escape'], $additionalArgs));


if (strpos($callable, '@putenv ') === 0) {
$exec = $callable;
} else {
if (str_contains($callable, '@additional_args')) {
$exec = str_replace('@additional_args', $args, $callable);
} else {
$exec = $callable . ($args === '' ? '' : ' '.$args);
}
}

if ($this->io->isVerbose()) {
$this->io->writeError(sprintf('> %s: %s', $event->getName(), $exec));
} elseif ($event->getName() !== '__exec_command') {

$this->io->writeError(sprintf('> %s', $exec));
}

$possibleLocalBinaries = $this->composer->getPackage()->getBinaries();
if (count($possibleLocalBinaries) > 0) {
foreach ($possibleLocalBinaries as $localExec) {
if (Preg::isMatch('{\b'.preg_quote($callable).'$}', $localExec)) {
$caller = BinaryInstaller::determineBinaryCaller($localExec);
$exec = Preg::replace('{^'.preg_quote($callable).'}', $caller . ' ' . $localExec, $exec);
break;
}
}
}

if (strpos($exec, '@putenv ') === 0) {
if (false === strpos($exec, '=')) {
Platform::clearEnv(substr($exec, 8));
} else {
[$var, $value] = explode('=', substr($exec, 8), 2);
Platform::putEnv($var, $value);
}

continue;
}
if (strpos($exec, '@php ') === 0) {
$pathAndArgs = substr($exec, 5);
if (Platform::isWindows()) {
$pathAndArgs = Preg::replaceCallback('{^\S+}', static function ($path) {
return str_replace('/', '\\', $path[0]);
}, $pathAndArgs);
}


$matched = Preg::isMatchStrictGroups('{^[^\'"\s/\\\\]+}', $pathAndArgs, $match);
if ($matched && !file_exists($match[0])) {
$finder = new ExecutableFinder;
if ($pathToExec = $finder->find($match[0])) {
if (Platform::isWindows()) {
$execWithoutExt = Preg::replace('{\.(exe|bat|cmd|com)$}i', '', $pathToExec);

if (file_exists($execWithoutExt)) {
$pathToExec = $execWithoutExt;
}
unset($execWithoutExt);
}
$pathAndArgs = $pathToExec . substr($pathAndArgs, strlen($match[0]));
}
}
$exec = $this->getPhpExecCommand() . ' ' . $pathAndArgs;
} else {
$finder = new PhpExecutableFinder();
$phpPath = $finder->find(false);
if ($phpPath) {
Platform::putEnv('PHP_BINARY', $phpPath);
}

if (Platform::isWindows()) {
$exec = Preg::replaceCallback('{^\S+}', static function ($path) {
return str_replace('/', '\\', $path[0]);
}, $exec);
}
}




if (strpos($exec, 'composer ') === 0) {
$exec = $this->getPhpExecCommand() . ' ' . ProcessExecutor::escape(Platform::getEnv('COMPOSER_BINARY')) . substr($exec, 8);
}

if (0 !== ($exitCode = $this->executeTty($exec))) {
$this->io->writeError(sprintf('<error>Script %s handling the %s event returned with error code '.$exitCode.'</error>', $callable, $event->getName()), true, IOInterface::QUIET);

throw new ScriptExecutionException('Error Output: '.$this->process->getErrorOutput(), $exitCode);
}
}

$returnMax = max($returnMax, $return);

if ($event->isPropagationStopped()) {
break;
}
}
} finally {
$this->popEvent();

$knownIdentifiers = [];
foreach ($autoloadersBefore as $key => $cb) {
$knownIdentifiers[$this->getCallbackIdentifier($cb)] = ['key' => $key, 'callback' => $cb];
}
foreach (spl_autoload_functions() as $cb) {

if (isset($knownIdentifiers[$this->getCallbackIdentifier($cb)]) && $knownIdentifiers[$this->getCallbackIdentifier($cb)]['key'] === 0) {
break;
}


if ($cb instanceof ClassLoader) {
$cb->unregister();
$cb->register(false);
} else {
spl_autoload_unregister($cb);
spl_autoload_register($cb);
}
}
}

return $returnMax;
}

protected function executeTty(string $exec): int
{
if ($this->io->isInteractive()) {
return $this->process->executeTty($exec);
}

return $this->process->execute($exec);
}

protected function getPhpExecCommand(): string
{
$finder = new PhpExecutableFinder();
$phpPath = $finder->find(false);
if (!$phpPath) {
throw new \RuntimeException('Failed to locate PHP binary to execute '.$phpPath);
}
$phpArgs = $finder->findArguments();
$phpArgs = $phpArgs ? ' ' . implode(' ', $phpArgs) : '';
$allowUrlFOpenFlag = ' -d allow_url_fopen=' . ProcessExecutor::escape(ini_get('allow_url_fopen'));
$disableFunctionsFlag = ' -d disable_functions=' . ProcessExecutor::escape(ini_get('disable_functions'));
$memoryLimitFlag = ' -d memory_limit=' . ProcessExecutor::escape(ini_get('memory_limit'));

return ProcessExecutor::escape($phpPath) . $phpArgs . $allowUrlFOpenFlag . $disableFunctionsFlag . $memoryLimitFlag;
}






protected function executeEventPhpScript(string $className, string $methodName, Event $event)
{
if ($this->io->isVerbose()) {
$this->io->writeError(sprintf('> %s: %s::%s', $event->getName(), $className, $methodName));
} else {
$this->io->writeError(sprintf('> %s::%s', $className, $methodName));
}

return $className::$methodName($event);
}








public function addListener(string $eventName, $listener, int $priority = 0): void
{
$this->listeners[$eventName][$priority][] = $listener;
}




public function removeListener($listener): void
{
foreach ($this->listeners as $eventName => $priorities) {
foreach ($priorities as $priority => $listeners) {
foreach ($listeners as $index => $candidate) {
if ($listener === $candidate || (is_array($candidate) && is_object($listener) && $candidate[0] === $listener)) {
unset($this->listeners[$eventName][$priority][$index]);
}
}
}
}
}






public function addSubscriber(EventSubscriberInterface $subscriber): void
{
foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
if (is_string($params)) {
$this->addListener($eventName, [$subscriber, $params]);
} elseif (is_string($params[0])) {
$this->addListener($eventName, [$subscriber, $params[0]], $params[1] ?? 0);
} else {
foreach ($params as $listener) {
$this->addListener($eventName, [$subscriber, $listener[0]], $listener[1] ?? 0);
}
}
}
}






protected function getListeners(Event $event): array
{
$scriptListeners = $this->runScripts ? $this->getScriptListeners($event) : [];

if (!isset($this->listeners[$event->getName()][0])) {
$this->listeners[$event->getName()][0] = [];
}
krsort($this->listeners[$event->getName()]);

$listeners = $this->listeners;
$listeners[$event->getName()][0] = array_merge($listeners[$event->getName()][0], $scriptListeners);

return array_merge(...$listeners[$event->getName()]);
}




public function hasEventListeners(Event $event): bool
{
$listeners = $this->getListeners($event);

return count($listeners) > 0;
}







protected function getScriptListeners(Event $event): array
{
$package = $this->composer->getPackage();
$scripts = $package->getScripts();

if (empty($scripts[$event->getName()])) {
return [];
}

assert($this->composer instanceof Composer, new \LogicException('This should only be reached with a fully loaded Composer'));

if ($this->loader) {
$this->loader->unregister();
}

$generator = $this->composer->getAutoloadGenerator();
if ($event instanceof ScriptEvent) {
$generator->setDevMode($event->isDevMode());
}

$packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
$packageMap = $generator->buildPackageMap($this->composer->getInstallationManager(), $package, $packages);
$map = $generator->parseAutoloads($packageMap, $package);
$this->loader = $generator->createLoader($map, $this->composer->getConfig()->get('vendor-dir'));
$this->loader->register(false);

return $scripts[$event->getName()];
}




protected function isPhpScript(string $callable): bool
{
return false === strpos($callable, ' ') && false !== strpos($callable, '::');
}




protected function isCommandClass(string $callable): bool
{
return str_contains($callable, '\\') && !str_contains($callable, ' ') && str_ends_with($callable, 'Command');
}




protected function isComposerScript(string $callable): bool
{
return strpos($callable, '@') === 0 && strpos($callable, '@php ') !== 0 && strpos($callable, '@putenv ') !== 0;
}






protected function pushEvent(Event $event): int
{
$eventName = $event->getName();
if (in_array($eventName, $this->eventStack)) {
throw new \RuntimeException(sprintf("Circular call to script handler '%s' detected", $eventName));
}

return array_push($this->eventStack, $eventName);
}




protected function popEvent(): ?string
{
return array_pop($this->eventStack);
}

private function ensureBinDirIsInPath(): void
{
$pathEnv = 'PATH';




if (!isset($_SERVER[$pathEnv]) && isset($_SERVER['Path'])) {
$pathEnv = 'Path';
}


$binDir = $this->composer->getConfig()->get('bin-dir');
if (is_dir($binDir)) {
$binDir = realpath($binDir);
$pathValue = (string) Platform::getEnv($pathEnv);
if (!Preg::isMatch('{(^|'.PATH_SEPARATOR.')'.preg_quote($binDir).'($|'.PATH_SEPARATOR.')}', $pathValue)) {
Platform::putEnv($pathEnv, $binDir.PATH_SEPARATOR.$pathValue);
}
}
}




private function getCallbackIdentifier($cb): string
{
if (is_string($cb)) {
return 'fn:'.$cb;
}
if (is_object($cb)) {
return 'obj:'.spl_object_hash($cb);
}
if (is_array($cb)) {
return 'array:'.(is_string($cb[0]) ? $cb[0] : get_class($cb[0]) .'#'.spl_object_hash($cb[0])).'::'.$cb[1];
}


return 'unsupported';
}
}
