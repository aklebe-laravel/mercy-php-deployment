<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs a list of deployment steps and shows the progress.
 *
 * Live mode (normal mode in a terminal):
 *   one line per step; the running step shows a spinner and the elapsed time
 *   (refreshed while external commands run, see tick()), finished steps stay as "✔"/"✖" lines.
 * Plain mode (debug mode, or no terminal like CI/logs):
 *   a heading per step, the output of the commands flows below it, then the duration.
 *
 * A step is ['name' => string, 'label' => string, 'task' => callable(): bool, 'always' => bool].
 * "always": the step also runs when a later start step is given, e.g. steps that only load the
 * config/state the following steps need (without them a resumed run would use other values).
 */
final class StepRunner
{
    private const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private readonly OutputInterface $output;
    private readonly bool $debug;
    private readonly bool $live;
    private ?ConsoleSectionOutput $log = null;
    private ?ConsoleSectionOutput $status = null;

    private string $current = '';
    private float $stepStartedAt = 0.0;
    private float $lastTick = 0.0;
    private int $frame = 0;

    public function __construct(bool $debug, ?OutputInterface $output = null)
    {
        $this->output = $output ?? new ConsoleOutput();
        $this->debug = $debug;
        $this->live = !$debug
                      && $this->output instanceof ConsoleOutput
                      && $this->output->isDecorated()
                      && stream_isatty(STDOUT);

        if ($this->live) {
            // finished steps and messages above, the running step below
            $this->log = $this->output->section();
            $this->status = $this->output->section();
        }
    }

    /**
     * @param  list<array{name?: string, label: string, task: callable, always?: bool}>  $steps
     * @param  int|string                                                                $start  step number (1 based) or step name
     *
     * @return bool false if a step failed or the start step is unknown
     */
    public function run(array $steps, int|string $start = 1): bool
    {
        $steps = array_values($steps);
        $total = count($steps);
        $first = $this->resolveStart($steps, $start);
        if ($first === null) {
            $this->message('error', "Unknown step '$start'. Use --info to list the steps.");

            return false;
        }
        if ($first > 0) {
            $always = count(array_filter(array_slice($steps, 0, $first), fn(array $s) => !empty($s['always'])));
            $this->line(sprintf('<comment>Starting at step %d (%s), skipping %d step(s)%s.</comment>',
                $first + 1,
                $steps[$first]['name'] ?? $steps[$first]['label'],
                $first - $always,
                $always ? sprintf(', %d setup step(s) run anyway', $always) : ''));
        }

        $width = strlen((string) $total);
        $runStartedAt = microtime(true);
        $ran = 0;
        for ($i = 0; $i < $total; $i++) {
            $step = $steps[$i];
            if ($i < $first && empty($step['always'])) {
                continue;
            }
            $this->current = sprintf('[%s/%d] %s', str_pad((string) ($i + 1), $width, '0', STR_PAD_LEFT), $total, rtrim($step['label'], ' .'));
            $this->stepStartedAt = microtime(true);

            if ($this->live) {
                $this->drawStatus();
            } else {
                $this->output->writeln('');
                $this->output->writeln('<info>==> '.OutputFormatter::escape($this->current).'</info>');
            }

            try {
                $ok = (bool) ($step['task'])();
            } catch (Throwable $e) {
                $this->message('error', $e::class.': '.$e->getMessage().($this->debug ? "\n".$e->getTraceAsString() : ''));
                $ok = false;
            }

            $this->finishStep($ok);
            $ran++;
            if (!$ok) {
                $this->line(sprintf('<error> Deployment failed at step %d: %s </error>', $i + 1, $step['name'] ?? $step['label']));
                $this->line(sprintf('Continue after fixing it with the step name: <comment>%s</comment>', $step['name'] ?? ($i + 1)));

                return false;
            }
        }

        $this->line(sprintf('<info>Done: %d step(s) in %s.</info>', $ran, $this->formatDuration(microtime(true) - $runStartedAt)));

        return true;
    }

