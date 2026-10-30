<?php

namespace AklebeLaravel\MercyPhpDeployment\Tests\Unit;

use AklebeLaravel\MercyPhpDeployment\HostSetup;
use AklebeLaravel\MercyPhpDeployment\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs with a temporary HOME, so the global git config and ~/.ssh of the developer stay untouched.
 * Needs git and ssh-keygen; the network (ssh-keyscan, ssh -T) is replaced by {@see HostSetupTestProcess}.
 */
class HostSetupTest extends TestCase
{
    private string $home;
    private string|false $oldHome;
    private HostSetupTestProcess $process;

    protected function setUp(): void
    {
        $this->oldHome = getenv('HOME');
        $this->home = sys_get_temp_dir().'/host_setup_test_'.uniqid();
        mkdir($this->home, 0700, true);
        putenv('HOME='.$this->home);
        $this->process = new HostSetupTestProcess();
    }

    protected function tearDown(): void
    {
        putenv($this->oldHome === false ? 'HOME' : 'HOME='.$this->oldHome);
        new Filesystem()->remove($this->home);
    }

    public function test_sshServer_ofUrls(): void
    {
        $this->assertSame('gitea@git.example.com', HostSetup::sshServer('gitea@git.example.com:aklebe-laravel/'));
        $this->assertSame('git@github.com', HostSetup::sshServer('git@github.com'));
        $this->assertSame('git@host.example', HostSetup::sshServer('ssh://git@host.example/x.git'));
        $this->assertNull(HostSetup::sshServer('https://github.com/aklebe-laravel/'));
    }

    public function test_gitUrlRewrites_addsAndMovesFromOtherBase(): void
    {
        exec('git config --global --add url."gitea@old.example:old-org/".insteadOf "https://github.com/aklebe-laravel/"');
        $this->process->config = [
            'git' => [
                'url_rewrites' => [
                    [
                        'base'       => 'gitea@new.example:aklebe-laravel/',
                        'instead_of' => ['https://github.com/aklebe-laravel/', 'git@github.com:aklebe-laravel/'],
                    ],
                ],
            ],
        ];
        $setup = new HostSetup($this->process);

        $this->assertTrue($setup->gitUrlRewrites());
        $present = Process::parseGitUrlRewrites((string) shell_exec('git config --global --get-regexp "^url\..*\.insteadof$"'));
        $this->assertSame(['gitea@new.example:aklebe-laravel/' => ['https://github.com/aklebe-laravel/', 'git@github.com:aklebe-laravel/']], $present);

        $notices = count($this->process->notices);
        $this->assertTrue($setup->gitUrlRewrites());
        $this->assertCount($notices, $this->process->notices, 'a second run changes nothing');
        $this->assertSame(['gitea@new.example'], $setup->gitServers());
    }

    public function test_knownHosts_onlyWithMatchingFingerprint(): void
    {
        exec('ssh-keygen -q -t ed25519 -N "" -f '.escapeshellarg($this->home.'/hostkey'));
        [$type, $key] = explode(' ', trim(file_get_contents($this->home.'/hostkey.pub')));
        $fingerprint = explode(' ', trim((string) shell_exec('ssh-keygen -l -E sha256 -f '.escapeshellarg($this->home.'/hostkey.pub'))))[1];
        $this->process->scanOutput = "# comment\ngit.example $type $key";
        $this->process->config = ['ssh' => ['known_hosts' => ['git.example'], 'fingerprints' => ['git.example' => 'SHA256:wrong']]];
        $setup = new HostSetup($this->process);
        $knownHosts = $this->home.'/.ssh/known_hosts';

        $this->assertFalse($setup->knownHosts());
        $this->assertStringContainsString('does not match', end($this->process->errors));
        $this->assertFileDoesNotExist($knownHosts);

        $this->process->config['ssh']['fingerprints'] = [];
        $this->assertFalse($setup->knownHosts(), 'no fingerprint and no terminal');
        $this->assertStringContainsString("git.example: '$fingerprint'", end($this->process->errors));

        $this->process->config['ssh']['fingerprints'] = ['git.example' => $fingerprint];
        $this->assertTrue($setup->knownHosts());
        $this->assertStringContainsString($key, file_get_contents($knownHosts));

        $size = filesize($knownHosts);
        $this->assertTrue($setup->knownHosts());
        clearstatcache();
        $this->assertSame($size, filesize($knownHosts), 'a known host is not added twice');
    }

    public function test_gitSshEnv_usesDeployKeyIfPresent(): void
    {
        $this->process->config = ['ssh' => ['key' => '~/.ssh/id_ed25519_deploy']];
        $this->assertSame([], $this->process->gitSshEnv(), 'no key, no change');

        new HostSetup($this->process)->deployKey();
        $this->assertSame(['GIT_SSH_COMMAND' => "ssh -i '{$this->home}/.ssh/id_ed25519_deploy' -o IdentitiesOnly=yes"], $this->process->gitSshEnv());
    }

