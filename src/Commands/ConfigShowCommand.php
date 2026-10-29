<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\MercyApp;
use AklebeLaravel\MercyPhpDeployment\ProjectConfig;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config', description: 'Show the effective project config of a stage and where each value comes from.', help: 'Without options only the values the project sets. --all also shows the Mercy defaults, --redundant the project values that change nothing if removed (in no stage of this tool).',)]
class ConfigShowCommand extends Command
{
    /**
     * @param  MercyApp  $app
     */
    public function __construct(public MercyApp $app)
    {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('filter', InputArgument::OPTIONAL, 'Only keys starting with this, e.g. "kernel" or "npm.hosts"')
            ->addOption('stage', 's', InputOption::VALUE_REQUIRED, 'Stage (default: the first stage of this tool)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Also list the Mercy defaults the project does not change')
            ->addOption('redundant', 'r', InputOption::VALUE_NONE, 'Only the project values that change nothing if removed');
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
        if ($this->app->projectDir === null || !is_dir($this->app->projectDir)) {
            $output->writeln('<error>No project folder found.</error>');

            return Command::FAILURE;
        }

        $stage = (string) ($input->getOption('stage') ?: array_key_first($this->app->projectStages));
        $filter = (string) $input->getArgument('filter');
        if ($input->hasOption('instance') && ($instance = $input->getOption('instance'))) {
            // only for this look, the remembered instance of the installation stays
            $this->app->selectInstance((string) $instance, false);
        }
        $project = $this->app->projectConfig();
        $all = (bool) $input->getOption('all');

        // origin of every key: the last layer that sets it
        $origins = [];
        foreach ($project->layerData($stage) as $label => $data) {
            foreach (array_keys(ProjectConfig::flatten($data)) as $key) {
                $origins[$key] = $label;
            }
        }
        $unknown = $project->unknownKeys($stage);
        // redundant only if removing it changes no stage of this tool (dev.yaml is part of the build stage, too)
        $redundant = $project->redundantKeys($stage);
        foreach (array_keys($this->app->projectStages) as $other) {
            $otherLayers = $project->layers($other);
            $otherRedundant = $project->redundantKeys($other);
            foreach ($redundant as $key => $label) {
                if (isset($otherLayers[$label]) && ($otherRedundant[$key] ?? null) !== $label) {
                    unset($redundant[$key]);
                }
            }
        }
        // a map in a later layer replaces a list (or the other way round): keep only the keys of the merged result
        $merged = ProjectConfig::flatten($project->merged($stage));

        $rows = [];
        foreach ($merged as $key => $value) {
            $origin = $origins[$key] ?? '?';
            if ($filter !== '' && !str_starts_with($key, $filter)) {
                continue;
            }
            if ($input->getOption('redundant') ? !isset($redundant[$key]) : (!$all && ProjectConfig::isDefaultsLayer($origin))) {
                continue;
            }
            $shown = ProjectConfig::isSecretLayer($origin) ? '********' : $this->format($value);
            $from = $origin;
            if (ProjectConfig::isDefaultsLayer($origin)) {
                [$shown, $from] = ["<fg=gray>$shown</>", "<fg=gray>$origin</>"];
            } elseif (isset($unknown[$key])) {
                $from .= ' <fg=yellow>(unknown key)</>';
            } elseif (isset($redundant[$key])) {
                $from .= ' <fg=gray>(same as default)</>';
            }
            $rows[$key] = [$key, $shown, $from];
        }
        ksort($rows);

        $output->writeln("<info>Stage '$stage'</info>".($project->instance !== null ? ", <info>instance '{$project->instance}'</info>" : '')
                         .' ('.implode(' + ', array_keys($project->layers($stage))).')');
        if ($project->instance === null && $project->instances()) {
            $output->writeln('<comment>No instance chosen yet: --instance='.implode('|', $project->instances()).'</comment>');
        }
        $table = new Table($output);
        $table->setHeaders(['Key', 'Value', 'From'])
            ->setRows(array_values($rows))
            ->render();
        foreach ($project->warnings() as $warning) {
            $output->writeln("<comment>$warning</comment>");
        }
        if (!$all) {
            $output->writeln('<fg=gray>Only the values of the project; --all also shows the Mercy defaults.</>');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  mixed  $value
     *
     * @return string
     */
    private function format(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES);
        $text = trim((string) $text, "'");

        return OutputFormatter::escape(mb_strlen($text) > 80 ? mb_substr($text, 0, 77).'...' : $text);
    }
}
