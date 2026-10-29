<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

use Closure;
use Exception;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Symfony\Component\Yaml\Yaml;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class Process
{
    /**
     * Used to show/hide output
     *
     * @var bool
     */
    public bool $debug = false;

    /**
     * @var string
     */
    public string $deploymentDestinationRootPath = '';

    /**
     * @var array
     */
    public array $dockerComposeCommand = [];

    /**
     * @var string
     */
    public string $appServiceName = '';

    /**
     * Define your needed configs to [copy,] load
     * (list of files basename without .yaml)
     *
     * @var array|string[]
     */
    public array $requiredYamlConfigs = ['setup'];

    /**
     * List configs from {@see $requiredYamlConfigs} where have to adjusted by user before!
     * All configs will be created the the fist time by templates if they does not exist.
     * But in this case an error will appear if the config was created the first time,
     * so the user have to modify.
     *
     * @var array|string[]
     */
    public array $yamlConfigsHaveToProvideByUser = ['setup'];

    /**
     * loaded configs first indexed by required name
     *
     * @var array
     */
    public array $yamlConfigs = [];

    /**
     *
     * @var string
     */
    public string $configPath = '';

    /**
     *
     * @var string
     */
    public string $configTemplatesPath = '';

    /**
     * @var array
     */
    public array $hadConfigExisted = [];

    /**
     * @var Filesystem
     */
    public Filesystem $filesystem;

    /**
     * Shows the step progress while deploySteps() runs, null otherwise.
     *
     * @var StepRunner|null
     */
    protected ?StepRunner $runner = null;

    /**
     * Env keys that keep their first value once written. Changing them would make docker compose
     * create a new project with new, empty volumes (database!) instead of using the existing one.
     *
     * @var list<string>
     */
    protected array $stickyEnvKeys = ['APP_INSTANCE_KEY', 'COMPOSE_PROJECT_NAME'];

    /**
     * Sticky keys without a warning if the calculated value differs.
     * The instance key is only a random-looking seed, its calculated value has no meaning of its own.
     *
     * @var list<string>
     */
    protected array $silentStickyEnvKeys = ['APP_INSTANCE_KEY'];

    /**
     * Env key with the "provisioning.auto_port_start" the auto ports were calculated with.
     */
    public const string AUTO_PORT_START_ENV = 'MERCY_AUTO_PORT_START';

    /**
     * Env keys that only exist in the target env (written there by the app, e.g. "artisan key:generate").
     * They are not part of the config env, so they are carried over when the config env replaces the target env.
     *
     * @var list<string>
     */
    protected array $targetOwnedEnvKeys = ['APP_KEY'];

    /**
     * true: without "setup.kernel.version" the version comes from git (dev),
     * false: "setup.kernel.version" is required (production: the release to run).
     *
     * @var bool
     */
    protected bool $versionFromGit = false;

    /**
     * "git describe" results per project root.
     *
     * @var array<string, string>
     */
    private array $gitVersions = [];

    /**
     * @var string
     */
    private string $lastError = '';

    /**
     *
     */
    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     *
     * 1) Initiating validating the paths (deployment destination, configs, config templates)
     * 2) Preparing and loading all configs ('setup' > {@see self::requiredYamlConfigs})
     * 3) Ensure 'setup' > {@see self::yamlConfigsHaveToProvideByUser} exists now
     *
     * @param  string  $deploymentDestinationDir
     * @param  string  $configTemplateDir
     * @param  string  $configDir
     * @param  array   $requiredYamlConfigs
     *
     * @return void
     * @throws Exception
     */
    public function initSetup(string $deploymentDestinationDir, string $configTemplateDir, string $configDir, array $requiredYamlConfigs = []): void
    {
        // make valid paths if needed
        $this->deploymentDestinationRootPath = $this->assertDir($deploymentDestinationDir, 'Destination Root', true);
        $this->configTemplatesPath = $this->assertDir($configTemplateDir, 'Config Templates');
        $this->configPath = $this->assertDir($configDir, 'Config Dir');

        // merge required config names with defaults
        $this->requiredYamlConfigs = array_unique([...$this->requiredYamlConfigs, ...$requiredYamlConfigs]);

        // load and prepare configs by templates if not exist
        if (!$this->prepareAndLoadRequiredYamlConfigs()) {
            throw new Exception('Failed to load/prepare configs.');
        }

        // ===================================================================
        // Check config was prepared
        // ===================================================================
        foreach ($this->yamlConfigsHaveToProvideByUser as $confName) {
            $currentConfig = $this->getYamlConfigFullPath($confName);
            if (!$this->hadConfigExisted[$confName]) {
                throw new Exception(sprintf("Setup your '$confName' config in: '%s'", $currentConfig));
            }
        }

        $this->debug = $this->getConfigValue('setup.kernel.debug', false) ?? false;
        $this->appServiceName = $this->getConfigValue('setup.kernel.service', '');
    }


    /**
     * @param  string  $content
     *
     * @return void
     */
    public function info(string $content): void
    {
        if (!$this->debug) {
            return;
        }
        info($content);
    }

    /**
     * Runs a command. Its output is only shown in debug mode or with $showOutput,
     * on failure the end of the output is reported as error.
     *
     * @param  array        $command
     * @param  string|null  $rootDir
     * @param  float|null   $timeout
     * @param  bool         $showOutput  show the output also outside debug mode (e.g. a summary)
     *
     * @return bool
     */
    public function run(array $command, ?string $rootDir = null, ?float $timeout = null, bool $showOutput = false): bool
    {
        $process = new SymfonyProcess(command: $command, cwd: $rootDir, env: $this->gitSshEnv());
        $process->setTimeout($timeout);
        $visible = $this->debug || $showOutput;

        try {
            if ($visible) {
                $this->runner?->pauseLive();
                $process->run(function (string $type, string $buffer): void {
                    fwrite($type === SymfonyProcess::ERR ? STDERR : STDOUT, $buffer);
                });
            } else {
                $this->waitFor($process);
            }
        } catch (ProcessTimedOutException) {
            $this->error(sprintf('Timeout after %ss: %s', $timeout, $process->getCommandLine()));

            return false;
        } finally {
            if ($visible) {
                $this->runner?->resumeLive();
            }
        }

        if (!$process->isSuccessful()) {
            // the output was already shown in visible mode
            $this->error($this->describeFailure($process, !$visible));

            return false;
        }

        return true;
    }

    /**
     * Runs a command and returns its stdout, or null if it failed (non-zero exit).
     *
     * @param  array        $command
     * @param  string|null  $rootDir
     * @param  float|null   $timeout
     *
     * @return string|null
     */
    public function capture(array $command, ?string $rootDir = null, ?float $timeout = 10.0): ?string
    {
        if (!$rootDir) {
            $rootDir = $this->deploymentDestinationRootPath;
        }

        $process = new SymfonyProcess(command: $command, cwd: $rootDir, env: $this->gitSshEnv());
        $process->setTimeout($timeout);
        $this->lastError = '';

        try {
            $this->waitFor($process);
        } catch (ProcessTimedOutException) {
            $this->lastError = sprintf('Timeout after %ss: %s', $timeout, $process->getCommandLine());

            return null;
        }

        if (!$process->isSuccessful()) {
            $this->lastError = $this->describeFailure($process);

            return null;
        }

        return $process->getOutput();
    }

    /**
     * Starts the process and waits for it. While a step runner is active, it keeps
     * spinner and elapsed time up to date (a blocking wait would freeze the display).
     *
     * @param  SymfonyProcess  $process
     *
     * @return void
     * @throws ProcessTimedOutException
     */
    protected function waitFor(SymfonyProcess $process): void
    {
        if ($this->runner === null) {
            $process->run();

            return;
        }
        $process->start();
        // isRunning() also reads the pipes, so a chatty process cannot block on a full buffer
        while ($process->isRunning()) {
            $process->checkTimeout();
            $this->runner->tick();
            usleep(50_000);
        }
        $process->wait();
    }

    /**
     * @param  SymfonyProcess  $process
     * @param  bool            $withOutput  append the last lines of the output
     *
     * @return string
     */
    protected function describeFailure(SymfonyProcess $process, bool $withOutput = true): string
    {
        $text = sprintf('Exit %d: %s', $process->getExitCode(), $process->getCommandLine());
        if ($withOutput) {
            $out = implode("\n", array_filter([trim($process->getErrorOutput()), trim($process->getOutput())]));
            // docker pull progress ("a2e7cb82bdcd Extracting 2B") would push the real message out of view
            $lines = array_filter(explode("\n", $out), fn(string $line) => !self::isProgressLine($line));
            if ($lines) {
                $text .= "\n".implode("\n", array_slice($lines, -20));
            }
        }

        return $text;
    }

    /**
     * "npm.enabled" of the project config; without the key: enabled if "npm.url" is set.
     *
     * @return bool
     */
    public function isNpmEnabled(): bool
    {
        $enabled = $this->getConfigValue('setup.npm.enabled');
        if ($enabled === null) {
            return (string) $this->getConfigValue('setup.npm.url', '') !== '';
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Progress output of "docker compose pull/up" (layer ids, image pulled/pulling).
     *
     * @param  string  $line
     *
     * @return bool
     */
    protected static function isProgressLine(string $line): bool
    {
        // "a2e7cb82bdcd Extracting 2B" (pull), "0a60cdf6dfa3: Waiting" (push)
        return (bool) preg_match('/^\s*([0-9a-f]{12}:?\s|Image \S+ Pull(ed|ing)\b|\S+ Pull(ed|ing)\s*$)/', $line);
    }

    /**
     * Creates the external docker networks of the compose file that do not exist yet
     * (e.g. "proxy-gateway" on a fresh machine), otherwise "up" fails.
     *
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function ensureExternalNetworks(string $rootDir): bool
    {
        $config = $this->capture(['docker', 'compose', 'config', '--format', 'json'], $rootDir, 30.0);
        if ($config === null) {
            $this->error($this->lastError);

            return false;
        }
        foreach (self::externalNetworks($config) as $network) {
            if ($this->capture(['docker', 'network', 'inspect', $network], $rootDir) !== null) {
                continue;
            }
            $this->info("Creating the missing docker network '$network'.");
            if (!$this->run(['docker', 'network', 'create', $network], $rootDir)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string  $composeConfigJson  output of "docker compose config --format json"
     *
     * @return list<string> names of the external networks
     */
    public static function externalNetworks(string $composeConfigJson): array
    {
        $names = [];
        foreach (json_decode($composeConfigJson, true)['networks'] ?? [] as $key => $network) {
            if (!empty($network['external'])) {
                $names[] = (string) ($network['name'] ?? $key);
            }
        }

        return $names;
    }

    /**
     * @param  array        $command
     * @param  string|null  $cwd
     * @param  float|null   $timeout
     *
     * @return bool
     */
    protected function runStep(array $command, ?string $cwd, ?float $timeout): bool
    {
        if ($this->capture($command, $cwd, $timeout) !== null) {
            return true;
        }
        $this->error($this->lastError);

        return false;
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runComposerInstall(string $rootDir, bool $noDev = true): bool
    {
        // Use array syntax for command to auto-escape parameters safely
        $command = [
            'composer',
            'install',
            '--working-dir='.$rootDir,
            ...($noDev ? ['--no-dev', '--optimize-autoloader'] : []),
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runComposerUpdate(string $rootDir): bool
    {
        // Use array syntax for command to auto-escape parameters safely
        $command = [
            'composer',
            'update',
            '--working-dir='.$rootDir,
            //'--no-dev',
            //'--optimize-autoloader',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * Runs the steps, see {@see StepRunner}. Exits with code 1 if a step fails.
     *
     * @param  list<array{name?: string, label: string, task: callable}>  $steps
     * @param  int|string                                                 $deploymentStepStart  step number (1 based) or step name
     *
     * @return bool
     */
    public function deploySteps(array $steps, int|string $deploymentStepStart): bool
    {
        $this->runner = new StepRunner($this->debug);
        try {
            $ok = $this->runner->run($steps, $deploymentStepStart);
        } finally {
            $this->runner = null;
        }

        if (!$ok) {
            exit(1);
        }

        return true;
    }

    /**
     * Runs a command inside the build folder.
     * No timeout (composer and npm can take minutes); on failure the error output is reported.
     *
     * @param  array  $command  e.g. ['composer', 'install', '--no-dev']
     *
     * @return bool
     */
    public function runInBuildFolder(array $command): bool
    {
        return $this->runStep($command, $this->deploymentDestinationRootPath, null);
    }

    /**
     * @param  string  $path
     * @param  string  $label
     * @param  bool    $forceExist
     *
     * @return string
     * @throws Exception
     */
    public function assertDir(string $path, string $label = 'path', bool $forceExist = false): string
    {
        try {
            // e.g. the build folder: created on demand (realpath() needs an existing path)
            if ($forceExist && !is_dir($path)) {
                $this->filesystem->mkdir($path);
            }
            if (!($realpath = realpath($path))) {
                throw new Exception(sprintf("Invalid '%s' path: %s'", $label, $path));
            }
            if (!$this->checkDir($realpath, $forceExist)) {
                throw new Exception(sprintf("Path '%s' does not exist: '%s'", $label, $realpath));
            }
        } catch (Exception $exception) {
            throw new Exception(sprintf("Invalid '%s' path: %s'. Exception: %s", $label, $path, $exception->getMessage()));
        }

        return $realpath;
    }

    /**
     * @param  string  $dir
     * @param  bool    $forceExist
     * @param  int     $mode
     *
     * @return bool
     */
    public function checkDir(string $dir, bool $forceExist = false, int $mode = 0755): bool
    {
        if ($forceExist && !is_dir($dir)) {
            $this->filesystem->mkdir($dir, $mode);
        }

        return is_dir($dir);
    }

    /**
     * @param  string  $requiredName
     *
     * @return string
     */
    public function getYamlConfigFullPath(string $requiredName): string
    {
        return $this->configPath.DIRECTORY_SEPARATOR.$requiredName.'.yaml';
    }

    /**
     * @param  string  $requiredName
     *
     * @return string
     */
    public function getYamlConfigTemplateFullPath(string $requiredName): string
    {
        return $this->configTemplatesPath.DIRECTORY_SEPARATOR.$requiredName.'.yaml';
    }

    /**
     * 1) Ensure required templates exists
     * 2) if needed, copy from /../setup/ to last-setup {@see getYamlConfigFullPath}
     * 3) load the config file in {@see requiredYamlConfigs}
     *
     * @return bool
     */
    public function prepareAndLoadRequiredYamlConfigs(): bool
    {
        foreach ($this->requiredYamlConfigs as $required) {
            $installConfigPath = $this->getYamlConfigFullPath($required);
            // 1) If this config had already existed, skip a new copy ...
            if (file_exists($installConfigPath)) {
                $this->hadConfigExisted[$required] = true;
            } else {

                $this->hadConfigExisted[$required] = false;
                $configTemplate = $this->getYamlConfigTemplateFullPath($required);
                // 2) If Template does not exist, exit - error
                if (!file_exists($configTemplate)) {
                    $this->error("Missing template '$configTemplate'");

                    return false;
                }

                // 3) Copy the template
                if (!copy($configTemplate, $installConfigPath)) {
                    $this->error("Failed to copy '$configTemplate' to '$installConfigPath'");

                    return false;
                }
            }

            // 4) load the config
            if (!($this->yamlConfigs[$required] = $this->loadYamlConfig($installConfigPath))) {

                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     * @todo: optional parameters wich files to save
     *
     */
    public function saveRequiredConfigs(): bool
    {
        foreach ($this->requiredYamlConfigs as $required) {
            $installConfigPath = $this->getYamlConfigFullPath($required);
            $this->saveYamlConfig($installConfigPath, $this->yamlConfigs[$required]);
        }

        return true;
    }


    /**
     * Copy file if not already exist.
     *
     * @param  string  $source
     * @param  string  $dest
     *
     * @return bool true file was already existing
     */
    public function copyFile(string $source, string $dest): bool
    {
        if (!($previousFileExist = file_exists($dest))) {
            copy($source, $dest);
        }

        return $previousFileExist;
    }

    /**
     * Fully expands a leading tilde (~) into the absolute home directory path.
     */
    public static function expandTilde(string $path): string
    {
        if (!str_starts_with($path, '~')) {
            return $path;
        }

        // Fetch home directory using platform-agnostic order of precedence
        $home = match (true) {
            false !== getenv('HOME') => getenv('HOME'),
            false !== getenv('USERPROFILE') => getenv('USERPROFILE'),
            default => null,
        };

        // Fallback for secure Linux production nodes missing env variables
        if ($home === null && function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_getuid());
            $home = $info['dir'] ?? null;
        }

        if ($home === null) {
            throw new RuntimeException('Could not resolve the user home directory.');
        }

        // Strip trailing slashes from home dir to guarantee a uniform path structure
        $home = rtrim($home, DIRECTORY_SEPARATOR);

        // Replace the leading tilde with the absolute path
        return substr_replace($path, $home, 0, 1);
    }

    /**
     * Important: Must be located outside the build folder!
     * Absolute host path of the directory that is mounted read-only into the container.
     * Forward slashes on purpose (Windows paths in .env / compose).
     */
    public function getSshDockerDir(): string
    {
        $dir = (string) $this->getConfigValue('setup.ssh.docker_dir', '~/.ssh-docker');

        return str_replace('\\', '/', self::expandTilde($dir));
    }

    /**
     * Builds the host directory that is mounted read-only into the container
     * (/tmp/host-ssh). Must run on the Docker host and BEFORE "sail up".
     *
     * @return bool the directory on success
     */
    public function syncDockerSsh(): bool
    {
        $ssh = $this->getConfigValue('setup.ssh', []);
        $hosts = array_values(array_filter((array) ($ssh['known_hosts'] ?? [])));
        $keySource = self::expandTilde($ssh['key'] ?? '~/.ssh/id_ed25519');
        $hostKnownHosts = self::expandTilde('~/.ssh/known_hosts');
        $targetDir = $this->getSshDockerDir();

        // Validate everything first, so a failure never leaves a half-written directory
        if ($hosts === []) {
            $this->error("No hosts configured in setup.yaml > ssh > known_hosts.");

            return false;
        }
        if (!is_file($keySource)) {
            $this->error("Deploy key '$keySource' does not exist.");

            return false;
        }

        // 1) Extract only the configured hosts from the host's known_hosts.
        //    ssh-keygen -F also handles hashed entries. No blind ssh-keyscan.
        $lines = [];
        $missing = [];
        foreach ($hosts as $host) {
            $output = $this->capture(['ssh-keygen', '-F', (string) $host, '-f', $hostKnownHosts]);
            $entries = array_filter(preg_split('/\R/', trim((string) $output)) ?: [], fn(string $l) => $l !== '' && !str_starts_with($l, '#'));
            if ($entries === []) {
                $missing[] = $host;
                continue;
            }
            array_push($lines, ...$entries);
        }

        if ($missing !== []) {
            $this->error('No known_hosts entry on this machine for: '.implode(', ', $missing).'. Run "./mercy host:setup" (adds them by "ssh.fingerprints").');

            return false;
        }

        try {
            // 0711: the container user (sail) may have a different UID than the host owner.
            // It must be able to traverse the mount to read known_hosts, but not list it.
            $this->filesystem->mkdir($targetDir);
            $this->filesystem->chmod($targetDir, 0711);

            // 2) Rebuilt on every run: removed hosts disappear, rotated keys are refreshed.
            //    dumpFile() replaces atomically. 0644, read directly by sail.
            $this->filesystem->dumpFile($targetDir.'/known_hosts', implode("\n", array_unique($lines))."\n");
            $this->filesystem->chmod($targetDir.'/known_hosts', 0644);

            // 3) Deploy key: always overwrite (copyFile() would skip an existing, outdated key).
            //    Only start-container (root) reads it and copies it to /home/sail/.ssh with 600.
            $oldUmask = umask(0077);
            try {
                $this->filesystem->copy($keySource, $targetDir.'/id_ed25519', true);
            } finally {
                umask($oldUmask);
            }
            $this->filesystem->chmod($targetDir.'/id_ed25519', 0600);

            if (is_file($keySource.'.pub')) {
                $this->filesystem->copy($keySource.'.pub', $targetDir.'/id_ed25519.pub', true);
                $this->filesystem->chmod($targetDir.'/id_ed25519.pub', 0644);
            }

            // 4) Git url rewrites (url.<base>.insteadOf) of this machine, so git/composer inside
            //    the container resolve repository urls the same way as on the host.
            //    start-container includes this file into the sail user's git config.
            $rewrites = self::parseGitUrlRewrites((string) $this->capture(['git', 'config', '--global', '--get-regexp', '^url\\..*\\.insteadof$']));
            if ($rewrites) {
                $this->filesystem->dumpFile($targetDir.'/gitconfig', self::renderGitConfig($rewrites));
                $this->filesystem->chmod($targetDir.'/gitconfig', 0644);
            } else {
                $this->filesystem->remove($targetDir.'/gitconfig');
            }
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Using {@see Filesystem::copy()} to copy a directory.
     * Checking first source exists and creating dest if not.
     *
     * @param  string  $source
     * @param  string  $destination
     *
     * @return bool
     */
    public function copyDirectory(string $source, string $destination, array $options = []): bool
    {
        if (!is_dir($source)) {
            return false;
        }

        // Create the destination directory if it does not exist
        if (!$this->checkDir($destination, true)) {
            return false;
        }

        $this->filesystem->mirror($source, $destination, options: $options);

        return true;
    }

    /**
     * @return EnvConfig
     */
    public function getEnvConfig(): EnvConfig
    {
        return new EnvConfig($this->getConfigValue('setup.env_strict_literals', []));
    }

    /**
     * if target env not exists: Copies the given template env to the wanted location.
     * After this, calculate the yaml configs, calculate ports and write them to the env file.
     *
     * @param  string  $envBaseFilename  relative path to env filename like '.env' or '.env.minimal'
     * @param  string  $additionCopyTo   full path - if given this file will also created
     *
     * @return bool
     * @throws Exception
     */
    public function prepareEnvFile(string $envBaseFilename, string $additionCopyTo = ''): bool
    {
        // get the full path
        $currentConfigPath = $this->configPath.'/'.$envBaseFilename;

        // copy template if file does not exist
        // (a freshly copied template holds only template values, they must not count as "already written")
        $configEnvExisted = is_file($currentConfigPath);
        $this->copyFile($this->configTemplatesPath.'/'.$envBaseFilename, $currentConfigPath);

        // calc auto ports, yaml vars and additional vars
        // (sticky keys are also taken from the target env, in case the config env was deleted)
        if (!$this->saveEnvBySections($currentConfigPath, ['*'], $additionCopyTo ? [$additionCopyTo] : [], $configEnvExisted)) {
            return false;
        }

        if ($additionCopyTo) {
            // the config env replaces the target env, but keys the app wrote there itself (APP_KEY) survive
            $kept = $this->readTargetOwnedEnvValues($additionCopyTo);
            $this->filesystem->copy($currentConfigPath, $additionCopyTo, true);
            if ($kept) {
                $target = $this->getEnvConfig()
                    ->load($additionCopyTo);
                foreach ($kept as $key => $value) {
                    $target->setValue($key, $value->value, $value->parsable);
                }
                $target->save();
            }
        }

        return true;
    }

    /**
     * @param  string  $envFile
     *
     * @return array<string, EnvValue> non-empty values of {@see self::$targetOwnedEnvKeys}
     */
    protected function readTargetOwnedEnvValues(string $envFile): array
    {
        if (!is_file($envFile)) {
            return [];
        }
        $env = $this->getEnvConfig()
            ->load($envFile);
        $values = [];
        foreach ($this->targetOwnedEnvKeys as $key) {
            $value = $env->getValue($key);
            if ($value !== null && (string) $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * Recalculate yaml sections like 'setup.ldap' for 'setup.ldap.xxx' vars
     * and write them to the env file.
     *
     * @param  string  $envFilename  Fullpath of env file
     * @param  array   $sections
     *
     * @return bool
     * @throws Exception
     */
    public function saveEnvBySections(string $envFilename, array $sections = ['*'], array $stickyFallbackFiles = [], bool $useOwnStickyValues = true): bool
    {
        $env = $this->getEnvConfig();
        $env->load($envFilename);

        // calc yaml vars, but keep sticky values that already exist
        $calculated = $this->keepStickyEnvValues($this->prepareEnvVars($sections), $useOwnStickyValues ? $env : null, $stickyFallbackFiles);

        // set calculated values and save
        foreach ($calculated as $name => $value) {
            if (!is_string($name)) {
                throw new Exception("Additional variable names must be strings.");
            }
            $env->setValue($name, $value);
        }
        $env->save();

        return true;
    }

    /**
     * Recalculate yaml sections like 'setup.ldap' for 'setup.ldap.xxx' vars
     *
     * @param  array  $includeSections
     *
     * @return array
     */
    public function prepareEnvVars(array $includeSections = ['*']): array
    {
        $array = [
            'setup.kernel' => [
                'APP_NAME'             => $this->getConfigValue('setup.kernel.name'),
                'APP_VERSION'          => fn() => $this->getAppVersion(),
                'APP_SERVICE'          => $this->getConfigValue('setup.kernel.service'),
                'APP_ENV'              => $this->getConfigValue('setup.kernel.env'),
                'APP_DOMAIN'           => $this->getConfigValue('setup.kernel.domain'),
                'APP_IP'               => $this->getConfigValue('setup.kernel.ip'),
                'THEME_NAME'           => $this->getConfigValue('setup.kernel.theme'),
                'APP_INSTANCE_KEY'     => function () {
                    if (true) {
                        // use prominent high-value config values (like name, domain, git_host, ...) to generate same key for multiple setups with same values
                        $key = $this->getConfigValue('setup.git.registry_source', '');
                        $key .= $this->getConfigValue('setup.git.namespace', '');
                        $key .= $this->getConfigValue('setup.git.source', '');
                        $key .= $this->getConfigValue('setup.kernel.name', '');
                        $key .= $this->getConfigValue('setup.kernel.domain', '');
                        $key .= $this->getConfigValue('setup.provisioning.auto_port_start', 29000);
                    }

                    return strtolower($this->getShortSecHash($key));
                },
                'COMPOSE_PROJECT_NAME' => function () {
                    if (!($name = $this->getConfigValue('setup.kernel.name', ''))) {
                        $name = 'noname';
                    }

                    return $this->sanitize($name).'-${APP_INSTANCE_KEY}';
                },
                'SSH_DOCKER_DIR'       => fn() => $this->getSshDockerDir(),
            ],
            'setup.net'    => [
                'VPN_DNS1' => $this->getConfigValue('setup.net.dns1'),
                'VPN_DNS2' => $this->getConfigValue('setup.net.dns2'),
            ],
            'setup.npm'    => [
                'NPM_API_URL'      => $this->getConfigValue('setup.npm.url'),
                'NPM_API_USER'     => $this->getConfigValue('setup.npm.user'),
                'NPM_API_PASSWORD' => $this->getConfigValue('setup.npm.password'),
                // how the proxy reaches the app: "network" (shared docker network) or "host" (ip + APP_PORT),
                // empty = production "host", otherwise "network"
                'NPM_FORWARD'      => $this->getConfigValue('setup.npm.forward', ''),
                'DETECTED_NPM_IP'  => function () {
                    if ($url = $this->getConfigValue('setup.npm.url', '')) {
                        return $this->resolveIp($url);
                    }

                    return '';
                },
            ],
            'setup.git'    => [
                'MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR' => function () {
                    return $this->getConfigValue('setup.git.namespace', '');
                },
                'MODULE_DEPLOYENV_REQUIRE_MODULES_GIT'            => function () {
                    if (!($host = $this->getConfigValue('setup.git.source', ''))) {
                        return '';
                    }

                    return $host.':${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git';
                },
                'DOCKER_REGISTRY_IMAGE'                           => function () {
                    if (!($host = $this->getConfigValue('setup.git.registry_source', ''))) {
                        return '';
                    }
                    if (!($namespace = $this->getConfigValue('setup.git.registry_namespace', ''))) {
                        return '';
                    }
                    //if (!($name = $this->getConfigValue('setup.git.registry_image', ''))) {
                    //    return '';
                    //}
                    $name = $this->getImageName();

                    return $host.'/'.$namespace.'/'.$name;
                },
            ],
            'setup.ldap'   => [
                'LDAP_ENABLED'                   => function () {
                    return $this->getConfigValue('setup.ldap.enabled', '');
                },
                'LDAP_HOST'                      => function () {
                    return $this->getConfigValue('setup.ldap.host', '');
                },
                'LDAP_PORT'                      => function () {
                    return $this->getConfigValue('setup.ldap.port', 389);
                },
                'LDAP_SSL'                       => function () {
                    return $this->getConfigValue('setup.ldap.ssl', true);
                },
                'LDAP_USERNAME'                  => function () {
                    return $this->getConfigValue('setup.ldap.username', '');
                },
                'LDAP_PASSWORD'                  => function () {
                    return $this->getConfigValue('setup.ldap.password', '');
                },
                'LDAP_BASE_DN'                   => function () {
                    return $this->getConfigValue('setup.ldap.base_dn', '');
                },
                'LDAP_GUID_AUTH_NAME'            => function () {
                    return $this->getConfigValue('setup.ldap.guid_auth_name', 'cn');
                },
                'LDAP_USERS_ALLOWED_FROM_GROUPS' => function () {
                    return $this->getConfigValue('setup.ldap.users_allowed_from_groups', '');
                },
                'LDAP_IMPORT_CRON'               => function () {
                    return $this->getConfigValue('setup.ldap.import_cron', '');
                },
                'LDAP_OBJECT_CLASS_GROUPS'       => function () {
                    return $this->getConfigValue('setup.ldap.object_class_groups', 'posixGroup');
                },
                'LDAP_OBJECT_CLASS_USERS'        => function () {
                    return $this->getConfigValue('setup.ldap.object_class_users', 'posixAccount');
                },
            ],
        ];

        // handling auto ports by given key 'setup.provisioning' or '*'
        $array['setup.provisioning'] = $this->handleAutoPorts();

        // free env vars of the project or its modules (setup.yaml "env:"), added last so they win
        $array['setup.env'] = $this->getFreeEnvVars();

        // run it
        $result = [];
        foreach ($array as $section => $sectionData) {
            if (in_array('*', $includeSections) || in_array($section, $includeSections)) {
                foreach ($sectionData as $key => $value) {
                    $result[$key] = ($value instanceof Closure) ? $value() : $value;
                }
            }
        }

        return $result;
    }

    /**
     * Replaces calculated values of {@see self::$stickyEnvKeys} by the values already written
     * (in the env itself, otherwise in one of the fallback env files) and warns if they differ.
     * To get new values on purpose, delete these lines from the env files and run again.
     *
     * @param  array<string, mixed>  $calculated
     * @param  EnvConfig|null        $env  the env being written, null if it was just created from a template
     * @param  list<string>          $fallbackFiles
     *
     * @return array<string, mixed>
     */
    /**
     * True if the env was written with another "provisioning.auto_port_start" than the config has now.
     * Envs written before this key existed: changed if their first port is outside of [start, start + number of
     * port keys). Without changes the first port is the start itself (or a few above if that one was busy).
     *
     * @param  array<string, mixed>  $calculated
     * @param  list<EnvConfig>       $sources
     * @param  list<string>          $portKeys
     *
     * @return bool
     */
    protected function autoPortStartChanged(array $calculated, array $sources, array $portKeys): bool
    {
        $start = $calculated[self::AUTO_PORT_START_ENV] ?? null;
        if ($start === null) {
            return false;
        }
        foreach ($sources as $source) {
            $stored = $source->getValue(self::AUTO_PORT_START_ENV)?->value;
            if ($stored !== null && $stored !== '') {
                return (string) $stored !== (string) $start;
            }
        }
        foreach ($sources as $source) {
            $firstPort = (int) ($source->getValue((string) ($portKeys[0] ?? ''))?->value ?? 0);
            if ($firstPort > 0) {
                return $firstPort < (int) $start || $firstPort >= (int) $start + max(1, count($portKeys));
            }
        }

        return false;
    }

    protected function keepStickyEnvValues(array $calculated, ?EnvConfig $env, array $fallbackFiles = []): array
    {
        $sources = $env ? [$env] : [];
        foreach ($fallbackFiles as $file) {
            if (is_file($file)) {
                $sources[] = $this->getEnvConfig()
                    ->load($file);
            }
        }

        // Auto ports are sticky as well, but silently: on a second run the own running containers
        // occupy them, so the port search would always find different (higher) ports.
        // Exception: "provisioning.auto_port_start" was changed on purpose, then the ports are calculated again.
        $portKeys = (array) $this->getConfigValue('setup.provisioning.auto_port_env', []);
        if ($this->autoPortStartChanged($calculated, $sources, $portKeys)) {
            $portKeys = [];
        }
        $silentKeys = [...$this->silentStickyEnvKeys, ...$portKeys];

        foreach (array_unique([...$this->stickyEnvKeys, ...$silentKeys]) as $key) {
            if (!array_key_exists($key, $calculated)) {
                continue;
            }
            foreach ($sources as $source) {
                $existing = $source->getValue($key)?->value;
                if ($existing === null || $existing === '') {
                    continue;
                }
                if ((string) $existing !== (string) $calculated[$key] && !in_array($key, $silentKeys, true)) {
                    $this->warning(sprintf("%s keeps its value '%s' (the config would give '%s'). Changing it creates a new docker project with empty volumes. To change it on purpose, remove %s from the env files and run again.",
                        $key,
                        $existing,
                        $calculated[$key],
                        $key,));
                }
                $calculated[$key] = $existing;
                break;
            }
        }

        return $calculated;
    }

    /**
     * Writes "build.lock.yaml" into the root of the build folder: the exact source state of the image
     * (commit of the scaffold, every module and theme, and the branch based composer packages).
     * It lives in the app root, because "storage" is a volume on production and would hide it.
     *
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function writeBuildLock(string $rootDir): bool
    {
        try {
            $content = "# Generated by \"./mercy build\": exact source state of this image. Do not edit.\n".Yaml::dump($this->collectBuildLock($rootDir), 4, 2);
            $this->filesystem->dumpFile($rootDir.'/build.lock.yaml', $content);
        } catch (Exception $e) {
            $this->error('Unable to write build.lock.yaml: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param  string  $rootDir
     *
     * @return array{built_at: string, image: string, repositories: list<array<string, mixed>>, composer: array<string, array<string, string>>}
     * @throws Exception
     */
    public function collectBuildLock(string $rootDir): array
    {
        $rootDir = rtrim($rootDir, '/');
        $repoDirs = [$rootDir];
        foreach (['Modules', 'themes'] as $parent) {
            foreach (glob("$rootDir/$parent/*", GLOB_ONLYDIR) ?: [] as $dir) {
                if (file_exists("$dir/.git")) {
                    $repoDirs[] = $dir;
                }
            }
        }

        $repositories = [];
        foreach ($repoDirs as $dir) {
            $commit = trim((string) $this->capture(['git', 'rev-parse', 'HEAD'], $dir));
            if ($commit === '') {
                throw new Exception("Not a git repository or no commit: $dir");
            }
            $repositories[] = [
                'path'   => $dir === $rootDir ? '.' : substr($dir, strlen($rootDir) + 1),
                'remote' => self::stripUrlCredentials(trim((string) $this->capture(['git', 'remote', 'get-url', 'origin'], $dir))),
                'branch' => trim((string) $this->capture(['git', 'branch', '--show-current'], $dir)) ?: '(detached)',
                'commit' => $commit,
                'dirty'  => trim((string) $this->capture(['git', 'status', '--porcelain', '--untracked-files=no'], $dir)) !== '',
            ];
        }

        // composer packages installed from a branch (dev-*): their exact commit is only in composer.lock
        $composer = [];
        $lock = json_decode((string) @file_get_contents($rootDir.'/composer.lock'), true) ?: [];
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (str_starts_with((string) ($package['version'] ?? ''), 'dev-') && !empty($package['source']['reference'])) {
                $composer[$package['name']] = [
                    'version' => $package['version'],
                    'commit'  => $package['source']['reference'],
                ];
            }
        }
        ksort($composer);

        return [
            'version'      => $this->getAppVersion(),
            'built_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'image'        => (string) $this->getImageName(),
            'repositories' => $repositories,
            'composer'     => $composer,
        ];
    }

    /**
     * "https://user:token@host/x.git" -> "https://host/x.git" (ssh urls stay unchanged).
     *
     * @param  string  $url
     *
     * @return string
     */
    public static function stripUrlCredentials(string $url): string
    {
        return preg_replace('~^(https?://)[^/@]+@~i', '$1', $url) ?? $url;
    }

    /**
     * Checks that this machine has the git url rewrites required by "setup.git.url_rewrites"
     * (e.g. GitHub urls in composer.json that are fetched from an internal Gitea).
     * Without the setting nothing is required (e.g. installing straight from GitHub).
     *
     * @return bool
     */
    public function checkGitUrlRewrites(): bool
    {
        $expected = $this->expectedGitUrlRewrites();
        if (!$expected) {
            return true;
        }

        $present = self::parseGitUrlRewrites((string) $this->capture(['git', 'config', '--global', '--get-regexp', '^url\\..*\\.insteadof$']));
        $missing = self::missingGitUrlRewrites($expected, $present);
        if (!$missing) {
            return true;
        }

        $commands = [];
        foreach ($missing as $base => $insteadOfList) {
            foreach ($insteadOfList as $insteadOf) {
                $commands[] = sprintf('git config --global --add url."%s".insteadOf "%s"', $base, $insteadOf);
            }
        }
        $this->error("This machine is missing git url rewrites required by 'setup.git.url_rewrites'. Run:\n".implode("\n", $commands));

        return false;
    }

    /**
     * "setup.git.url_rewrites" as base url => list of urls it replaces.
     *
     * @return array<string, list<string>>
     */
    public function expectedGitUrlRewrites(): array
    {
        $expected = [];
        foreach ($this->getConfigValue('setup.git.url_rewrites', []) ?? [] as $rewrite) {
            $base = (string) ($rewrite['base'] ?? '');
            foreach ((array) ($rewrite['instead_of'] ?? []) as $insteadOf) {
                if ($base !== '' && $insteadOf !== '') {
                    $expected[$base][] = (string) $insteadOf;
                }
            }
        }

        return $expected;
    }

    /**
     * Env for every command of the deployment: git over ssh (also inside composer) uses the deploy key
     * ("ssh.key"), so one key per machine is enough, the same one the containers use.
     * Only for commands started here; git commands of the user keep his own ssh config.
     *
     * @return array<string, string>
     */
    public function gitSshEnv(): array
    {
        $key = self::expandTilde((string) $this->getConfigValue('setup.ssh.key', '~/.ssh/id_ed25519'));
        if (!is_file($key)) {
            return [];
        }

        return ['GIT_SSH_COMMAND' => 'ssh -i '.escapeshellarg($key).' -o IdentitiesOnly=yes'];
    }

    /**
     * Prepares git and ssh of this machine from the config, see {@see HostSetup}.
     *
     * @return bool
     */
    public function setupHost(): bool
    {
        return $this->hostSetup()
            ->run();
    }

    /**
     * @return HostSetup
     */
    public function hostSetup(): HostSetup
    {
        return new HostSetup($this);
    }

    /**
     * @param  array<string, list<string>>  $expected  base url => urls it must replace
     * @param  array<string, list<string>>  $present   same format, from {@see self::parseGitUrlRewrites()}
     *
     * @return array<string, list<string>> the expected entries that are not present
     */
    public static function missingGitUrlRewrites(array $expected, array $present): array
    {
        $missing = [];
        foreach ($expected as $base => $insteadOfList) {
            foreach ($insteadOfList as $insteadOf) {
                if (!in_array($insteadOf, $present[$base] ?? [], true)) {
                    $missing[$base][] = $insteadOf;
                }
            }
        }

        return $missing;
    }

    /**
     * Parses the output of "git config --get-regexp '^url\..*\.insteadof$'".
     * A line looks like: url.gitea@git.example.com:org/.insteadof https://github.com/org/
     *
     * @param  string  $output
     *
     * @return array<string, list<string>> base url => list of urls it replaces
     */
    public static function parseGitUrlRewrites(string $output): array
    {
        $rewrites = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^url\.(.+)\.insteadof\s+(\S+)$/i', trim($line), $m)) {
                $rewrites[$m[1]][] = $m[2];
            }
        }

        return $rewrites;
    }

    /**
     * @param  array<string, list<string>>  $rewrites  base url => list of urls it replaces
     *
     * @return string git config file content
     */
    public static function renderGitConfig(array $rewrites): string
    {
        $content = "# Generated by the Mercy installer from the host's git config. Do not edit.\n";
        foreach ($rewrites as $base => $insteadOfList) {
            $content .= sprintf("[url \"%s\"]\n", $base);
            foreach (array_unique($insteadOfList) as $insteadOf) {
                $content .= sprintf("\tinsteadOf = %s\n", $insteadOf);
            }
        }

        return $content;
    }

    /**
     * Env vars from the "env:" section of setup.yaml, written 1:1 into the .env.
     * Meant for settings of the project or its modules (e.g. an API url of a module),
     * so the generic deployment does not need to know them.
     *
     * @return array<string, scalar|null>
     * @throws Exception
     */
    protected function getFreeEnvVars(): array
    {
        $vars = $this->getConfigValue('setup.env', []) ?? [];
        if (!is_array($vars)) {
            throw new Exception("'setup.env' must be a map of NAME: value.");
        }

        foreach ($vars as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
                throw new Exception(sprintf("Invalid env var name in 'setup.env': %s", var_export($name, true)));
            }
            if (!is_scalar($value) && $value !== null) {
                throw new Exception("Value of 'setup.env.$name' must be a string, number, bool or empty.");
            }
        }

        return $vars;
    }

    /**
     * Handle automatic port assignment based on YAML configuration.
     *
     * @return array
     */
    protected function handleAutoPorts(): array
    {
        $startPort = (int) $this->getConfigValue('setup.provisioning.auto_port_start', 29000);
        $portVars = $this->getConfigValue('setup.provisioning.auto_port_env', []);

        if (!$startPort || empty($portVars)) {
            $this->error("Invalid port settings in provisioning.");

            return [];
        }

        // remembers the start the ports were calculated with: a new start moves the ports, see keepStickyEnvValues()
        $portOverrides = [self::AUTO_PORT_START_ENV => $startPort];
        $currentPort = $startPort;

        // preparing ports
        foreach ($portVars as $envVar) {
            // Assign current port to the variable and increment
            $currentPort = $this->getNextFreePort($currentPort);
            $portOverrides[$envVar] = $currentPort;
            $currentPort++;
        }

        return $portOverrides;
    }

    /**
     * @param  int  $portStart
     *
     * @return int
     */
    function getNextFreePort(int $portStart): int
    {
        $maxPort = 0xFFFF;
        while ($portStart <= $maxPort) {
            $connection = @fsockopen('localhost', $portStart, $errno, $errorString, 0.1);
            $hasPort = is_resource($connection);

            if (!$hasPort) {

                return $portStart;
            }

            fclose($connection); // close only if port exists
            $portStart++;
        }

        return 0;
    }

    /**
     * To minimize collisions while keeping the string short, generate a secure sha256 hash,
     * output raw binary, and encode it via URL-safe Base64.
     * This condenses a standard 64-character hex hash into 22 characters
     *
     * @param  string|null  $input
     * @param  int          $length
     *
     * @return string
     */
    function getShortSecHash(?string $input = null, int $length = 12): string
    {
        if ($input === null) {
            $input = uniqid('', true);
        }
        $rawHash = hash('sha256', $input, true); // Raw binary output
        $b64 = base64_encode($rawHash);
        $urlSafe = str_replace(['+', '/', '='], ['-', '_', ''], $b64); // Clean symbols

        return substr($urlSafe, 0, $length); // Truncate to desired length
    }

    /**
     * @param $content
     *
     * @return array|string
     */
    public function sanitize($content): array|string
    {
        // our list of "unsafe characters", add/remove characters if necessary
        $dangerousCharacters = [" ", '"', "'", "&", "/", "\\", "?", "#"];

        // every forbidden character is replaced by an underscore
        $safeFilename = str_replace($dangerousCharacters, '_', $content);

        return strtolower($safeFilename);
    }

    /**
     * Resolves the Nginx Proxy Manager intranet IP dynamically from the API URL.
     *
     * @param  string  $apiUrl  Example: 'http://npm.example.com:81/api'
     *
     * @return string Returns the IPv4 address or empty if resolution fails
     */
    function resolveIp(string $apiUrl): string
    {
        // Extract the host part from the URL safely
        $host = parse_url($apiUrl, PHP_URL_HOST);

        if (!$host) {
            return '';
        }

        // Perform a system-level DNS lookup
        $ip = gethostbyname($host);

        // If gethostbyname fails, it returns the original hostname string instead of an IP
        if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }

        return $ip;
    }

    /**
     * @param  string  $content
     *
     * @return void
     */
    public function note(string $content): void
    {
        if (!$this->debug) {
            return;
        }
        note($content);
    }

    /**
     * @param  string  $content
     *
     * @return void
     */
    public function error(string $content): void
    {
        if ($this->runner) {
            $this->runner->message('error', $content);

            return;
        }
        error($content);
    }

    /**
     * Always shown (unlike info, which is only shown in debug mode), e.g. what a step changed on this machine.
     *
     * @param  string  $content
     *
     * @return void
     */
    public function notice(string $content): void
    {
        if ($this->runner) {
            $this->runner->message('info', $content);

            return;
        }
        note($content);
    }

    /**
     * Asks in the terminal; null without a terminal (CI, scripts), so callers fail with instructions instead.
     *
     * @param  string  $question
     *
     * @return string|null the trimmed answer
     */
    public function prompt(string $question, bool $hidden = false): ?string
    {
        if (!stream_isatty(STDIN)) {
            return null;
        }
        $this->runner?->pauseLive();
        try {
            fwrite(STDOUT, $question.' ');
            if ($hidden) {
                // passwords and tokens are not shown while typing
                shell_exec('stty -echo');
            }
            $answer = fgets(STDIN);
        } finally {
            if ($hidden) {
                shell_exec('stty echo');
                fwrite(STDOUT, "\n");
            }
            $this->runner?->resumeLive();
        }

        return $answer === false ? null : trim($answer);
    }

    /**
     * Runs a command and returns exit code and output (stdout and stderr), also on failure.
     *
     * @param  array       $command
     * @param  float|null  $timeout
     *
     * @return array{0: int, 1: string}
     */
    public function captureAll(array $command, ?float $timeout = 10.0, ?string $input = null): array
    {
        $process = new SymfonyProcess(command: $command, cwd: $this->deploymentDestinationRootPath ?: null, env: $this->gitSshEnv());
        $process->setTimeout($timeout);
        // e.g. a token for "docker login --password-stdin" (never as an argument, it would be visible in ps)
        $process->setInput($input);
        try {
            $this->waitFor($process);
        } catch (ProcessTimedOutException) {
            return [124, sprintf('Timeout after %ss: %s', $timeout, $process->getCommandLine())];
        }

        return [(int) $process->getExitCode(), trim($process->getErrorOutput()."\n".$process->getOutput())];
    }

    /**
     * Always shown (unlike info/note), but does not stop the deployment.
     *
     * @param  string  $content
     *
     * @return void
     */
    public function warning(string $content): void
    {
        if ($this->runner) {
            $this->runner->message('warning', $content);

            return;
        }
        warning($content);
    }

    /**
     * @param  string  $filepath
     *
     * @return array
     */
    public function loadYamlConfig(string $filepath): array
    {
        try {
            return Yaml::parseFile($filepath);
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return [];
        }
    }

    /**
     * @param  string  $filepath
     * @param  array   $config
     *
     * @return bool
     */
    public function saveYamlConfig(string $filepath, array $config): bool
    {
        $updatedYamlContent = Yaml::dump($config, 4, 2);

        return (bool) file_put_contents($filepath, $updatedYamlContent);
    }

    /**
     * Get the first required block where type is 'theme' and names are filled.
     *
     * @return array
     */
    public function getFirstRequiredBlockWithThemeNames(): array
    {
        foreach ($this->getConfigValue('setup.requires', []) as $require) {
            if (data_get($require, 'type') === 'theme') {
                if (count(data_get($require, 'names', [])) > 0) {
                    return $require;
                }
            }
        }

        return [];
    }


    /**
     * @return bool
     */
    public function deployComposerUpdate(): bool
    {
        return $this->runComposerUpdate($this->deploymentDestinationRootPath);
    }


    /**
     * @param  string  $key
     * @param  mixed   $default
     *
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->yamlConfigs, $key, $default);
    }

    /**
     * @param  string  $separator
     *
     * @return string
     */
    public function getImageNameVersion(string $separator = ':'): string
    {
        // "kernel.image" if the image needs another name than the app (e.g. "proxtop-web" next to "proxtop-agent")
        $image = trim((string) $this->getConfigValue('setup.kernel.image', '')) ?: $this->getConfigValue('setup.kernel.name');

        return $image.$separator.$this->getAppVersion();
    }

    /**
     * The one version of the app, used for APP_VERSION, the image tag and build.lock.yaml:
     * - build: "setup.kernel.version" of setup-build.yaml, the release number of the image
     * - production: "setup.kernel.version" of the installer config, the release to run (required)
     * - dev: no fixed number, "git describe" of the project shows the code that really runs,
     *   e.g. "v2.0.1-rc1-3-g1a2b3c4-dirty" ("dev" without git)
     *
     * @return string
     * @throws Exception
     */
    public function getAppVersion(): string
    {
        $version = trim((string) $this->getConfigValue('setup.kernel.version', ''));
        if ($version !== '') {
            return $version;
        }
        if (!$this->versionFromGit) {
            throw new Exception("Missing 'setup.kernel.version' in setup.yaml (the release to run).");
        }

        $root = $this->deploymentDestinationRootPath;

        return $this->gitVersions[$root] ??= trim((string) $this->capture(['git', 'describe', '--tags', '--always', '--dirty'], $root)) ?: 'dev';
    }

    /**
     * Docker allows letters, digits, "_", "." and "-" (not first), max 128 chars.
     *
     * @param  string  $tag
     *
     * @return bool
     */
    public static function isValidImageTag(string $tag): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag);
    }

    /**
     * Recalculates the calculated values (version, image, ...) in an existing env file in place.
     * Unlike {@see self::prepareEnvFile()} nothing is copied, so values written by hand stay.
     *
     * @param  string  $envFile  full path
     *
     * @return bool
     * @throws Exception
     */
    public function refreshEnvFile(string $envFile): bool
    {
        if (!is_file($envFile)) {
            $this->error("Env file not found: $envFile (run the install first).");

            return false;
        }

        return $this->saveEnvBySections($envFile);
    }

    /**
     * @return string
     */
    public function getImageName(): string
    {
        return $this->getImageNameVersion();
    }

    /**
     * @return string
     */
    public function getImageSaveBaseFile(): string
    {
        return $this->getImageNameVersion('-');
    }

    /**
     * Git url of the scaffold that is cloned into the build folder.
     * Uses "setup.git.scaffold" if set, otherwise "<git.source>:<git.namespace>/mercy-scaffold.git".
     *
     * @return string
     * @throws Exception
     */
    public function getScaffoldRepository(): string
    {
        if ($url = $this->getConfigValue('setup.git.scaffold', '')) {
            return $url;
        }

        $source = $this->getConfigValue('setup.git.source', '');
        $namespace = $this->getConfigValue('setup.git.namespace', '');
        if (!$source || !$namespace) {
            throw new Exception("Missing 'setup.git.source' or 'setup.git.namespace' (or 'setup.git.scaffold') in setup.yaml.");
        }

        return "{$source}:{$namespace}/mercy-scaffold.git";
    }

    /**
     * @return string
     */
    public function getImageFullTag(): string
    {
        $giteaDomain = $this->getConfigValue('setup.git.registry_source');
        $owner = $this->getConfigValue('setup.git.registry_namespace');
        $imageName = $this->getImageName();

        return "{$giteaDomain}/{$owner}/$imageName";
    }
}