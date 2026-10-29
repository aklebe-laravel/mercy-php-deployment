<?php

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\DockerProcessDeployment;
use AklebeLaravel\MercyPhpDeployment\MercyApp;
use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class BaseCommand extends Command
{
    /**
     * @var array
     */
    protected array $steps = [];

    /**
     * @var string
     */
    protected string $phpBinary = PHP_BINARY;

    /**
     * @var string
     */
    protected string $requiredPhp = '8.5';

    /**
     * @param  MercyApp  $app
     */
    public function __construct(public MercyApp $app)
    {
        parent::__construct();
    }

    /**
     * Every deployment command starts with the configs of the project repository.
     *
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     *
     * @return void
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('instance') && ($instance = $input->getOption('instance'))) {
            $this->app->selectInstance((string) $instance);
        }
        $this->app->applyProject();
    }

    /**
     * Overwrite this
     *
     * @param  DockerProcessDeployment|SailProcessDeployment  $deployment
     *
     * @return array
     */
    protected function getDeploymentSteps(DockerProcessDeployment|SailProcessDeployment $deployment): array
    {
        return [];
    }

    /**
     * Lists the steps for --info. Always shown, also outside debug mode.
     * The numbers are the ones the "step" argument expects.
     *
     * @param  InputInterface                                 $input
     * @param  DockerProcessDeployment|SailProcessDeployment  $deployment
     *
     * @return bool true if --info was given
     */
    protected function checkInfoOption(InputInterface $input, DockerProcessDeployment|SailProcessDeployment $deployment): bool
    {
        if (!$input->getOption('info')) {
            return false;
        }

        $output = new ConsoleOutput();
        $output->writeln(sprintf('<info>%s [step]</info> - start with the step number or name, all following steps run as well.', $this->getName()));
        $output->writeln('');
        $output->writeln(sprintf('<comment>%3s  %-30s %s</comment>', 'Nr', 'Name', 'Description'));
        foreach (array_values($this->getDeploymentSteps($deployment)) as $i => $step) {
            $output->writeln(sprintf('%3d  %-30s %s', $i + 1, $step['name'] ?? '?', rtrim($step['label'] ?? '?', ' .')));
        }

        return true;
    }
}
