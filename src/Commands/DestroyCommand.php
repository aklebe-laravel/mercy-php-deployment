<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment\Commands;

use AklebeLaravel\MercyPhpDeployment\MercyApp;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Removes the docker project (containers, networks, volumes = database!) of this folder
 * and, only with --files, the files of the folder.
 *
 * Safety:
 * - works on the folder of the mercy script, never on the current working dir
 * - the docker project name comes from "docker compose config" (the real COMPOSE_PROJECT_NAME of the .env)
 * - the user has to type that name to confirm (no confirmation in non-interactive mode without --force)
 * - only images built for this project are removed by default (--images also removes the pulled ones)
 * - --files refuses if a git repo in the folder has uncommitted or unpushed changes (unless --force)
 */
#[AsCommand(name: 'destroy', description: 'Remove the docker project of this folder (database included), optionally its files.')]
class DestroyCommand extends Command
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
        $this->addOption('images', null, InputOption::VALUE_NONE, 'Also remove the pulled images (app image, mariadb, redis), even if other projects use them')
            ->addOption('files', null, InputOption::VALUE_NONE, 'Also delete all files of this folder (project/, secrets, Modules, ...)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation and the git check');
    }

    /**
     * @param  InputInterface   $input
     * @param  OutputInterface  $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = (string) realpath($this->app->deploymentDestinationDir);
        $force = (bool) $input->getOption('force');
        $withFiles = (bool) $input->getOption('files');

        if (!$this->isMercyRoot($root)) {
            $io->error("'$root' does not look like a Mercy folder (mercy and compose.yaml expected), nothing changed.");

            return Command::FAILURE;
        }

        // the real docker project name (needs .env, without it no container was ever started from here)
        $project = is_file("$root/.env") ? $this->composeProjectName($root) : null;

        $io->title('Destroy');
        $io->listing(array_filter([
            "Folder: $root",
            $project !== null ? "Docker project '$project': containers, networks and volumes (the DATABASE) are removed, images: ".($input->getOption('images') ? 'all of this project' : 'only the ones built here')
                : 'Docker: no .env or no resolvable compose project, nothing to remove',
            $withFiles ? 'Files: EVERYTHING in the folder, including project/ with its secrets and the module repos' : 'Files: kept (use --files to delete them)',
        ]));

        if ($withFiles && !$force && ($dirty = $this->dirtyRepositories($root))) {
            $io->error("These git repos have uncommitted or unpushed changes, nothing changed:\n".implode("\n", $dirty)."\nCommit/push them or use --force.");

            return Command::FAILURE;
        }

        if (!$force) {
            if (!$input->isInteractive()) {
                $io->error('Confirmation needed: run it interactively or with --force.');

                return Command::FAILURE;
            }
            $expected = $project ?? basename($root);
            if ($io->ask("Type '$expected' to confirm") !== $expected) {
                $io->writeln('Not confirmed, nothing changed.');

                return Command::SUCCESS;
            }
        }

        if ($project !== null) {
            $command = ['docker', 'compose', 'down', '--volumes', '--remove-orphans', '--rmi', $input->getOption('images') ? 'all' : 'local'];
            if (!$this->runCommand($command, $root, $io)) {
                $io->warning("'docker compose down' failed, check 'docker ps -a' for leftovers of '$project'.");
            }
        }

        if ($withFiles) {
            // after this no class of the vendor folder can be loaded any more: only plain PHP from here
            $left = $this->deleteContent($root);
            fwrite(STDOUT,
                $left === []
                    ? "\nAll files of '$root' removed.\n"
                    : "\nNot removable (owned by another user, e.g. created by a container), remove them with sudo:\n  ".implode("\n  ", array_slice($left, 0, 20)).(count($left) > 20 ? "\n  ..." : '')."\n");

            return $left === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->success($project !== null ? "Docker project '$project' removed, files kept." : 'Nothing to remove in docker, files kept.');

        return Command::SUCCESS;
    }

    /**
     * @param  string  $root
     *
     * @return bool
     */
    private function isMercyRoot(string $root): bool
    {
        return $root !== '' && $root !== '/' && $root !== (string) realpath((string) getenv('HOME'))
               // "mercy.php": scaffolds and installers before 2.1
               && (is_file("$root/mercy") || is_file("$root/mercy.php"))
               && is_file("$root/compose.yaml");
    }

    /**
     * @param  string  $root
     *
     * @return string|null
     */
    private function composeProjectName(string $root): ?string
    {
        $process = new SymfonyProcess(['docker', 'compose', 'config', '--format', 'json'], $root, null, null, 60);
        $process->run();
        $config = json_decode($process->getOutput(), true);
        $name = is_array($config) ? (string) ($config['name'] ?? '') : '';

        return $process->isSuccessful() && $name !== '' ? $name : null;
    }

    /**
     * Git repos of the folder with uncommitted or unpushed changes.
     *
     * @param  string  $root
     *
     * @return list<string>
     */
    private function dirtyRepositories(string $root): array
    {
        $dirs = [$root, "$root/project", ...(glob("$root/Modules/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/themes/*", GLOB_ONLYDIR) ?: [])];
        $dirty = [];
        foreach ($dirs as $dir) {
            if (!file_exists("$dir/.git")) {
                continue;
            }
            $status = new SymfonyProcess(['git', 'status', '--porcelain'], $dir);
            $status->run();
            // commits that are not on the upstream branch (fails without upstream: then nothing to compare)
            $unpushed = new SymfonyProcess(['git', 'log', '--oneline', '@{upstream}..HEAD'], $dir);
            $unpushed->run();
            if (trim($status->getOutput()) !== '' || ($unpushed->isSuccessful() && trim($unpushed->getOutput()) !== '')) {
                $dirty[] = '  '.($dir === $root ? '.' : substr($dir, strlen($root) + 1));
            }
        }

        return $dirty;
    }

    /**
     * @param  array         $command
     * @param  string        $cwd
     * @param  SymfonyStyle  $io
     *
     * @return bool
     */
    private function runCommand(array $command, string $cwd, SymfonyStyle $io): bool
    {
        $process = new SymfonyProcess($command, $cwd, null, null, null);
        $process->run(fn(string $type, string $buffer) => $io->write($buffer));

        return $process->isSuccessful();
    }

    /**
     * Deletes everything inside $root (not $root itself). Symlinks are removed, never followed.
     *
     * @param  string  $root
     *
     * @return list<string> paths that could not be removed
     */
    private function deleteContent(string $root): array
    {
        $left = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST,);
        foreach ($items as $item) {
            $path = $item->getPathname();
            $removed = $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);
            if (!$removed) {
                $left[] = $path;
            }
        }

        return $left;
    }
}
