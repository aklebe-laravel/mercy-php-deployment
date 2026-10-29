<?php

namespace AklebeLaravel\MercyPhpDeployment\Tests\Unit;

use AklebeLaravel\MercyPhpDeployment\DockerProcess;
use AklebeLaravel\MercyPhpDeployment\Process;
use PHPUnit\Framework\TestCase;

class DeploymentServiceTest extends TestCase
{
    /**
     * @return void
     */
    public function test_parseGitUrlRewrites_multipleEntries_grouped(): void
    {
        $output = "url.gitea@git.example.com:old-org/.insteadof https://github.com/new-org/\n"."url.gitea@git.example.com:old-org/.insteadof git@github.com:new-org/\n"."url.https://mirror.example.com/.insteadof https://example.org/\n";

        $this->assertSame([
            'gitea@git.example.com:old-org/' => ['https://github.com/new-org/', 'git@github.com:new-org/'],
            'https://mirror.example.com/'    => ['https://example.org/'],
        ], Process::parseGitUrlRewrites($output));
    }

    /**
     * @return void
     */
    public function test_missingGitUrlRewrites_reportsOnlyMissing(): void
    {
        $expected = ['gitea@git.example.com:old-org/' => ['https://github.com/new-org/', 'git@github.com:new-org/']];
        $present = ['gitea@git.example.com:old-org/' => ['git@github.com:new-org/'], 'other/' => ['x/']];

        $this->assertSame(['gitea@git.example.com:old-org/' => ['https://github.com/new-org/']],
            Process::missingGitUrlRewrites($expected, $present));
        $this->assertSame([], Process::missingGitUrlRewrites($expected, $expected));
    }

    /**
     * @return void
     */
    public function test_parseGitUrlRewrites_empty_emptyArray(): void
    {
        $this->assertSame([], Process::parseGitUrlRewrites(''));
    }

    /**
     * @return void
     */
    public function test_renderGitConfig_validGitConfigSyntax(): void
    {
        $content = Process::renderGitConfig([
            'gitea@git.example.com:old-org/' => ['https://github.com/new-org/', 'git@github.com:new-org/'],
        ]);

        $this->assertStringContainsString("[url \"gitea@git.example.com:old-org/\"]\n", $content);
        $this->assertStringContainsString("\tinsteadOf = https://github.com/new-org/\n", $content);
        $this->assertStringContainsString("\tinsteadOf = git@github.com:new-org/\n", $content);
    }

    /**
     * @return void
     */
    public function test_releaseFileMap_targets(): void
    {
        $this->assertSame([
            'resources/production/compose.yaml' => '/srv/app/compose.yaml',
            'resources/setup/.'                 => '/srv/app/resources/setup',
        ], DockerProcess::releaseFileMap('/srv/app', '/srv/app/resources/setup'));
    }

    /**
     * @return void
     */
    public function test_isValidImageTag(): void
    {
        $this->assertTrue(Process::isValidImageTag('2.0.1-rc1'));
        $this->assertTrue(Process::isValidImageTag('v2.0.1-rc1-3-g1a2b3c4-dirty'));
        $this->assertFalse(Process::isValidImageTag(''));
        $this->assertFalse(Process::isValidImageTag('-rc1'));
        $this->assertFalse(Process::isValidImageTag('2.0 rc1'));
        $this->assertFalse(Process::isValidImageTag('2.0.1+build'));
    }

    /**
     * @return void
     */
    public function test_getImageNameVersion_imageOverridesName(): void
    {
        $deployment = new DockerProcess();
        $deployment->yamlConfigs = ['setup' => ['kernel' => ['name' => 'proxtop', 'version' => '2.0.1']]];
        $this->assertSame('proxtop:2.0.1', $deployment->getImageNameVersion());

        $deployment->yamlConfigs['setup']['kernel']['image'] = 'proxtop-web';
        $this->assertSame('proxtop-web:2.0.1', $deployment->getImageNameVersion());
        $this->assertSame('proxtop-web-2.0.1', $deployment->getImageSaveBaseFile());
    }
}
