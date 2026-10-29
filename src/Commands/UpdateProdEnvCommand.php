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

#[AsCommand(name: 'update', description: 'Update the docker image.', help: 'Download and update the docker image and refresh the app.')]
class UpdateProdEnvCommand extends BaseCommand
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
        // array_filter to remove all null values automatically (the conditional entries) by array_filter
        return array_filter([
            [
                'name'  => 'check_host_php',
                'label' => 'Checking host PHP version',
                'task'  => function () use ($deployment): bool {
                    $current = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
                    if ($current !== $this->requiredPhp) {
                        $deployment->error("Host PHP is {$current}, but the runtime image uses {$this->requiredPhp}.");

                        return false;
                    }

                    return true;
                },
            ],
            [
                'name'  => 'check_platform_requirements',
                'label' => 'Checking platform requirements',
                'task'  => fn(): bool => $deployment->runInBuildFolder(['composer', 'check-platform-reqs', '--lock']),

            ],
            [
                // a private image needs a login, the tool asks in a terminal
                'name'  => 'registry_login',
                'label' => 'Checking the docker registry login',
                'task'  => fn(): bool => $deployment->hostSetup()->registryLogin(false),
            ],
            [
                'name'  => 'pull_docker_image',
                'label' => 'Pull Image ...',
                'task'  => fn(): bool => $deployment->runDockerPullImage($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'fetch_release_files',
                'label' => 'Copy compose.yaml and templates out of the image ...',
                'task'  => fn(): bool => $deployment->fetchReleaseFiles($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'migrate_layout',
                'label' => 'Checking the installer layout',
                'task'  => fn(): bool => $deployment->migrateLegacyLayout($deployment->deploymentDestinationRootPath),
            ],
            [
                // version, image and the other calculated values follow setup.yaml,
                // otherwise compose would start the image of the old .env
                'name'  => 'refresh_env',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label' => 'Refreshing calculated .env values',
                'task'  => fn(): bool => $deployment->refreshEnvFile($deployment->configPath.'/setup-production.env')
                                         && $deployment->refreshEnvFile($deployment->deploymentDestinationRootPath.'/.env'),
            ],
            [
                'name'  => 'start_containers',
                'label' => 'Build containers ...',
                'task'  => fn(): bool => $deployment->runDockerBuild($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'link_storage',
                'label' => 'Linking Storage ...',
                'task'  => fn(): bool => $deployment->runArtisanStorageLink($deployment->deploymentDestinationRootPath),
            ],
            [
                // ports, ip or npm.forward may have changed, the proxy has to follow (creates or updates the hosts)
                'name'  => 'sync_npm',
                'label' => 'Sync Nginx Proxy Manager ...',
                'task'  => fn(): bool => $deployment->runMercySyncNpm($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'start_migration',
                'label' => 'Migration / Seeding / Warm Cache ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'migrate,cache,view'),
            ],
            [
                'label' => 'Config / Cache ...',
                'task'  => fn(): bool => $deployment->runConfigCache($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'show_status',
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
        $deployment = new DockerProcessDeployment();
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