    public function test_registryLogin_checkedInDockerConfig(): void
    {
        putenv('DOCKER_CONFIG='.$this->home.'/docker');
        mkdir($this->home.'/docker');
        $this->process->config = ['git' => ['registry_source' => 'git.example.com']];
        $setup = new HostSetup($this->process);

        // no login, no terminal: the build stops, production only warns
        $this->assertFalse($setup->registryLogin(true));
        $this->assertStringContainsString('docker login git.example.com', end($this->process->errors));
        $this->assertTrue($setup->registryLogin(false));
        $this->assertStringContainsString('cannot be pulled', end($this->process->warnings));

        file_put_contents($this->home.'/docker/config.json', json_encode(['auths' => ['https://git.example.com' => ['auth' => 'x']]]));
        $this->assertTrue(HostSetup::isLoggedIn('git.example.com'));
        $this->assertFalse(HostSetup::isLoggedIn('ghcr.io'));
        $this->assertTrue($setup->registryLogin(true));
        putenv('DOCKER_CONFIG');
    }

    public function test_shellCompletion_installedAndLoadedInEveryTerminal(): void
    {
        $argv = $_SERVER['argv'];
        $xdg = getenv('XDG_DATA_HOME');
        putenv('XDG_DATA_HOME');
        $completions = $this->home.'/.local/share/bash-completion/completions';
        mkdir($completions, 0755, true);
        file_put_contents("$completions/mercy.php", 'old');
        $setup = new HostSetup($this->process);

        try {
            $_SERVER['argv'][0] = 'vendor/bin/phpunit';
            $setup->shellCompletion();
            $this->assertFileDoesNotExist("$completions/phpunit", 'only for the mercy script');

            $_SERVER['argv'][0] = './mercy';
            $setup->shellCompletion();
            $this->assertStringContainsString('complete -F _sf_mercy mercy', file_get_contents("$completions/mercy"));
            $this->assertFileDoesNotExist("$completions/mercy.php", 'the file of the old name is removed');
            $rc = file_get_contents($this->home.'/.bash_completion');
            $this->assertStringContainsString(". '$completions/mercy'", $rc, 'loaded in every terminal, also for ./mercy');

            $notices = count($this->process->notices);
            $setup->shellCompletion();
            $this->assertSame($rc, file_get_contents($this->home.'/.bash_completion'), 'the line is added once');
            $this->assertCount($notices, $this->process->notices, 'a second run changes nothing');
        } finally {
            $_SERVER['argv'] = $argv;
            putenv($xdg === false ? 'XDG_DATA_HOME' : "XDG_DATA_HOME=$xdg");
        }
    }

    public function test_expectedFingerprints_githubBuiltIn(): void
    {
        $this->assertContains('SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU', new HostSetup($this->process)->expectedFingerprints('github.com'));
    }

    public function test_deployKey_createdAndAccessTested(): void
    {
        $this->process->config = ['ssh' => ['key' => '~/.ssh/id_ed25519_deploy'], 'git' => ['source' => 'git@github.com']];
        $setup = new HostSetup($this->process);

        $this->assertTrue($setup->deployKey());
        $this->assertFileExists($this->home.'/.ssh/id_ed25519_deploy.pub');

        $this->assertFalse($setup->gitAccess());
        $this->assertStringContainsString('ssh-ed25519 ', end($this->process->errors), 'the error shows the public key');

        $this->process->acceptedServers = ['git@github.com'];
        $this->assertTrue($setup->gitAccess());
    }
}

/**
 * Process without network and output: config from an array, ssh-keyscan and ssh -T simulated.
 */
class HostSetupTestProcess extends Process
{
    public array $config = [];
    public array $errors = [];
    public array $notices = [];
    public array $warnings = [];
    public string $scanOutput = '';
    public array $acceptedServers = [];

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get(['setup' => $this->config], $key, $default);
    }

    public function error(string $content): void
    {
        $this->errors[] = $content;
    }

    public function notice(string $content): void
    {
        $this->notices[] = $content;
    }

    public function warning(string $content): void
    {
        $this->warnings[] = $content;
    }

    public function prompt(string $question, bool $hidden = false): ?string
    {
        // like CI: no terminal
        return null;
    }

    public function capture(array $command, ?string $rootDir = null, ?float $timeout = 10.0): ?string
    {
        if ($command[0] === 'ssh-keyscan') {
            return $this->scanOutput;
        }

        return parent::capture($command, sys_get_temp_dir(), $timeout);
    }

    public function captureAll(array $command, ?float $timeout = 10.0, ?string $input = null): array
    {
        return in_array(end($command), $this->acceptedServers, true) ? [1, "Hi! You've successfully authenticated, but GitHub does not provide shell access."] : [255, 'Permission denied (publickey).'];
    }
}