    /**
     * Refreshes spinner and elapsed time. Called often while external commands run (cheap, rate limited).
     *
     * @return void
     */
    public function tick(): void
    {
        if (!$this->live || $this->current === '') {
            return;
        }
        $now = microtime(true);
        if ($now - $this->lastTick < 0.1) {
            return;
        }
        $this->lastTick = $now;
        $this->frame = ($this->frame + 1) % count(self::FRAMES);
        $this->drawStatus();
    }

    /**
     * Call before output is written directly to STDOUT (e.g. a command whose output the user should see).
     * Live mode hides the status line, so the raw output does not mix with it.
     *
     * @return void
     */
    public function pauseLive(): void
    {
        if (!$this->live || $this->current === '') {
            return;
        }
        $this->status->clear();
        $this->log->writeln('<fg=cyan>▸</> '.OutputFormatter::escape($this->current));
    }

    /**
     * Call after the raw output. The old sections cannot be redrawn below foreign output,
     * so new sections start under it.
     *
     * @return void
     */
    public function resumeLive(): void
    {
        if (!$this->live || $this->current === '') {
            return;
        }
        $this->log = $this->output->section();
        $this->status = $this->output->section();
        $this->drawStatus();
    }

    /**
     * Shows an error, warning or info message at the right place (above the running step).
     *
     * @param  string  $level  error|warning|info
     * @param  string  $text
     *
     * @return void
     */
    public function message(string $level, string $text): void
    {
        $style = match ($level) {
            'error' => 'fg=red',
            'warning' => 'fg=yellow',
            default => 'fg=default',
        };
        $prefix = match ($level) {
            'error' => 'ERROR',
            'warning' => 'WARNING',
            default => 'INFO',
        };
        $lines = explode("\n", rtrim($text));
        $this->line(sprintf('<%s>    %s: %s</>', $style, $prefix, OutputFormatter::escape(array_shift($lines))));
        foreach ($lines as $line) {
            $this->line(sprintf('<%s>    %s</>', $style, OutputFormatter::escape($line)));
        }
    }

    /**
     * @return bool
     */
    public function isLive(): bool
    {
        return $this->live;
    }

    /**
     * @param  list<array{name?: string, label: string}>  $steps
     * @param  int|string                                 $start
     *
     * @return int|null 0 based index
     */
    private function resolveStart(array $steps, int|string $start): ?int
    {
        if (is_int($start) || ctype_digit((string) $start)) {
            $index = max(1, (int) $start) - 1;

            return $index < count($steps) ? $index : null;
        }
        foreach ($steps as $i => $step) {
            if (($step['name'] ?? null) === $start) {
                return $i;
            }
        }

        return null;
    }

    private function finishStep(bool $ok): void
    {
        $result = sprintf('%s %s <fg=gray>(%s)</>',
            $ok ? '<fg=green>✔</>' : '<fg=red>✖</>',
            OutputFormatter::escape($this->current),
            $this->formatDuration(microtime(true) - $this->stepStartedAt),);

        if ($this->live) {
            $this->status->clear();
            $this->log->writeln($result);
        } else {
            $this->output->writeln($result);
        }
        $this->current = '';
    }

    private function drawStatus(): void
    {
        $this->status->overwrite(sprintf('<fg=cyan>%s</> %s <fg=gray>… %s</>',
            self::FRAMES[$this->frame],
            OutputFormatter::escape($this->current),
            $this->formatDuration(microtime(true) - $this->stepStartedAt, false),));
    }

    private function line(string $formatted): void
    {
        if ($this->live) {
            $this->log->writeln($formatted);
        } else {
            $this->output->writeln($formatted);
        }
    }

    private function formatDuration(float $seconds, bool $precise = true): string
    {
        if ($seconds >= 60) {
            return sprintf('%dm %02ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
        }

        return $precise ? sprintf('%.1fs', $seconds) : sprintf('%ds', (int) $seconds);
    }
}
