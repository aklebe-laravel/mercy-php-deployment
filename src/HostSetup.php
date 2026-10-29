<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

/**
 * Prepares this machine for git and ssh from the project config, so nobody has to remember the commands:
 *
 * 1. git url rewrites ("git.url_rewrites"): sets the "url.<base>.insteadOf" entries in the global git config
 *    and removes the same insteadOf of another base (e.g. after moving from one Gitea org to another).
 * 2. known_hosts ("ssh.known_hosts"): adds missing hosts to ~/.ssh/known_hosts, but only with a host key whose
 *    fingerprint is known: github.com is built in, other hosts come from "ssh.fingerprints". Without a
 *    fingerprint it asks in a terminal and fails otherwise (no blind trust).
 * 3. deploy key ("ssh.key"): creates it if missing and shows the public key to add at the git server.
 *    It is the only key needed: the containers use it, and every git/composer call of the deployment
 *    on this machine too (GIT_SSH_COMMAND, see Process::gitSshEnv()).
 * 4. access: tests the key against every git server (ssh -T) and says where the key is missing.
 * 5. docker registry ("git.registry_source"), separate step of build and production: checks the login and
 *    asks for user and token in a terminal (docker login), so a push does not fail after the whole build.
 * 6. tab completion of the commands and options (bash), see shellCompletion().
 *
 * Every step only changes what is missing, so it can run before every install/update.
 */
final class HostSetup
{
    /**
     * Published by GitHub ("GitHub's SSH key fingerprints" in the GitHub docs), so github.com needs no config.
     */
    public const KNOWN_FINGERPRINTS = [
        'github.com' => [
            'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU', // Ed25519
            'SHA256:p2QAMXNIC1TJYWeIOttrVc98/R1BUFWu3/LiyKgUfQM', // ECDSA
            'SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s', // RSA
        ],
    ];

    public function __construct(private readonly Process $process)
    {
    }

    public function run(): bool
    {
        return $this->gitUrlRewrites()
               && $this->knownHosts()
               && $this->deployKey()
               && $this->gitAccess()
               && $this->shellCompletion();
    }

