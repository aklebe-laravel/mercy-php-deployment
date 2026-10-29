<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\MercyApp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'project:init', description: 'Create a new project folder from the scaffold template.')]
class ProjectInitCommand extends Command
{
    /**
     * @param  MercyApp  $app
     */
    public function __construct(public MercyApp $app)
    {
        parent::__construct();
    }

    /**
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (string) $this->app->projectDir;
        $template = $this->app->getProjectTemplateDir();

        if ($target === '') {
            $output->writeln('<error>No project folder configured (MercyApp::withProject()).</error>');

            return Command::FAILURE;
        }
        if (file_exists($target)) {
            $output->writeln("<error>'$target' already exists, nothing changed.</error>");

            return Command::FAILURE;
        }
        if (!is_dir($template)) {
            $output->writeln("<error>No project template found in '$template'.</error>");

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();
        // realpath: mirror() builds the target paths from the iterated paths, a symlinked origin would break them
        $filesystem->mirror((string) realpath($template), $target);
        // the secrets file of this machine starts as a copy of the example (it is ignored by git)
        $filesystem->copy("$target/config/secrets.example.yaml", "$target/config/secrets.yaml");

        $output->writeln("<info>Project created in '$target'.</info>");
        $output->writeln('Next steps:');
        $output->writeln('  1. edit config/setup.yaml (base) and config/dev.yaml, build.yaml, prod.yaml (differences)');
        $output->writeln('  2. fill in config/secrets.yaml (not versioned, keep a copy in your password manager)');
        $output->writeln('  3. "git init" in the project folder and push it to your own (private) repository');
        $output->writeln('  4. php mercy.php install');

        return Command::SUCCESS;
    }
}
