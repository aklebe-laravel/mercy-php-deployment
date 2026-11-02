<?php

namespace AklebeLaravel\MercyPhpDeployment\Tests\Unit;

use AklebeLaravel\MercyPhpDeployment\ImageVersions;
use AklebeLaravel\MercyPhpDeployment\Process;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * "kernel.version: auto": version selection, the registry API (faked HTTP) and the use in build and production.
 */
class ImageVersionsTest extends TestCase
{
    public function test_isAuto(): void
    {
        $this->assertTrue(ImageVersions::isAuto('auto'));
        $this->assertTrue(ImageVersions::isAuto(' Auto '));
        $this->assertFalse(ImageVersions::isAuto('2.0.5'));
        $this->assertFalse(ImageVersions::isAuto(''));
    }

    public function test_newest_skipsPreReleasesAndOtherTags(): void
    {
        $tags = ['2.0.9', '2.0.10', '2.1.0-rc1', '2.0.11-feature-x', 'latest', 'sha256-abc.sig', '2.0'];
        $this->assertSame('2.0.10', ImageVersions::newest($tags));
        $this->assertSame('v1.2.0', ImageVersions::newest(['v1.1.9', 'v1.2.0']));
        $this->assertNull(ImageVersions::newest(['2.0.1-rc2', 'latest']));
        $this->assertNull(ImageVersions::newest([]));
    }

    public function test_next(): void
    {
        // numeric, not alphabetic order
        $this->assertSame('2.0.11', ImageVersions::next(['2.0.9', '2.0.10']));
        // a pre-release or test image of the next number is followed by its release
        $this->assertSame('2.0.6', ImageVersions::next(['2.0.5', '2.0.6-rc1']));
        $this->assertSame('2.0.6', ImageVersions::next(['2.0.5', '2.0.6-feature-x']));
        // the release of it exists already
        $this->assertSame('2.0.7', ImageVersions::next(['2.0.6-rc1', '2.0.6']));
        $this->assertSame('2.0.1', ImageVersions::next(['2.0.1-rc2']));
        // the prefix of the newest tag is kept
        $this->assertSame('v1.3.1', ImageVersions::next(['v1.3.0', 'latest']));
        $this->assertSame(ImageVersions::FIRST, ImageVersions::next([]));
        $this->assertSame(ImageVersions::FIRST, ImageVersions::next(['latest']));
    }

    public function test_forTag(): void
    {
        $tags = ['2.0.4', '2.0.5', '2.6.4', '2.7.0', '2.7.1', '2.8.0-rc1', 'latest'];
        // major: next free number of the series
        $this->assertSame('3.0.0', ImageVersions::forTag($tags, '3'));
        $this->assertSame('2.8.0', ImageVersions::forTag($tags, '2'));
        // minor: new series or next free number (also an older series: hotfix)
        $this->assertSame('2.9.0', ImageVersions::forTag($tags, '2.9'));
        $this->assertSame('2.7.2', ImageVersions::forTag($tags, '2.7'));
        $this->assertSame('2.6.5', ImageVersions::forTag($tags, '2.6'));
        $this->assertSame('2.8.0', ImageVersions::forTag($tags, '2.8'));
        // full: exactly this one (a pre-release of it does not block)
        $this->assertSame('2.7.5', ImageVersions::forTag($tags, '2.7.5'));
        $this->assertSame('2.8.0', ImageVersions::forTag($tags, '2.8.0'));
        $this->assertSame('v3.0.0', ImageVersions::forTag([], 'v3'));
        $this->assertSame('3.1.0', ImageVersions::forTag([], '3.1'));
    }

