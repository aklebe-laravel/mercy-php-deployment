<?php

namespace AklebeLaravel\MercyPhpDeployment;

use AklebeLaravel\MercyPhpDeployment\Commands\BuildProdEnvCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\ConfigShowCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\DestroyCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\HostSetupCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\InstallDevEnvCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\InstallProdEnvCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\ProjectInitCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\UpdateDevEnvCommand;
use AklebeLaravel\MercyPhpDeployment\Commands\UpdateProdEnvCommand;
use Exception;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class MercyApp
{
    /**
     * @var string|null project repository, see {@see ProjectConfig}
     */
    public ?string $projectDir = null;

    /**
     * @var array<string, string> stage => file name in the config dir, e.g. ['dev' => 'setup.yaml']
     */
    public array $projectStages = [];

    /**
     * File in the project folder that remembers the instance of this installation (not versioned).
     */
    public const string INSTANCE_FILE = '.instance';

    /**
     * Instance chosen for this run (--instance), otherwise the remembered one.
     */
    private ?string $instance = null;

    /**
     * @param  string  $deploymentDestinationDir  Destination directory
     * @param  string  $configTemplateDir         config templates used for this process
     * @param  string  $configDir                 final configs used for this process (generated from the project)
     */
    public function __construct(public string $deploymentDestinationDir, public string $configTemplateDir, public string $configDir)
    {
    }

    /**
     * The project repository that holds the configs, e.g.
     * ->withProject(__DIR__.'/project', ['dev' => 'setup.yaml', 'build' => 'setup-build.yaml'])
     *
     * @param  string                 $projectDir
     * @param  array<string, string>  $stages  stage => file name in the config dir (the first one is the default stage)
     *
     * @return $this
     */
    public function withProject(string $projectDir, array $stages): static
    {
        $this->projectDir = $projectDir;
        $this->projectStages = $stages;

        return $this;
    }

    /**
     * Writes the merged project configs into the config dir. Called before every deployment command.
     *
     * @return void
     * @throws Exception
     */
    public function applyProject(): void
    {
        if ($this->projectDir === null) {
            return;
        }
        if (!is_dir($this->projectDir)) {
            throw new Exception(sprintf("No project found in '%s'. Clone your project repository there%s.",
                $this->projectDir,
                is_dir($this->getProjectTemplateDir()) ? ' or create a new one with "php mercy.php project:init"' : ''));
        }

        $project = $this->projectConfig();
        if ($project->instance === null && ($instances = $project->instances())) {
            throw new Exception(sprintf("This project has instances (%s). Choose the one of this installation once: php %s %s --instance=<name>",
                implode(', ', $instances),
                basename($_SERVER['SCRIPT_NAME'] ?? 'mercy.php'),
                $_SERVER['argv'][1] ?? '<command>'));
        }
        $project->apply($this->projectStages, $this->configDir);

        // which config this run uses: the mix-up of two configs is the most expensive mistake
        $merged = $project->merged((string) array_key_first($this->projectStages));
        note(sprintf('Project %s%s, stage %s',
            $merged['kernel']['name'] ?? '?',
            $project->instance !== null ? ", instance {$project->instance}" : '',
            implode(' + ', array_keys($this->projectStages))));

        // typos and old key names would be ignored silently otherwise
        $messages = $project->warnings();
        foreach (array_keys($this->projectStages) as $stage) {
            foreach ($project->unknownKeys($stage) as $key => $label) {
                $messages[] = "Unknown config key '$key' in $label (typo or old name? see: php mercy.php config --all).";
            }
        }
        foreach (array_unique($messages) as $message) {
            warning($message);
        }
    }

    /**
     * The project config of this installation (with the instance, if any).
     *
     * @return ProjectConfig
     */
    public function projectConfig(): ProjectConfig
    {
        return new ProjectConfig((string) $this->projectDir, null, $this->currentInstance());
    }

    /**
     * @return string|null
     */
    public function currentInstance(): ?string
    {
        if ($this->instance !== null) {
            return $this->instance;
        }
        $file = rtrim((string) $this->projectDir, '/').'/'.self::INSTANCE_FILE;
        $name = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $name !== '' ? $name : null;
    }

    /**
     * Chooses the instance (config/instances/<name>.yaml) and remembers it in project/.instance,
     * so later commands need no option.
     *
     * @param  string  $name
     * @param  bool    $remember  false: only for this run (e.g. "config --instance=x" to have a look)
     *
     * @return void
     * @throws Exception
     */
    public function selectInstance(string $name, bool $remember = true): void
    {
        new ProjectConfig((string) $this->projectDir)->instanceFile($name);
        $this->instance = $name;
        $file = rtrim((string) $this->projectDir, '/').'/'.self::INSTANCE_FILE;
        if ($remember && (!is_file($file) || trim((string) file_get_contents($file)) !== $name)) {
            file_put_contents($file, $name."\n");
            note("Instance '$name' saved in ".basename((string) $this->projectDir).'/'.self::INSTANCE_FILE.', later commands use it without --instance.');
        }
    }

    /**
     * Console with the options every command knows.
     *
     * @param  string  $name
     *
     * @return Application
     */
    private function console(string $name): Application
    {
        $console = new Application($name, '1.0.0');
        $console->getDefinition()
            ->addOption(new InputOption('instance', null, InputOption::VALUE_REQUIRED, 'Instance of the project (config/instances/<name>.yaml), remembered in project/'.self::INSTANCE_FILE, null,
                // tab completion (./mercy.php install --instance=<tab>), installed by host:setup
                fn(): array => $this->projectDir !== null && is_dir($this->projectDir) ? new ProjectConfig($this->projectDir)->instances() : []));

        return $console;
    }

    /**
     * Template of a new project, shipped by the scaffold (the installer has none).
     *
     * @return string
     */
    public function getProjectTemplateDir(): string
    {
        return rtrim($this->configTemplateDir, '/').'/project';
    }

    /**
     * @return int
     * @throws Exception
     */
    public function runDev(): int
    {
        $console = $this->console('Dev Deployment CLI Tool');
        $console->addCommand(new InstallDevEnvCommand($this));
        $console->addCommand(new UpdateDevEnvCommand($this));
        $console->addCommand(new ProjectInitCommand($this));
        $console->addCommand(new ConfigShowCommand($this));
        $console->addCommand(new DestroyCommand($this));
        $console->addCommand(new HostSetupCommand($this));

        return $console->run();
    }

    /**
     * @return int
     * @throws Exception
     */
    public function runDevBuildToProd(): int
    {
        $console = $this->console('Dev Deployment CLI Tool');
        $console->addCommand(new BuildProdEnvCommand($this));
        $console->addCommand(new ConfigShowCommand($this));
        $console->addCommand(new HostSetupCommand($this));

        return $console->run();
    }

    /**
     * @return int
     * @throws Exception
     */
    public function runProd(): int
    {
        $console = $this->console('Production Deployment CLI Tool');
        $console->addCommand(new InstallProdEnvCommand($this));
        $console->addCommand(new UpdateProdEnvCommand($this));
        $console->addCommand(new ConfigShowCommand($this));
        $console->addCommand(new DestroyCommand($this));

        return $console->run();
    }
}
