<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\LibraryBranch;
use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'library:sync', description: 'Libraries from git.branches / git.library_paths (local.yaml) again, e.g. after a change in your library folder.', help: 'Also a step of install and update. Copies the library folders into the scaffold and runs composer update for them.',)]
class LibrarySyncCommand extends BaseCommand
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
            [
                'name'  => 'library_branch',
                'label' => 'Libraries from branches or folders',
                'task'  => fn(): bool => new LibraryBranch($deployment)->sync($deployment->deploymentDestinationRootPath),
            ],
        ], 1);

        return Command::SUCCESS;
    }
}
