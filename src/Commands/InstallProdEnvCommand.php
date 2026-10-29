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
class InstallProdEnvCommand extends BaseCommand
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
                'task'  => fn(): bool => $deployment->hostSetup()
                    ->registryLogin(false),
            ],
            [
                // ./mercy install --instance=<tab> (see HostSetup::shellCompletion())
                'name'  => 'shell_completion',
                'label' => 'Tab completion (bash)',
                'task'  => fn(): bool => $deployment->hostSetup()
                    ->shellCompletion(),
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
                'name'   => 'prepare_env',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label'  => 'Preparing .env',
                'task'   => fn(): bool => $deployment->prepareEnvFile('setup-production.env', $deployment->deploymentDestinationRootPath.'/.env'),
            ],
            // No "sync_ssh" step in production: the image contains all modules, no git access needed.
            [
                'name'  => 'start_containers',
                'label' => 'Build containers ...',
                'task'  => fn(): bool => $deployment->runDockerBuild($deployment->deploymentDestinationRootPath),
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
                'name'  => 'sync_npm',
                'label' => 'Sync Nginx Proxy Manager ...',
                'task'  => fn(): bool => $deployment->runMercySyncNpm($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'start_migration',
                'label' => 'Migration / Seeding ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'migrate'),
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