    public function test_forTag_existingRelease_neverBuiltAgain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exists already');
        ImageVersions::forTag(['2.7.0', '2.7.1'], '2.7.0');
    }

    public function test_isTagOption(): void
    {
        foreach (['3', '2.7', '2.7.0', 'v2.7'] as $ok) {
            $this->assertTrue(ImageVersions::isTagOption($ok), $ok);
        }
        foreach (['', 'auto', '2.7.0.1', '2.7.0-rc1', 'x'] as $bad) {
            $this->assertFalse(ImageVersions::isTagOption($bad), $bad);
        }
    }

    public function test_tags_bearerLoginAndPages(): void
    {
        $calls = [];
        $http = function (string $url, array $headers) use (&$calls): array {
            $calls[] = [$url, $headers];
            $auth = $headers[0] ?? '';
            if (str_starts_with($url, 'https://ghcr.io/token')) {
                $this->assertSame('Authorization: Basic '.base64_encode('me:secret'), $auth);
                $this->assertStringContainsString('scope=repository%3Amy-org%2Fmy-web%3Apull', $url);
                $this->assertStringContainsString('service=ghcr.io', $url);

                return [200, [], '{"token":"T1"}'];
            }
            if ($auth !== 'Authorization: Bearer T1') {
                return [401, ['www-authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:my-org/my-web:pull"'], ''];
            }
            if (str_contains($url, 'last=2.0.1')) {
                return [200, [], '{"tags":["2.0.2"]}'];
            }

            return [200, ['link' => '</v2/my-org/my-web/tags/list?last=2.0.1&n=1000>; rel="next"'], '{"name":"my-org/my-web","tags":["2.0.0","2.0.1"]}'];
        };
        $versions = new ImageVersions($http, fn(string $registry): ?array => $registry === 'ghcr.io' ? ['me', 'secret'] : null);

        $this->assertSame(['2.0.0', '2.0.1', '2.0.2'], $versions->tags('ghcr.io', 'my-org/my-web'));
        $this->assertSame('https://ghcr.io/v2/my-org/my-web/tags/list?n=1000', $calls[0][0]);
        $this->assertSame('https://ghcr.io/v2/my-org/my-web/tags/list?last=2.0.1&n=1000', end($calls)[0]);
    }

    public function test_tags_anonymousTokenForPublicImage(): void
    {
        $http = function (string $url, array $headers): array {
            if (str_starts_with($url, 'https://git.example.com/v2/token')) {
                $this->assertSame([], $headers);

                return [200, [], '{"access_token":"A"}'];
            }
            if (($headers[0] ?? '') === 'Authorization: Bearer A') {
                return [200, [], '{"tags":["1.0.0"]}'];
            }

            return [401, ['www-authenticate' => 'Bearer realm="https://git.example.com/v2/token",service="container_registry",scope="*"'], ''];
        };

        $this->assertSame(['1.0.0'], new ImageVersions($http, fn() => null)->tags('https://git.example.com/', 'org/web'));
    }

    public function test_tags_errors(): void
    {
        // image never pushed: no tags, not an error
        $versions = new ImageVersions(fn() => [404, [], '{"errors":[{"code":"NAME_UNKNOWN"}]}'], fn() => null);
        $this->assertSame([], $versions->tags('ghcr.io', 'org/new'));

        // private image without login
        $versions = new ImageVersions(function (string $url, array $headers): array {
            if (str_contains($url, '/token')) {
                return [200, [], '{"token":"anon"}'];
            }

            return [$headers === [] ? 401 : 403, ['www-authenticate' => 'Bearer realm="https://ghcr.io/token",service="ghcr.io"'], ''];
        }, fn() => null);
        $this->assertNull($versions->tags('ghcr.io', 'org/private'));
        $this->assertStringContainsString('403', $versions->lastError);
        $this->assertStringContainsString('docker login', $versions->lastError);

        // not reachable
        $versions = new ImageVersions(fn() => [0, [], ''], fn() => null);
        $this->assertNull($versions->tags('ghcr.io', 'org/web'));
        $this->assertStringContainsString('not reachable', $versions->lastError);
    }

    public function test_dockerCredentials_fromAuths(): void
    {
        $dir = sys_get_temp_dir().'/docker_config_'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/config.json', json_encode(['auths' => ['https://ghcr.io' => ['auth' => base64_encode('me:tok:en')]]]));
        $old = getenv('DOCKER_CONFIG');
        putenv('DOCKER_CONFIG='.$dir);
        try {
            $this->assertSame(['me', 'tok:en'], ImageVersions::dockerCredentials('ghcr.io'));
            $this->assertNull(ImageVersions::dockerCredentials('git.example.com'));
        } finally {
            putenv($old === false ? 'DOCKER_CONFIG' : 'DOCKER_CONFIG='.$old);
            unlink($dir.'/config.json');
            rmdir($dir);
        }
    }

    public function test_prepareBuildConfig_auto_nextVersionInEveryFile(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.4', '2.0.5', '2.0.6-rc1']);
        $files = [];
        foreach (['setup.yaml', 'setup-build.yaml'] as $name) {
            $files[] = $file = sys_get_temp_dir().'/'.uniqid().'-'.$name;
            file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']])));
        }
        try {
            $this->assertTrue($process->prepareBuildConfig($files));
            foreach ($files as $file) {
                $this->assertSame('2.0.6', Yaml::parseFile($file)['kernel']['version']);
            }
            // the registry is asked once
            $this->assertSame(1, $process->registryCalls);
            $this->assertSame([['ghcr.io', 'my-org/my-web']], $process->asked);
        } finally {
            array_map('unlink', $files);
        }
    }

    public function test_prepareBuildConfig_autoWithBranch_testImageOfTheNextVersion(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5']);
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'image' => 'other-web', 'version' => 'auto'], 'build_steps' => ['branch' => 'feature/x']])));
        try {
            $this->assertTrue($process->prepareBuildConfig([$file]));
            $this->assertSame('2.0.6-feature-x', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame([['ghcr.io', 'my-org/other-web']], $process->asked);
        } finally {
            unlink($file);
        }
    }

    public function test_prepareBuildConfig_autoWithoutRegistry_fails(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5']);
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto'], 'build_steps' => ['use_registry' => false]])));
        try {
            $this->assertFalse($process->prepareBuildConfig([$file]));
            $this->assertStringContainsString('use_registry', $process->errors[0]);
            $this->assertSame(0, $process->registryCalls);
        } finally {
            unlink($file);
        }
    }

    public function test_prepareBuildConfig_fixedVersion_registryNotAsked(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5']);
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => '3.0.0']])));
        try {
            $this->assertTrue($process->prepareBuildConfig([$file]));
            $this->assertSame('3.0.0', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame(0, $process->registryCalls);
        } finally {
            unlink($file);
        }
    }

    public function test_prepareBuildConfig_tagOption_overridesConfigAndIsRemembered(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5', '2.7.1']);
        $process->buildTag = '2.7';
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        $versionFile = sys_get_temp_dir().'/'.uniqid('build_').'/storage/app/build-version';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => '1.0.0']])));
        try {
            $this->assertTrue($process->prepareBuildConfig([$file], $versionFile));
            $this->assertSame('2.7.2', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame("2.7.2\n", file_get_contents($versionFile));

            // resuming without --tag: the remembered number, the registry is not asked again
            $resume = new ImageVersionsTestProcess(['2.0.5', '2.7.1', '2.0.6']);
            file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']])));
            $this->assertTrue($resume->prepareBuildConfig([$file], $versionFile));
            $this->assertSame('2.7.2', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame(0, $resume->registryCalls);

            // a new --tag decides anew
            $resume->buildTag = '3';
            $this->assertTrue($resume->prepareBuildConfig([$file], $versionFile));
            $this->assertSame('3.0.0', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame("3.0.0\n", file_get_contents($versionFile));
        } finally {
            unlink($file);
            new \Symfony\Component\Filesystem\Filesystem()->remove(dirname($versionFile, 3));
        }
    }

    public function test_prepareBuildConfig_fixedVersion_rememberedFileIgnored(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5']);
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        $versionFile = sys_get_temp_dir().'/'.uniqid('build-version-');
        file_put_contents($versionFile, "2.0.6\n");
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => '4.0.0']])));
        try {
            $this->assertTrue($process->prepareBuildConfig([$file], $versionFile));
            $this->assertSame('4.0.0', Yaml::parseFile($file)['kernel']['version']);
        } finally {
            unlink($file);
            unlink($versionFile);
        }
    }

    public function test_prepareBuildConfig_tagOfExistingRelease_fails(): void
    {
        $process = new ImageVersionsTestProcess(['2.7.0']);
        $process->buildTag = '2.7.0';
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']])));
        try {
            $this->assertFalse($process->prepareBuildConfig([$file]));
            $this->assertStringContainsString('exists already', $process->errors[0]);
        } finally {
            unlink($file);
        }
    }

    public function test_prepareBuildConfig_tagWithoutRegistry_onlyFullNumber(): void
    {
        $process = new ImageVersionsTestProcess(['2.7.0']);
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto'], 'build_steps' => ['use_registry' => false]])));
        try {
            $process->buildTag = '2.7';
            $this->assertFalse($process->prepareBuildConfig([$file]));
            $this->assertStringContainsString('--tag=2.7.0', $process->errors[0]);

            $process->buildTag = '2.7.3';
            $this->assertTrue($process->prepareBuildConfig([$file]));
            $this->assertSame('2.7.3', Yaml::parseFile($file)['kernel']['version']);
            $this->assertSame(0, $process->registryCalls);
        } finally {
            unlink($file);
        }
    }

    public function test_prepareBuildConfig_tagWithBranch_suffix(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.5']);
        $process->buildTag = '3';
        $file = sys_get_temp_dir().'/'.uniqid().'-setup.yaml';
        file_put_contents($file, Yaml::dump(self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto'], 'build_steps' => ['branch' => 'feature-x']])));
        try {
            $this->assertTrue($process->prepareBuildConfig([$file]));
            $this->assertSame('3.0.0-feature-x', Yaml::parseFile($file)['kernel']['version']);
        } finally {
            unlink($file);
        }
    }

    public function test_getAppVersion_productionAuto_newestOnce(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.4', '2.0.5', '2.0.6-feature-x']);
        $process->config = self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']]);

        $this->assertSame('2.0.5', $process->getAppVersion());
        $this->assertSame('my-web:2.0.5', $process->getImageName());
        $this->assertSame(1, $process->registryCalls);
    }

    public function test_getAppVersion_productionAutoWithoutRelease_throws(): void
    {
        $process = new ImageVersionsTestProcess(['2.0.1-rc2']);
        $process->config = self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no release');
        $process->getAppVersion();
    }

    public function test_getAppVersion_devAuto_fromGitNotRegistry(): void
    {
        $process = new ImageVersionsTestDevProcess(['2.0.5']);
        $process->config = self::config(['kernel' => ['name' => 'my-web', 'version' => 'auto']]);
        $dir = sys_get_temp_dir().'/'.uniqid('no_git_');
        mkdir($dir);
        $process->root($dir);
        try {
            // no git repository here: "dev"
            $this->assertSame('dev', $process->getAppVersion());
            $this->assertSame(0, $process->registryCalls);
        } finally {
            rmdir($dir);
        }
    }

    /**
     * @param  array  $overrides
     *
     * @return array
     */
    private static function config(array $overrides): array
    {
        return array_replace_recursive([
            'git'         => ['registry_source' => 'ghcr.io', 'registry_namespace' => 'my-org'],
            'build_steps' => ['use_registry' => true],
        ], $overrides);
    }
}

/**
 * Registry answers from a fixed tag list, config and messages in memory.
 */
class ImageVersionsTestProcess extends Process
{
    public array $config = [];
    public array $errors = [];
    public array $notices = [];
    public int $registryCalls = 0;
    /** @var list<array{0: string, 1: string}> registry and repository of every tag request */
    public array $asked = [];

    /**
     * @param  list<string>  $tags
     */
    public function __construct(private readonly array $tags)
    {
        parent::__construct();
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get(['setup' => $this->config], $key, $default);
    }

    public function imageVersions(): ImageVersions
    {
        return new ImageVersions(function (string $url): array {
            $this->registryCalls++;
            preg_match('#^https://([^/]+)/v2/(.+)/tags/list#', $url, $m);
            $this->asked[] = [$m[1], $m[2]];

            return [200, [], json_encode(['tags' => $this->tags])];
        }, fn() => null);
    }

    public function error(string $content): void
    {
        $this->errors[] = $content;
    }

    public function notice(string $content): void
    {
        $this->notices[] = $content;
    }
}

/**
 * Like a dev/build process: without a version the version comes from git.
 */
class ImageVersionsTestDevProcess extends ImageVersionsTestProcess
{
    protected bool $versionFromGit = true;

    public function root(string $dir): void
    {
        $this->deploymentDestinationRootPath = $dir;
    }
}
