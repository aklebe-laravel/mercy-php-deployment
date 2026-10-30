<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

use Exception;
use Illuminate\Support\Str;
use ZipArchive;

class SailProcess extends Process
{
    /**
     * Dev and build: without "setup.kernel.version" the version comes from git.
     *
     * @var bool
     */
    protected bool $versionFromGit = true;

    /**
     * The dev stage must run composer with dev packages, otherwise the dev environment removes its own
     * tools (sail, ignition, ...) while it runs (see DeployEnv "mercy:app update").
     *
     * @return bool
     */
    public function checkDevStage(): bool
    {
        if (filter_var($this->getConfigValue('setup.kernel.dev_mode', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $this->error("The dev stage has 'kernel.dev_mode' not set to true. Set 'kernel.dev_mode: true' in project/config/dev.yaml "."(check it with: ./mercy config kernel).");

        return false;
    }

    /**
     * @var array
     */
    public array $dockerComposeCommand = ['./vendor/bin/sail'];

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runSailBuild(string $rootDir, bool $noCache = true): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'build',
            ...($noCache ? ['--no-cache'] : []),
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runSailUpDaemon(string $rootDir): bool
    {
        if (!$this->ensureExternalNetworks($rootDir)) {
            return false;
        }
        $command = [
            ...$this->dockerComposeCommand,
            'up',
            '-d',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runArtisanKeyGenerate(string $rootDir): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'key:generate',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runArtisanStorageLink(string $rootDir): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'storage:link',
            // idempotent: an existing link is recreated instead of reported as error
            '--force',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runMercySyncNpm(string $rootDir): bool
    {
        if (!$this->isNpmEnabled()) {
            $this->info('Nginx Proxy Manager is disabled (npm.enabled), skipped.');

            return true;
        }
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'mercy:sync-npm',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     * @param  string  $flowCmd
     *
     * @return bool
     */
    public function runMercyFlow(string $rootDir, string $flowCmd): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'mercy:app',
            $flowCmd,
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runConfigCache(string $rootDir): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'config:cache',
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runMercyInfo(string $rootDir): bool
    {
        $command = [
            ...$this->dockerComposeCommand,
            'artisan',
            'mercy:info',
        ];

        return $this->run($command, $rootDir, showOutput: true);
    }

    /**
     * Builds the production image from the FINISHED build folder.
     * No SSH, no .env and no module sync happen in the Docker build anymore.
     *
     * @param  string  $dockerfilePath  relative to the build folder
     *
     * @return bool
     */
    public function buildDockerImage(string $dockerfilePath): bool
    {
        // a release image needs an explicit number, never a git fallback like "v2.0.0-3-gabc-dirty"
        $version = trim((string) $this->getConfigValue('setup.kernel.version', ''));
        if ($version === '' || !self::isValidImageTag($version)) {
            $this->error("Set a valid 'kernel.version' in setup-build.yaml (letters, digits, '.', '_', '-'), got '$version'.");

            return false;
        }

        $imageName = $this->getImageName();
        $this->info("Starting docker Build for: {$imageName}");
        $commit = trim((string) $this->capture(['git', 'rev-parse', 'HEAD'], $this->deploymentDestinationRootPath));

        return $this->runInBuildFolder([
            'docker',
            'buildx',
            'build',
            '--load',
            '--progress=plain',
            '--target',
            'production',
            // standard labels: "docker image inspect" shows which release and commit an image is
            '--label',
            'org.opencontainers.image.version='.$version,
            '--label',
            'org.opencontainers.image.revision='.$commit,
            '--label',
            'org.opencontainers.image.created='.gmdate('Y-m-d\TH:i:s\Z'),
            '-t',
            $imageName,
            '-f',
            $dockerfilePath,
            '.',
        ]);
    }

    /**
     * @return bool
     */
    public function saveDockerImage(): bool
    {
        $imageName = $this->getImageName();
        $tarPath = $this->deploymentDestinationRootPath.'/'.$this->getImageSaveBaseFile().'.tar';
        $this->info(sprintf("Export Image to '%s.gz' ...", $tarPath));

        return $this->runInBuildFolder(['docker', 'save', $imageName, '-o', $tarPath])
               && $this->runInBuildFolder(['gzip', '-f', $tarPath]); // erzeugt <name>.tar.gz und löscht die .tar
    }

    /**
     * @return bool
     */
    public function createBundle(): bool
    {
        $zipFilename = "storage/docker-builds/".$this->getImageSaveBaseFile().'.zip';
        $exportFilename = $this->getImageSaveBaseFile().'.tar.gz';
        $exportPath = "storage/docker-builds/".$exportFilename;
        $this->info(sprintf("Creating bundle '%s' ...", $zipFilename));

        if (!class_exists(ZipArchive::class)) {
            $this->error('PHP extension "zip" is required to create a bundle (e.g. "sudo apt install php8.5-zip").');

            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Unable to create bundle: {$zipFilename}");

            return false;
        }
        if (!$zip->addFile($exportPath, $exportFilename)) {
            $zip->close();
            $this->error("Unable to add '{$exportPath}' to bundle.");

            return false;
        }
        //$zip->addFile('docker-compose.prod.yml');
        //$zip->addFile('mercy-setup.yaml');

        return $zip->close();
    }

    /**
     * @return bool
     */
    public function pushToDockerRegistry(): bool
    {
        $fullTag = $this->getImageFullTag();
        $imageName = $this->getImageName();

        $this->info("Tagging Image: {$fullTag}");
        if (!$this->runInBuildFolder(['docker', 'tag', $imageName, $fullTag])) {
            $this->error("Failed to tag docker in registry.");

            return false;
        }

        $this->info("Moving image to registry ...");
        if (!$this->runInBuildFolder(['docker', 'push', $fullTag])) {
            $this->error("Failed to push image to registry.");

            return false;
        }

        $this->info("Image moved to {$fullTag}.");

        return true;
    }

    /**
     * @param  string  $themeGit
     *
     * @return string
     */
    public function getThemeDir(string $themeGit): string
    {
        return Str::snake($themeGit)
               |> (fn($x) => str_replace('_', '-', $x))
               |> (fn($x) => str_replace('-theme', '', $x));
    }

    /**
     * Mercy core from "core.yaml" next to the config templates (resources/setup/core.yaml).
     * The core is part of the scaffold version and is not copied to the user config,
     * so projects can not change it by accident.
     *
     * @return array{modules: list<array{name: string, directory: string}>, default_theme: string}
     * @throws Exception
     */
    public function getCoreConfig(): array
    {
        $file = $this->configTemplatesPath.'/core.yaml';
        if (!is_file($file)) {
            throw new Exception("Mercy core config not found: $file");
        }

        $core = $this->loadYamlConfig($file)['core'] ?? [];
        $modules = [];
        foreach ($core['modules'] ?? [] as $module) {
            $name = is_array($module) ? ($module['name'] ?? '') : (string) $module;
            if (!str_ends_with($name, '-module')) {
                throw new Exception("Invalid core module '$name' in $file (name must end with '-module').");
            }
            // "system-base-module" -> "Modules/SystemBase", unless "directory" is given
            $directory = is_array($module) && !empty($module['directory']) ? $module['directory'] : 'Modules/'.Str::studly(substr($name, 0, -strlen('-module')));
            $modules[] = ['name' => $name, 'directory' => $directory];
        }

        $defaultTheme = (string) ($core['default_theme'] ?? '');
        if (!$modules || !str_ends_with($defaultTheme, '-theme')) {
            throw new Exception("Invalid $file: 'core.modules' and 'core.default_theme' (ending with '-theme') are required.");
        }

        return ['modules' => $modules, 'default_theme' => $defaultTheme];
    }

    /**
     * Clones the Mercy core modules and the theme. They are needed before anything else,
     * because "artisan mercy:app require" (which clones all other modules) lives in DeployEnv.
     * The theme is the first theme of "setup.requires" (then "setup.base_requires"), otherwise the core default theme.
     * Each one starts at the ref of initialRef() (tag, branch).
     *
     * @return bool
     * @throws Exception
     */
    public function deployBaseModulesAndTheme(): bool
    {
        $gitPath = $this->getConfigValue('setup.git.source');
        $gitNamespace = $this->getConfigValue('setup.git.namespace');
        $core = $this->getCoreConfig();

        $themeGit = data_get($this->getFirstRequiredBlockWithThemeNames(), 'names.0', $core['default_theme']);
        $gitCloneList = [
            ...$core['modules'],
            ['name' => $themeGit, 'directory' => 'themes/'.$this->getThemeDir($themeGit)],
        ];

        foreach ($gitCloneList as $gitClone) {

            $targetDir = $this->deploymentDestinationRootPath.'/'.$gitClone['directory'];
            if (!$this->checkDir($targetDir, true)) {
                $this->error("Unable to create directory: $targetDir");

                return false;
            }

            // if .git already exist, skip ...
            if ($this->checkDir("$targetDir/.git")) {
                continue;
            }

            $path = "$gitPath:$gitNamespace/{$gitClone['name']}.git";
            // the same start as "mercy:app require" would choose: dev branch, release tag or branch of its block
            $ref = $this->initialRef($gitClone['name'], $path);
            if (!$this->run(['git', 'clone', ...($ref !== null ? ['--branch', $ref] : []), $path, '.'], $targetDir)) {
                $this->error("Unable to clone '$path' into $targetDir");

                return false;
            }
        }

        return true;
    }
}
