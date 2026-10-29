<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

class DockerProcess extends Process
{
    /**
     * @var array
     */
    public array $dockerComposeCommand = ['docker', 'compose'];

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runDockerPullImage(string $rootDir): bool
    {
        $fullTag = $this->getImageFullTag();

        # docker pull $IMAGE
        $command = [
            'docker',
            'pull',
            $fullTag,
        ];

        return $this->run($command, $rootDir);
    }

    /**
     * Files that belong to the image version: image path (below /var/www/html) => local target.
     *
     * The installer uses the same layout as the scaffold: the templates land in "resources/setup"
     * ("/." copies the content of the folder: env templates, core.yaml, project template).
     *
     * @param  string  $rootDir       installer root
     * @param  string  $templatesDir  installer config templates (resources/setup)
     *
     * @return array<string, string>
     */
    public static function releaseFileMap(string $rootDir, string $templatesDir): array
    {
        return [
            'resources/production/compose.yaml' => $rootDir.'/compose.yaml',
            'resources/setup/.'                 => $templatesDir,
        ];
    }

    /**
     * One-time move of the generated prod env of the old installer layout (install/config/.env)
     * to the new config dir (storage/app/last-setup/setup-production.env). It holds the fixed
     * instance key, project name and ports, so it must not be recreated.
     *
     * @param  string  $rootDir
     *
     * @return bool
     * @todo remove after all installers use the new layout (v2 go-live)
     *
     */
    public function migrateLegacyLayout(string $rootDir): bool
    {
        $old = $rootDir.'/install/config/.env';
        $new = $this->configPath.'/setup-production.env';
        if (is_file($old) && !is_file($new)) {
            $this->filesystem->copy($old, $new);
            $this->warning("Moved '$old' to '$new' (new installer layout). Delete the old 'install' folder after a successful update.");
        }

        return true;
    }

    /**
     * Copies compose.yaml and the templates out of the pulled image
     * (without starting a container). They are overwritten every time, the user config
     * in the config dir is never touched.
     *
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function fetchReleaseFiles(string $rootDir): bool
    {
        $image = $this->getImageFullTag();
        $containerId = trim((string) $this->capture(['docker', 'create', $image], $rootDir, 120));
        if ($containerId === '') {
            $this->error("Unable to create a container from '$image' to copy the release files.");

            return false;
        }

        try {
            $this->filesystem->mkdir($this->configTemplatesPath);
            foreach (self::releaseFileMap($rootDir, $this->configTemplatesPath) as $source => $target) {
                if (!$this->run(['docker', 'cp', "$containerId:/var/www/html/$source", $target], $rootDir)) {
                    $this->error("Unable to copy '$source' out of '$image'.");

                    return false;
                }
            }
        } finally {
            $this->capture(['docker', 'rm', $containerId], $rootDir, 60);
        }

        return true;
    }

    /**
     * @param  string  $rootDir
     *
     * @return bool
     */
    public function runDockerBuild(string $rootDir): bool
    {
        if (!$this->ensureExternalNetworks($rootDir)) {
            return false;
        }
        $command = [
            ...$this->dockerComposeCommand,
            'up',
            '-d',
            '--force-recreate',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
            'artisan',
            'key:generate',
            '--no-interaction',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
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
            'exec',
            '-T',
            $this->appServiceName,
            'php',
            'artisan',
            'mercy:info',
        ];

        return $this->run($command, $rootDir, showOutput: true);
    }
}