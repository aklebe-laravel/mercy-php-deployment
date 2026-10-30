<?php

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\DockerProcessDeployment;
use AklebeLaravel\MercyPhpDeployment\LibraryBranch;
use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update', description: 'Update the dev env.', help: 'Shorter install for an existing dev env: refresh ssh/env, composer install, rebuild (cached), require, update, migrate.')]
class UpdateDevEnvCommand extends BaseCommand
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
        // A shorter "install" for an existing dev env (after "git pull" of the scaffold or changes in setup.yaml).
        // Not repeated on purpose: generate_key (would invalidate sessions/encrypted data), link_storage.
        // sync_npm is repeated: ports, ip or npm.forward may have changed, the proxy has to follow.
        return array_filter([
            [
                'name'   => 'check_dev_stage',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label'  => 'Checking the dev stage ...',
                'task'   => fn(): bool => $deployment->checkDevStage(),
            ],
            [
                // git url rewrites, known_hosts, deploy key and access of this machine (see HostSetup)
                'name'   => 'setup_host',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label'  => 'Preparing this machine (git, ssh) ...',
                'task'   => fn(): bool => $deployment->setupHost(),
            ],
            [
                'name'  => 'copy_root_files',
                'label' => 'Copy root files ...',
                'task'  => fn(): bool => $deployment->copyDirectory($this->app->deploymentDestinationDir.'/install/project-root', $deployment->deploymentDestinationRootPath),
            ],
            [
                'name'   => 'copy_ssh',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label'  => 'Copy ssh stuff ...',
                'task'   => fn(): bool => $deployment->syncDockerSsh(),
            ],
            [
                'name'  => 'clone_dependencies',
                'label' => 'Cloning missing core modules & theme ...',
                'task'  => fn(): bool => $deployment->deployBaseModulesAndTheme(),
            ],
            [
                // git.branch(es) in project/config/local.yaml: libraries (git repositories of composer.json) from test branches, see LibraryBranch
                'name'  => 'library_branch',
                'label' => 'Test branches of the libraries ...',
                'task'  => fn(): bool => new LibraryBranch($deployment)->sync($deployment->deploymentDestinationRootPath),
            ],
            [
                // install, not update: the composer.lock of the scaffold decides the versions
                'name'  => 'composer_install',
                'label' => 'Running Composer Install ...',
                'task'  => fn(): bool => $deployment->runComposerInstall($deployment->deploymentDestinationRootPath, false),
            ],
            [
                // project name, instance key and ports stay as they are (sticky env values)
                'name'   => 'setup_env',
                // also when resuming at a later step (quick, repeatable): prepares what the later steps use, e.g. a fixed secret
                'always' => true,
                'label'  => 'Setup Environment Configs ...',
                'task'   => fn(): bool => $deployment->prepareEnvFile('setup-local.env', $deployment->deploymentDestinationRootPath.'/.env'),
            ],
            [
                // with docker cache: fast if the Dockerfile did not change
                'name'  => 'build_containers',
                'label' => 'Build containers ...',
                'task'  => fn(): bool => $deployment->runSailBuild($deployment->deploymentDestinationRootPath, false),
            ],
            [
                'name'  => 'sail_up',
                'label' => 'Sailing ... (starting docker)',
                'task'  => fn(): bool => $deployment->runSailUpDaemon($deployment->deploymentDestinationRootPath),
            ],
            [
                'name'  => 'get_requires',
                'label' => 'Clone/update requirements ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'require'),
            ],
            [
                'name'  => 'update',
                'label' => 'Updating System, Clearing Caches, Build Frontend ...',
                'task'  => fn(): bool => $deployment->runMercyFlow($deployment->deploymentDestinationRootPath, 'update'),
            ],
            [
                'name'  => 'sync_npm',
                'label' => 'Sync Nginx Proxy Manager ...',
                'task'  => fn(): bool => $deployment->runMercySyncNpm($deployment->deploymentDestinationRootPath),
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
