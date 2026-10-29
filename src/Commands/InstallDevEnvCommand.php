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

#[AsCommand(name: 'install', description: 'Install the docker image.', help: 'Download and install the docker image. Also setup the new app.')]
class InstallDevEnvCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            // Ein Pflichtargument (Name der Person)
            ->addArgument('step', InputArgument::OPTIONAL, 'Step number or name to start with (default: 1)')
            // Eine optionale Flagge (z.B. --uppercase)
            ->addOption('info', 'i', InputOption::VALUE_NONE, 'Show list of steps');
    }

    /**
     * @return array[]
     */
    protected function getDeploymentSteps(DockerProcessDeployment|SailProcessDeployment $deployment): array
    {
        // array_filter to remove all null values automatically (the conditional entries) by array_filter
        return array_filter([
            [
                'name'  => 'check_dev_stage',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Checking the dev stage ...',
                'task'  => fn(): bool => $deployment->checkDevStage(),
            ],
            [
                // git url rewrites, known_hosts, deploy key and access of this machine (see HostSetup)
                'name'  => 'setup_host',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Preparing this machine (git, ssh) ...',
                'task'  => fn(): bool => $deployment->setupHost(),
            ],
            [
                'name'  => 'copy_root_files',
                'label' => 'Copy root files ...',
                'task'  => fn(): bool => $deployment->copyDirectory($this->app->deploymentDestinationDir.'/install/project-root', $deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'preparing_env',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Preparing .env.minimal ...',
                'task'  => fn(): bool => $deployment->prepareEnvFile('.env.minimal', $deployment->deploymentDestinationRootPath.'/.env.minimal'),
            ],
            [
                'name'  => 'copy_ssh',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Copy ssh stuff ...',
                'task'  => fn(): bool => $deployment->syncDockerSsh(),
            ],
            [
                'name'  => 'clone_dependencies',
                'label' => 'Cloning Modules & themes ...',
                'task'  => fn(): bool => $deployment->deployBaseModulesAndTheme(),
            ],
            [
                'name'  => 'composer_update',
                'label' => 'Running Composer Update ...',
                'task'  => fn(): bool => $deployment->deployComposerUpdate(),
            ],
            [
                'name'  => 'setup_env',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Setup Environment Configs ...',
                'task'  => fn(): bool => $deployment->prepareEnvFile("setup-local.env",
                    $deployment->deploymentDestinationRootPath.'/.env'),
            ],
            [
                'name'  => 'build_containers',
                'label' => 'Build containers ...',
                'task'  => fn(): bool => $deployment->runSailBuild($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'sail_up',
                'label' => 'Sailing ... (starting docker)',
                'task'  => fn(): bool => $deployment->runSailUpDaemon($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'generate_key',
                'label' => 'Generate Key by Artisan ...',
                'task'  => fn(): bool => $deployment->runArtisanKeyGenerate($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'link_storage',
                'label' => 'Linking Storage ...',
                'task'  => fn(): bool => $deployment->runArtisanStorageLink($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => '_sync_npm',
                'label' => 'Sync Nginx Proxy Manager ...',
                'task'  => fn(): bool => $deployment->runMercySyncNpm($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'get_requires',
                'label' => 'Clone Requirements ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'require'),
            ],
            [
                'name'  => 'update',
                'label' => 'Updating System, Clearing Caches, Build Frontend ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'update'),
            ],
            [
                'name'  => 'migrate',
                'label' => 'Migration / Seeding ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'migrate'),
            ],
            [
                'name'  => 'health_check',
                'label' => 'Health Check ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'healthy'),
            ],
            [
                'name'  => 'summary',
                'label' => 'Summary ...',
                'task'  => fn(): bool => $deployment->runMercyInfo($deployment->deploymentDestinationRootPath),
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
