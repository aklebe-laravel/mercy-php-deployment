<?php

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\DockerProcessDeployment;
use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'create', description: 'Build the production docker image.', help: 'Build the docker image for production stage.')]
class BuildProdEnvCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('step', InputArgument::OPTIONAL, 'Step number or name to start with (default: 1)')
            ->addOption('info', 'i', InputOption::VALUE_NONE, 'Show list of steps');
    }

    /**
     * @return array[]
     */
    protected function getDeploymentSteps(DockerProcessDeployment|SailProcessDeployment $deployment): array
    {
        $devConfigSource = $this->app->configDir;
        $buildDestination = $this->app->deploymentDestinationDir;
        $dockerfilePath = 'docker/app/Dockerfile';
        // Where the yamls must be inside the build folder so that `mercy:app require` finds them.
        $buildConfigDestination = $buildDestination.'/storage/app/last-setup';


        // array_filter to remove all null values automatically (the conditional entries) by array_filter
        return array_filter([
            [
                // loads the dev config (version, image name, ...), so it runs also when resuming at a later step
                'name'   => 'init_host_setup',
                'label'  => 'Init Host Setup',
                'always' => true,
                'task'   => function () use ($deployment): bool {

                    // init setup from this location (probably dev env)
                    $deployment->initSetup($this->app->deploymentDestinationDir, $this->app->configTemplateDir, $this->app->configDir);

                    return true;
                },
            ],
            [
                // git url rewrites, known_hosts, deploy key and access of this machine (see HostSetup)
                'name'  => 'setup_host',
                'label' => 'Preparing this machine (git, ssh)',
                'task'  => fn(): bool => $deployment->setupHost(),
            ],
            [
                // checked before the build, not only at the push after several minutes
                // (inside the task: the config is loaded by init_host_setup, not yet when the steps are listed)
                'name'  => 'registry_login',
                'label' => 'Checking the docker registry login',
                'task'  => fn(): bool => !$deployment->getConfigValue('setup.build_steps.use_registry', true)
                                         || $deployment->hostSetup()->registryLogin(true),
            ],
            [
                'name'  => 'check_host_php',
                'label' => 'Checking host PHP version',
                'task'  => function () use ($deployment): bool {
                    // Must match the PHP version of the runtime image (see Dockerfile).
                    $current = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
                    if ($current !== $this->requiredPhp) {
                        $deployment->error("Host PHP is {$current}, but the runtime image uses {$this->requiredPhp}.");

                        return false;
                    }

                    return true;
                },
            ],
            [
                'name'  => 'cleanup_build',
                'label' => 'Cleanup build folder',
                'task'  => function () use ($deployment, $buildDestination): bool {
                    $deployment->filesystem->remove($buildDestination);

                    return $deployment->checkDir($buildDestination, true);
                },
            ],
            [
                'name'  => 'clone_app',
                'label' => 'Cloning the app',
                'task'  => fn(): bool => $deployment->run([
                    'git',
                    'clone',
                    $deployment->getScaffoldRepository(),
                    '.',
                ], $buildDestination),
            ],
            [
                // switches to the build config; also when resuming (after "clone_app" the build folder exists)
                'name'   => 'init_target_setup',
                'label'  => 'Init Target Setup',
                'always' => true,
                'task'   => function () use ($deployment, $devConfigSource, $buildConfigDestination, $buildDestination): bool {

                    // from here make a target (build) setup
                    // use the "setup-build.yaml" defined in your current env as the "setup.yaml" for the build
                    $deployment->filesystem->copy($devConfigSource.'/setup-build.yaml', $buildConfigDestination.'/setup.yaml', true);
                    $deployment->filesystem->copy($devConfigSource.'/setup-build.yaml', $buildConfigDestination.'/setup-build.yaml', true);

                    // reinit setup with build locations
                    $deployment->initSetup($buildDestination, $buildDestination.'/resources/setup', $buildDestination.'/storage/app/last-setup');

                    return true;
                },
            ],
            [
                'name'  => 'check_platform_requirements',
                'label' => 'Checking platform requirements',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['composer', 'check-platform-reqs', '--lock']),
            ],
            [
                'name'  => 'copy_root_files',
                'label' => 'Copy root files',
                'task'  => fn(): bool => $deployment->copyDirectory($deployment->deploymentDestinationRootPath.'/install/project-root', $deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'prepare_env',
                'label' => 'Preparing .env',
                'task'  => fn(): bool => $deployment->prepareEnvFile('setup-production.env', $deployment->deploymentDestinationRootPath.'/.env'),
            ],
            [
                'name'  => 'get_base_requirements',
                'label' => 'Fetching base modules and theme',
                'task'  => fn(): bool => $deployment->deployBaseModulesAndTheme(),
            ],
            [
                'name'  => 'composer_install_1',
                'label' => 'Composer install (root)',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['composer', 'install', '--no-dev', '--no-scripts']),
            ],
            [
                'name'  => 'get_mercy_requirements',
                'label' => 'Fetching optional modules',
                'task'  => fn(): bool => $deployment->runInBuildFolder([$this->phpBinary, 'artisan', 'mercy:app', 'require']),
            ],
            //[
            //    'name'  => 'stop',
            //    'label' => 'Stop Build!',
            //    'task'  => fn(): bool => false,
            //],
            [
                // Safety net: with --no-dev Composer only autoloads packages that are reachable from the root
                // requirements. Packages required only by modules are installed (they are in the lock) but would
                // be left out, which makes artisan fail with "Class ... not found" while booting.
                // Dumping with --dev autoloads everything that is installed in vendor/.
                'name'  => 'composer_autoload',
                'label' => 'Composer autoload',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['composer', 'dump-autoload', '--dev', '--no-scripts']),
            ],
            [
                'name'  => 'composer_install_2',
                'label' => 'Composer install (module dependencies)',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['composer', 'install', '--no-dev', '--optimize-autoloader']),
            ],
            [
                'name'  => 'npm_ci',
                'label' => 'npm ci',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['npm', 'ci']),
            ],
            //[
            //    'name'  => 'build_frontend',
            //    'label' => 'Building frontend ...',
            //    'task'  => fn(): bool => $deployment->runInBuildFolder([$php, 'artisan', 'deploy-env:build-frontend']),
            //],
            [
                'name'  => 'build_mercy_assets',
                'label' => 'Building mercy assets',
                'task'  => fn(): bool => $deployment->runInBuildFolder([$this->phpBinary, 'artisan', 'deploy-env:build-mercy-assets']),
            ],
            [
                'name'  => 'npm_run',
                'label' => 'npm run build',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['npm', 'run', 'build']),
            ],
            [
                'name'  => 'write_build_lock',
                'label' => 'Writing build.lock.yaml',
                'task'  => fn(): bool => $deployment->writeBuildLock($buildDestination),
            ],
            [
                // Safety net in addition to the .dockerignore: these must never end up in the image.
                'name'  => 'cleanup_after_build',
                'label' => 'Removing build-only files',
                'task'  => function () use ($deployment, $buildDestination, $buildConfigDestination): bool {
                    $deployment->filesystem->remove([
                        $buildDestination.'/.env',
                        $buildDestination.'/node_modules',
                        $buildConfigDestination,
                    ]);

                    return true;
                },
            ],
            [
                'name'  => 'build_image',
                'label' => 'Build the image',
                'task'  => fn(): bool => $deployment->buildDockerImage($dockerfilePath),
            ],
            [
                'label' => 'Save the image',
                'name'  => 'save_image',
                'task'  => fn(): bool => $deployment->saveDockerImage(),
            ],
            [
                'name'  => 'create_bundle',
                'label' => 'Create a bundle',
                'task'  => function () use ($deployment) {
                    if ($deployment->getConfigValue('setup.build_steps.create_bundle')) {
                        return $deployment->createBundle();
                    }

                    return true;
                },
            ],
            [
                'name'  => 'push_to_registry',
                'label' => 'Push to registry',
                'task'  => function () use ($deployment) {
                    if ($deployment->getConfigValue('setup.build_steps.use_registry')) {
                        return $deployment->pushToDockerRegistry();
                    }

                    return true;
                },
            ],
        ]);
    }

    /**
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     *
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deployment = new SailProcessDeployment();
        $deployment->initSetup($this->app->deploymentDestinationDir, $this->app->configTemplateDir, $this->app->configDir);

        $steps = $this->getDeploymentSteps($deployment);
        if ($this->checkInfoOption($input, $deployment)) {

            return Command::SUCCESS;

        } else {

            $name = $input->getArgument('step') ?? 1;
            //$deployment->info("OK: $name");
            $deployment->deploySteps($steps, $name);

        }

        return Command::SUCCESS;
    }
}