    /**
     * Installs the bash completion of the running script ("mercy") for this user, so commands and
     * values like "--instance=" complete with <tab>: ./mercy install --instance=<tab>
     * Works with "./mercy" (shebang, executable), not with "php mercy" (bash completes "php" then), like artisan.
     * Loaded by the package bash-completion in every new terminal. Never fails the setup.
     *
     * @return bool
     */
    public function shellCompletion(): bool
    {
        $script = basename((string) ($_SERVER['argv'][0] ?? ''));
        $template = dirname((string) new \ReflectionClass(\Symfony\Component\Console\Application::class)->getFileName()).'/Resources/completion.bash';
        // only for the Mercy entry point (not e.g. for phpunit running the tests)
        if (!preg_match('/^mercy(\.php)?$/', $script) || !is_file($template)) {
            return true;
        }

        $dataHome = getenv('XDG_DATA_HOME') ?: Process::expandTilde('~/.local/share');
        $file = "$dataHome/bash-completion/completions/$script";
        $content = str_replace(['{{ COMMAND_NAME }}', '{{ VERSION }}'],
            [$script, \Symfony\Component\Console\Command\CompleteCommand::COMPLETION_API_VERSION], (string) file_get_contents($template));
        if (is_file($file) && file_get_contents($file) === $content) {
            return true;
        }

        if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) || @file_put_contents($file, $content) === false) {
            $this->process->warning("Tab completion not installed (cannot write $file).");

            return true;
        }
        $this->process->notice("Tab completion installed for ./$script (in a new terminal: ./$script install --instance=<tab>).");

        return true;
    }

    /**
     * @return bool
     */
    public function gitUrlRewrites(): bool
    {
        $expected = $this->process->expectedGitUrlRewrites();
        if (!$expected) {
            return true;
        }
        $present = Process::parseGitUrlRewrites((string) $this->process->capture(['git', 'config', '--global', '--get-regexp', '^url\\..*\\.insteadof$']));

        foreach ($expected as $base => $insteadOfList) {
            foreach ($insteadOfList as $insteadOf) {
                if (in_array($insteadOf, $present[$base] ?? [], true)) {
                    continue;
                }
                // the same url rewritten to another base would win or conflict: remove it
                foreach ($present as $otherBase => $otherList) {
                    if ($otherBase !== $base && in_array($insteadOf, $otherList, true)) {
                        if (!$this->process->run(['git', 'config', '--global', '--fixed-value', '--unset', "url.$otherBase.insteadOf", $insteadOf])) {
                            return false;
                        }
                        $this->process->notice("git: '$insteadOf' no longer goes to '$otherBase'.");
                    }
                }
                if (!$this->process->run(['git', 'config', '--global', '--add', "url.$base.insteadOf", $insteadOf])) {
                    return false;
                }
                $this->process->notice("git: '$insteadOf' now goes to '$base'.");
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    public function knownHosts(): bool
    {
        $hosts = array_values(array_filter((array) $this->process->getConfigValue('setup.ssh.known_hosts', [])));
        $file = Process::expandTilde('~/.ssh/known_hosts');
        $this->process->filesystem->mkdir(dirname($file), 0700);

        foreach ($hosts as $host) {
            $host = (string) $host;
            if (trim((string) $this->process->capture(['ssh-keygen', '-F', $host, '-f', $file])) !== '') {
                continue;
            }

            $scanned = $this->scanHostKeys($host);
            if (!$scanned) {
                $this->process->error("Cannot read the ssh host key of '$host' (ssh-keyscan). Is the host reachable on port 22?");

                return false;
            }

            $expected = $this->expectedFingerprints($host);
            if ($expected) {
                $lines = array_keys(array_intersect($scanned, $expected));
                if (!$lines) {
                    $this->process->error("The ssh host key of '$host' does not match 'ssh.fingerprints' (".implode(', ', $expected).'), got: '.implode(', ', array_unique($scanned))
                                          .". Either the server key changed or this is not the expected server.");

                    return false;
                }
            } else {
                $answer = $this->process->prompt(sprintf("Unknown ssh host '%s' with fingerprint(s): %s\nTrust it? [y/N]", $host, implode(', ', array_unique($scanned))));
                if (strtolower((string) $answer) !== 'y') {
                    $this->process->error(sprintf("No fingerprint for ssh host '%s'. Compare it with the server (there: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub) and add it to the network config:\n"."ssh:\n  fingerprints:\n    %s: '%s'",
                        $host,
                        $host,
                        array_values($scanned)[0]));

                    return false;
                }
                $lines = array_keys($scanned);
            }

            file_put_contents($file, implode("\n", $lines)."\n", FILE_APPEND);
            @chmod($file, 0600);
            $this->process->notice("ssh: added the host key of '$host' to $file.");
        }

        return true;
    }

    /**
     * @return bool
     */
    public function deployKey(): bool
    {
        $key = $this->keyFile();
        if (is_file($key)) {
            return true;
        }

        $this->process->filesystem->mkdir(dirname($key), 0700);
        $comment = 'mercy-deploy@'.gethostname();
        if (!$this->process->run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $key])) {
            return false;
        }
        $this->process->notice("ssh: created the deploy key $key.");

        return true;
    }

    /**
     * Tests the deploy key against every git server, asks to add it where it is missing.
     *
     * @return bool
     */
    public function gitAccess(): bool
    {
        $key = $this->keyFile();
        foreach ($this->gitServers() as $server) {
            while (!$this->canLogin($key, $server)) {
                $publicKey = trim((string) @file_get_contents($key.'.pub'));
                $message = sprintf("The deploy key is not accepted by '%s'. Add this public key there as ssh key (GitHub: Settings > SSH keys, Gitea: Settings > SSH / GPG Keys):\n%s",
                    $server,
                    $publicKey);
                $answer = $this->process->prompt($message."\nPress Enter after adding it, or type 'q' to stop:");
                if ($answer === null || strtolower($answer) === 'q') {
                    $this->process->error($message);

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Login at the docker registry of the image. A build needs it to push; production only for a private image,
     * so there a missing login is only a warning.
     *
     * @param  bool  $required  true: fail without login (build)
     *
     * @return bool
     */
    public function registryLogin(bool $required): bool
    {
        $registry = trim((string) $this->process->getConfigValue('setup.git.registry_source', ''));
        if ($registry === '' || self::isLoggedIn($registry)) {
            return true;
        }
        $how = "docker login $registry  (GitHub ghcr.io: classic token with write:packages, read:packages is enough for production; "."Gitea: Settings > Applications, token with package read/write)";

        $user = $this->process->prompt("Docker registry '$registry': not logged in. User name (empty = skip):");
        if ($user === null || $user === '') {
            if ($required) {
                $this->process->error("Not logged in at the docker registry '$registry', the image cannot be pushed. Run: $how");

                return false;
            }
            $this->process->warning("Not logged in at the docker registry '$registry'. A private image cannot be pulled, then run: $how");

            return true;
        }

        $token = (string) $this->process->prompt("Token (or password) of '$user' at '$registry' (not shown):", true);
        [$code, $output] = $this->process->captureAll(['docker', 'login', $registry, '-u', $user, '--password-stdin'], 30.0, $token);
        if ($code !== 0) {
            $this->process->error("docker login at '$registry' failed: $output");

            return false;
        }
        $this->process->notice("docker: logged in at '$registry' as '$user'.");

        return true;
    }

    /**
     * Looks into the docker client config (~/.docker/config.json or $DOCKER_CONFIG).
     *
     * @param  string  $registry
     *
     * @return bool
     */
    public static function isLoggedIn(string $registry): bool
    {
        $dir = getenv('DOCKER_CONFIG') ?: Process::expandTilde('~/.docker');
        $file = rtrim($dir, '/').'/config.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($config)) {
            return false;
        }
        // a credential store keeps the logins elsewhere, they cannot be checked here
        if (!empty($config['credsStore']) || isset($config['credHelpers'][$registry])) {
            return true;
        }
        foreach (array_keys($config['auths'] ?? []) as $key) {
            if (preg_replace('#^https?://#', '', rtrim((string) $key, '/')) === $registry) {
                return true;
            }
        }

        return false;
    }

    /**
     * user@host of the git servers in use: the bases of the url rewrites (they replace the urls in the
     * code), otherwise "git.source" (e.g. git@github.com).
     *
     * @return list<string>
     */
    public function gitServers(): array
    {
        $servers = [];
        foreach (array_keys($this->process->expectedGitUrlRewrites()) as $base) {
            if ($server = self::sshServer($base)) {
                $servers[] = $server;
            }
        }
        if (!$servers && ($server = self::sshServer((string) $this->process->getConfigValue('setup.git.source', '')))) {
            $servers[] = $server;
        }

        return array_values(array_unique($servers));
    }

    /**
     * "gitea@git.example.com:org/" or "ssh://git@host/x" -> "gitea@git.example.com"; https urls -> null.
     *
     * @param  string  $url
     *
     * @return string|null
     */
    public static function sshServer(string $url): ?string
    {
        if (preg_match('#^ssh://([^@/]+@[^/:]+)#', $url, $m) || preg_match('#^([^@/:]+@[^/:]+)(:|$)#', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Fingerprints from "ssh.fingerprints" (string or list per host), otherwise the built in ones.
     *
     * @param  string  $host
     *
     * @return list<string>
     */
    public function expectedFingerprints(string $host): array
    {
        $configured = $this->process->getConfigValue('setup.ssh.fingerprints', []) ?? [];
        $value = $configured[$host] ?? self::KNOWN_FINGERPRINTS[$host] ?? [];

        return array_values(array_filter((array) $value));
    }

    /**
     * @param  string  $host
     *
     * @return array<string, string> known_hosts line => its SHA256 fingerprint
     */
    private function scanHostKeys(string $host): array
    {
        // "[host]:2222" (known_hosts syntax for another port) -> ssh-keyscan -p 2222 host
        $port = [];
        if (preg_match('/^\[(.+)]:(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], ['-p', $m[2]]];
        }
        $output = (string) $this->process->capture(['ssh-keyscan', '-T', '10', ...$port, $host], null, 20.0);
        $result = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($fingerprint = $this->fingerprint($line)) {
                $result[$line] = $fingerprint;
            }
        }

        return $result;
    }

    /**
     * @param  string  $knownHostsLine  "host ssh-ed25519 AAAA..."
     *
     * @return string|null "SHA256:..."
     */
    private function fingerprint(string $knownHostsLine): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mercy-hostkey-');
        try {
            file_put_contents($tmp, $knownHostsLine."\n");
            $output = (string) $this->process->capture(['ssh-keygen', '-l', '-E', 'sha256', '-f', $tmp]);
        } finally {
            @unlink($tmp);
        }

        return preg_match('/\b(SHA256:\S+)/', $output, $m) ? $m[1] : null;
    }

    /**
     * GitHub and Gitea answer "ssh -T" with "... successfully authenticated ..." (exit code 1 at GitHub).
     *
     * @param  string  $key
     * @param  string  $server
     *
     * @return bool
     */
    private function canLogin(string $key, string $server): bool
    {
        [, $output] = $this->process->captureAll(['ssh', '-i', $key, '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-T', $server], 30.0);

        return stripos($output, 'successfully authenticated') !== false;
    }

    private function keyFile(): string
    {
        return Process::expandTilde((string) $this->process->getConfigValue('setup.ssh.key', '~/.ssh/id_ed25519'));
    }
}
