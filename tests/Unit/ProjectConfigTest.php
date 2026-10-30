<?php

namespace AklebeLaravel\MercyPhpDeployment\Tests\Unit;

use AklebeLaravel\MercyPhpDeployment\ProjectConfig;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class ProjectConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/project_config_test_'.uniqid();
        mkdir($this->dir.'/project/config', 0755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    private function write(string $name, array $data): void
    {
        file_put_contents($this->dir.'/project/config/'.$name, Yaml::dump($data, 10));
    }

    private function writeDefaults(string $name, array $data): void
    {
        @mkdir($this->dir.'/defaults');
        file_put_contents($this->dir.'/defaults/'.$name, Yaml::dump($data, 10));
    }

    /**
     * Project with test defaults (empty unless a test writes them), independent of the real Mercy defaults.
     */
    private function project(): ProjectConfig
    {
        return new ProjectConfig($this->dir.'/project', $this->dir.'/defaults');
    }

    /**
     * @return void
     */
    public function test_merge_mapsMergedListsReplaced(): void
    {
        $base = ['npm' => ['user' => 'a', 'password' => ''], 'requires' => [['names' => ['x']], ['names' => ['y']]]];
        $override = ['npm' => ['password' => 'secret'], 'requires' => [['names' => ['z']]]];

        $this->assertSame([
            'npm'      => ['user' => 'a', 'password' => 'secret'],
            'requires' => [['names' => ['z']]],
        ], ProjectConfig::merge($base, $override));
    }

    /**
     * @return void
     */
    public function test_merged_layersInOrder(): void
    {
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo', 'env' => 'production'], 'ldap' => ['password' => '']]);
        $this->write('dev.yaml', ['kernel' => ['env' => 'local']]);
        $this->write('secrets.yaml', ['ldap' => ['password' => 'all']]);
        $this->write('dev.secrets.yaml', ['ldap' => ['password' => 'dev-only']]);
        $project = $this->project();

        $this->assertSame(['setup.yaml', 'dev.yaml', 'secrets.yaml', 'dev.secrets.yaml'], array_keys($project->layers('dev')));
        $this->assertSame(['kernel' => ['name' => 'demo', 'env' => 'local'], 'ldap' => ['password' => 'dev-only']], $project->merged('dev'));
        // a stage without own files gets base + secrets
        $this->assertSame(['kernel' => ['name' => 'demo', 'env' => 'production'], 'ldap' => ['password' => 'all']], $project->merged('prod'));
    }

    /**
     * @return void
     */
    public function test_apply_writesEveryStage(): void
    {
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo']]);
        $this->write('build.yaml', ['kernel' => ['version' => '1.2.3']]);

        $written = $this->project()
            ->apply(['dev' => 'setup.yaml', 'build' => 'setup-build.yaml'], $this->dir.'/last-setup');

        $this->assertSame([$this->dir.'/last-setup/setup.yaml', $this->dir.'/last-setup/setup-build.yaml'], $written);
        $this->assertSame(['kernel' => ['name' => 'demo']], Yaml::parseFile($written[0]));
        $this->assertSame(['kernel' => ['name' => 'demo', 'version' => '1.2.3']], Yaml::parseFile($written[1]));
        $this->assertStringStartsWith('# Generated', (string) file_get_contents($written[1]));
    }

    /**
     * @return void
     */
    public function test_layers_noConfig_throwsException(): void
    {
        $this->expectException(Exception::class);
        $this->project()
            ->layers('dev');
    }

    /**
     * @return void
     */
    public function test_flatten_keepsLists(): void
    {
        $this->assertSame(['a.b' => 1, 'a.c' => [1, 2], 'd' => []], ProjectConfig::flatten(['a' => ['b' => 1, 'c' => [1, 2]], 'd' => []]));
        $this->assertTrue(ProjectConfig::isSecretLayer('dev.secrets.yaml'));
        $this->assertFalse(ProjectConfig::isSecretLayer('dev.yaml'));
    }

    /**
     * Product -> network -> instance: the inherited files first, a file inherited twice only once.
     *
     * @return void
     */
    public function test_extends_chainInOrder(): void
    {
        mkdir($this->dir.'/project/config/networks');
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo'], 'npm' => ['url' => 'base']]);
        $this->write('networks/home.yaml', ['extends' => ['../setup.yaml'], 'npm' => ['url' => 'home']]);
        $this->write('prod.yaml', ['extends' => ['setup.yaml', 'networks/home.yaml'], 'kernel' => ['domain' => 'x.example']]);
        $this->write('secrets.yaml', ['npm' => ['password' => 'secret']]);
        $project = $this->project();

        $this->assertSame(['setup.yaml', 'networks/home.yaml', 'prod.yaml', 'secrets.yaml'], array_keys($project->layers('prod')));
        $this->assertSame([
            'kernel' => ['name' => 'demo', 'domain' => 'x.example'],
            'npm'    => ['url' => 'home', 'password' => 'secret'],
        ], $project->merged('prod'));
    }

    /**
     * @return void
     */
    public function test_extends_cycle_throwsException(): void
    {
        $this->write('setup.yaml', []);
        $this->write('a.yaml', ['extends' => ['b.yaml']]);
        $this->write('b.yaml', ['extends' => ['a.yaml']]);
        $this->write('dev.yaml', ['extends' => ['a.yaml']]);

        $this->expectExceptionMessage('Cycle');
        $this->project()
            ->layers('dev');
    }

    /**
     * @return void
     */
    public function test_extends_missingFile_throwsException(): void
    {
        $this->write('dev.yaml', ['extends' => ['missing.yaml']]);

        $this->expectExceptionMessage('missing.yaml');
        $this->project()
            ->layers('dev');
    }

    /**
     * Mercy defaults are the lowest layers (setup.yaml, then the stage), "~" means not set.
     *
     * @return void
     */
    public function test_defaults_lowestLayersNullRemoved(): void
    {
        $this->writeDefaults('setup.yaml', ['kernel' => ['name' => 'mercy', 'env' => 'production', 'domain' => null, 'debug' => false]]);
        $this->writeDefaults('dev.yaml', ['kernel' => ['env' => 'local']]);
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo']]);
        $project = $this->project();

        $this->assertSame(['mercy:setup.yaml', 'setup.yaml', 'mercy:dev.yaml'], array_keys($project->layers('dev')));
        $this->assertSame(['kernel' => ['name' => 'demo', 'env' => 'local', 'debug' => false]], $project->merged('dev'));
        $this->assertSame(['kernel' => ['name' => 'demo', 'env' => 'production', 'debug' => false]], $project->merged('prod'));
    }

    /**
     * Stage defaults win over the product base, the stage file wins over them.
     *
     * @return void
     */
    public function test_stageDefaults_betweenBaseAndStageFile(): void
    {
        $this->writeDefaults('setup.yaml', ['kernel' => ['dev_mode' => false, 'env' => 'production']]);
        $this->writeDefaults('dev.yaml', ['kernel' => ['dev_mode' => true, 'env' => 'local']]);
        $this->write('setup.yaml', ['kernel' => ['dev_mode' => false]]);
        $this->write('dev.yaml', ['kernel' => ['env' => 'testing']]);
        $project = $this->project();

        $this->assertSame(['mercy:setup.yaml', 'setup.yaml', 'mercy:dev.yaml', 'dev.yaml'], array_keys($project->layers('dev')));
        $this->assertSame(['kernel' => ['dev_mode' => true, 'env' => 'testing']], $project->merged('dev'));
    }

    /**
     * A value is redundant only if the layers before it already give it (not only the defaults).
     *
     * @return void
     */
    public function test_redundantKeys_comparedWithAllEarlierLayers(): void
    {
        $this->writeDefaults('setup.yaml', ['kernel' => ['service' => 'mercy.app', 'theme' => 'mercy-blank']]);
        $this->write('setup.yaml', ['kernel' => ['theme' => 'central-ui']]);
        $this->write('dev.yaml', ['kernel' => ['theme' => 'mercy-blank', 'service' => 'mercy.app']]);

        // theme in dev.yaml equals the default, but setup.yaml changed it before: needed
        $this->assertSame(['kernel.service' => 'dev.yaml'],
            $this->project()
                ->redundantKeys('dev'));
    }

    /**
     * @return void
     */
    public function test_unknownKeys_typosReportedFreeMapsAllowed(): void
    {
        $this->writeDefaults('setup.yaml', ['provisioning' => ['auto_port_start' => 27000], 'env' => [], 'requires' => [['names' => ['a']]]]);
        $this->write('setup.yaml', ['requires' => [['names' => ['b']]], 'env' => ['MY_KEY' => 'x']]);
        $this->write('dev.yaml', ['kernel' => ['auto_port_start' => 27500], 'provisioning' => ['auto_port_start' => 27500]]);

        $this->assertSame(['kernel.auto_port_start' => 'dev.yaml'],
            $this->project()
                ->unknownKeys('dev'));
    }

    /**
     * @return void
     */
    public function test_renamedKeys_movedWithWarning(): void
    {
        $this->writeDefaults('setup.yaml', ['ldap' => ['guid_auth_name' => 'cn'], 'git' => ['url_rewrites' => []]]);
        $this->write('setup.yaml', ['ldap' => ['guid_auth_nam' => 'uid'], 'url_rewrites' => [['base' => 'x']]]);
        $project = $this->project();

        $this->assertSame(['ldap' => ['guid_auth_name' => 'uid'], 'git' => ['url_rewrites' => [['base' => 'x']]]], $project->merged('dev'));
        $this->assertSame([], $project->unknownKeys('dev'));
        $this->assertCount(2, $project->warnings());
        $this->assertStringContainsString("'ldap.guid_auth_nam' in setup.yaml is now called 'ldap.guid_auth_name'", implode("\n", $project->warnings()));
    }

    /**
     * @return void
     */
    public function test_redundantKeys_sameValueAsDefault(): void
    {
        $this->writeDefaults('setup.yaml', ['kernel' => ['service' => 'mercy.app', 'theme' => 'mercy-blank']]);
        $this->write('setup.yaml', ['kernel' => ['service' => 'mercy.app', 'theme' => 'central-ui']]);

        $this->assertSame(['kernel.service' => 'setup.yaml'],
            $this->project()
                ->redundantKeys('dev'));
    }

    /**
     * The real defaults of this library: a project with only a name is complete, the stages differ.
     *
     * @return void
     */
    public function test_libraryDefaults_completeProjectFromName(): void
    {
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo']]);
        $project = new ProjectConfig($this->dir.'/project');

        $dev = $project->merged('dev');
        $this->assertSame('demo', $dev['kernel']['name']);
        $this->assertSame('local', $dev['kernel']['env']);
        $this->assertSame('production', $project->merged('prod')['kernel']['env']);
        $this->assertNotEmpty($dev['requires']);
        $this->assertArrayNotHasKey('domain', $dev['kernel'], '"~" in the defaults is not set');
        $this->assertSame([], $project->unknownKeys('dev'));
    }

    /**
     * An instance comes after the stage file, with the network it inherits; secrets stay last.
     *
     * @return void
     */
    public function test_instance_afterStageFileWithNetwork(): void
    {
        mkdir($this->dir.'/project/config/networks');
        mkdir($this->dir.'/project/config/instances');
        $this->write('setup.yaml', ['kernel' => ['name' => 'demo']]);
        $this->write('dev.yaml', ['kernel' => ['domain' => 'dev.example']]);
        $this->write('networks/home.yaml', ['npm' => ['url' => 'https://npm.home']]);
        $this->write('instances/vm-dev.yaml', ['extends' => ['../networks/home.yaml', '../setup.yaml'], 'kernel' => ['domain' => 'vm.example']]);
        $this->write('secrets.yaml', ['npm' => ['password' => 'secret']]);
        $project = new ProjectConfig($this->dir.'/project', $this->dir.'/defaults', 'vm-dev');

        $this->assertSame(['setup.yaml', 'dev.yaml', 'networks/home.yaml', 'instances/vm-dev.yaml', 'secrets.yaml'], array_keys($project->layers('dev')));
        $this->assertSame(['kernel' => ['name' => 'demo', 'domain' => 'vm.example'], 'npm' => ['url' => 'https://npm.home', 'password' => 'secret']], $project->merged('dev'));
        $this->assertSame(['vm-dev'], $project->instances());
    }

    /**
     * @return void
     */
    public function test_localYaml_lastLayerAfterSecrets(): void
    {
        $this->write('setup.yaml', ['git' => ['branch' => null], 'ldap' => ['password' => '']]);
        $this->write('secrets.yaml', ['ldap' => ['password' => 'secret']]);
        $this->write('local.yaml', ['git' => ['branch' => 'feature-x'], 'ldap' => ['password' => 'local']]);
        $project = $this->project();

        $this->assertSame(['setup.yaml', 'secrets.yaml', 'local.yaml'], array_keys($project->layers('dev')));
        $this->assertSame(['git' => ['branch' => 'feature-x'], 'ldap' => ['password' => 'local']], $project->merged('dev'));
    }

    /**
     * @return void
     */
    public function test_instance_unknown_throwsExceptionWithList(): void
    {
        mkdir($this->dir.'/project/config/instances');
        $this->write('setup.yaml', []);
        $this->write('instances/prod-server.yaml', []);

        $this->expectExceptionMessage('Instances: prod-server');
        new ProjectConfig($this->dir.'/project', $this->dir.'/defaults', 'prod-servr')->layers('prod');
    }
}
