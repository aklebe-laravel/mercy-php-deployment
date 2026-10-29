<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'host:setup',
    description: 'Prepare git and ssh of this machine from the project config (url rewrites, known_hosts, deploy key, access).',
    help: 'Runs also as the first steps of install, update and build. Only changes what is missing.',
)]
class HostSetupCommand extends BaseCommand
{
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

        $deployment->deploySteps([
            ['name' => 'git_url_rewrites', 'label' => 'git url rewrites', 'task' => fn(): bool => $deployment->hostSetup()->gitUrlRewrites()],
            ['name' => 'known_hosts', 'label' => 'ssh known_hosts', 'task' => fn(): bool => $deployment->hostSetup()->knownHosts()],
            ['name' => 'deploy_key', 'label' => 'Deploy key', 'task' => fn(): bool => $deployment->hostSetup()->deployKey()],
            ['name' => 'git_access', 'label' => 'Access to the git servers', 'task' => fn(): bool => $deployment->hostSetup()->gitAccess()],
        ], 1);

        return Command::SUCCESS;
    }
}
