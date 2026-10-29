<?php

namespace AklebeLaravel\MercyPhpDeployment\Tests\Feature;

use AklebeLaravel\MercyPhpDeployment\DockerProcessDeployment;
use AklebeLaravel\MercyPhpDeployment\SailProcessDeployment;
use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Copies the "Fixtures/app-blueprint" to a temp directory for every single test
 * to providing a clean base deployment folder.
 */
class DeploymentServiceTest extends TestCase
{
    private Filesystem $filesystem;
    private string $blueprintDir;
    private string $deploymentDestinationDir;
    private string $deploymentConfigTemplateDir;
    private string $deploymentConfigDir;
    private array $configList = ['setup'];

    /**
     * preparations for each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem();
        $this->blueprintDir = __DIR__.'/../Fixtures/app-blueprint/dev';
        $this->deploymentDestinationDir = sys_get_temp_dir().'/deployment_test_'.uniqid(); // uniqid() : no blocking simultan tests
        $this->filesystem->mirror($this->blueprintDir, $this->deploymentDestinationDir);
    }

    /**
     * cleanups for each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Nach dem Test aufräumen, damit die Festplatte nicht vollreißt
        $this->filesystem->remove($this->deploymentDestinationDir);
    }

    /**
     * @param  bool  $useCleanSetupDir
     *
     * @return void
     */
    private function makeAndValidatePaths(bool $useCleanSetupDir): void
    {
        $this->deploymentConfigTemplateDir = $this->deploymentDestinationDir.'/resources/setup';
        $this->deploymentConfigDir = $useCleanSetupDir ? $this->deploymentDestinationDir.'/storage/app/last-setup-clean' : $this->deploymentDestinationDir.'/storage/app/last-setup';

        // ensure the clean dir exist
        if ($useCleanSetupDir) {
            $this->filesystem->mkdir($this->deploymentConfigDir);
        }

        $this->assertTrue(is_dir($this->deploymentConfigTemplateDir));
        $this->assertTrue(is_dir($this->deploymentConfigDir));
    }

    /**
     * @return void
     */
    private function prepareCleanConfigPath(): void
    {
        // use the clean setup dir (true)
        $this->makeAndValidatePaths(true);

        // remove the old setup dir
        $this->filesystem->remove($this->deploymentConfigDir);
        $this->assertTrue(!is_dir($this->deploymentConfigDir));

        // and create it again
        $this->filesystem->mkdir($this->deploymentConfigDir);
        $this->assertTrue(is_dir($this->deploymentConfigDir));
    }

    /**
     * @return SailProcessDeployment
     * @throws Exception
     */
    private function getDeploymentInstance(): SailProcessDeployment
    {
        $d = new SailProcessDeployment();
        $d->initSetup($this->deploymentDestinationDir, $this->deploymentConfigTemplateDir, $this->deploymentConfigDir, $this->configList);

        return $d;
    }

    /**
     * @return void
     */
    public function test_initSetup_withAllConfigsPresent_configPrepared(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        // checking install config vars
        $this->assertSame('prox-top.dev.example.com', data_get($deployment->yamlConfigs, 'setup.kernel.domain'));
        $this->assertSame(27100, data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_start'));
        $this->assertSame('gitea@gitea.example.com', data_get($deployment->yamlConfigs, 'setup.git.source'));

        // checking other config vars
        $this->assertSame('system-base-module', data_get($deployment->yamlConfigs, 'setup.requires.0.names.0'));
        $this->assertSame('central-ui-module', data_get($deployment->yamlConfigs, 'setup.requires.0.names.6'));
        $this->assertSame(27100, data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_start'));
        $this->assertSame(11, data_get($deployment->yamlConfigs, 'setup.npm.hosts.0.certificate_id'));

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function test_initSetup_withoutInstallConfig_throwsException(): void
    {
        // prepare paths and make clean the config path
        $this->prepareCleanConfigPath();

        // We have to get an exception because the needed setup.yaml is missing
        try {
            $deployment = $this->getDeploymentInstance();
            // If no exception here, then fail!
            $this->fail();
        } catch (Exception $e) {
        }

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function test_initSetup_withInstallConfigOnly_configPrepared(): void
    {
        // prepare paths and make clean the config path
        $this->prepareCleanConfigPath();

        // copy the "setup.yaml" to the blank directory (was already modified by user)
        $this->filesystem->copy($this->blueprintDir.'/storage/app/last-setup/setup.yaml', $this->deploymentConfigDir.'/setup.yaml');

        //
        $deployment = $this->getDeploymentInstance();

        // checking install config vars - modified values
        $this->assertSame('prox-top.dev.example.com', data_get($deployment->yamlConfigs, 'setup.kernel.domain'));
        $this->assertSame(27100, data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_start'));
        $this->assertSame('gitea@gitea.example.com', data_get($deployment->yamlConfigs, 'setup.git.source'));
        $this->assertSame('system-base-module', data_get($deployment->yamlConfigs, 'setup.requires.0.names.0'));
        $this->assertSame('central-ui-module', data_get($deployment->yamlConfigs, 'setup.requires.0.names.6'));
        $this->assertSame(11, data_get($deployment->yamlConfigs, 'setup.npm.hosts.0.certificate_id'));
        $this->assertSame(11, data_get($deployment->yamlConfigs, 'setup.npm.hosts.1.certificate_id'));


        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function test_checkDir_dirExist(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertTrue($deployment->checkDir($this->deploymentConfigDir));
    }

    /**
     * @return void
     */
    public function test_checkDir_dirNotExist(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertTrue(!$deployment->checkDir($this->deploymentConfigDir.'-xxxxxx-'.uniqid()));
    }

    /**
     * @return void
     */
    public function test_getYamlConfigFullPath_fileExist(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertTrue(file_exists($deployment->getYamlConfigFullPath('setup')));
    }

    /**
     * @return void
     */
    public function test_saveRequiredConfigsAndValidateByLoadingAgain_valuesMatched(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        // manipulating
        $newBranch = 'test123';
        data_set($deployment->yamlConfigs, 'setup.requires.1.branch', $newBranch);
        $newTheme = 'test-theme';
        $themes = data_get($deployment->yamlConfigs, 'setup.requires.1.names', []);
        $themes[] = $newTheme;
        data_set($deployment->yamlConfigs, 'setup.requires.1.names', $themes);

        // saving
        $this->assertTrue($deployment->saveRequiredConfigs());

        // loading
        $deployment->prepareAndLoadRequiredYamlConfigs();

        // validating
        $this->assertSame(data_get($deployment->yamlConfigs, 'setup.requires.1.branch'), $newBranch);
        $this->assertSame(data_get($deployment->yamlConfigs, 'setup.requires.1.names.'.(count($themes) - 1)), $newTheme);
    }

    /**
     * @return void
     */
    public function test_copyIfNotExists_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->filesystem->touch($this->deploymentConfigDir.'/testing-123.yaml');
        $this->assertTrue(!$deployment->copyFile($this->deploymentConfigDir.'/testing-123.yaml', $this->deploymentConfigDir.'/testing-124.yaml'));
        $this->assertTrue(!$deployment->copyFile($this->deploymentConfigDir.'/testing-124.yaml', $this->deploymentConfigDir.'/testing-125.yaml'));
        $this->assertTrue($deployment->copyFile($this->deploymentConfigDir.'/testing-125.yaml', $this->deploymentConfigDir.'/testing-123.yaml'));
    }

    /**
     * @return void
     */
    public function test_getThemeDir_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertSame('mercy-blank', $deployment->getThemeDir('mercy-blank-theme'));
        $this->assertSame('my-great-awful', $deployment->getThemeDir('my-great-awful-theme'));
        $this->assertSame('another-beautiful', $deployment->getThemeDir('AnotherBeautifulTheme'));
        $this->assertSame('another-beautiful', $deployment->getThemeDir('AnotherBeautiful'));
    }

    /**
     * @return void
     */
    public function test_getFirstRequiredBlockWithTheme_withInstallConfig_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $requiredObject = $deployment->getFirstRequiredBlockWithThemeNames();
        $themeGit = data_get($requiredObject, 'names.0');
        $this->assertSame('central-ui-theme', $themeGit);
    }

    /**
     * @return void
     */
    public function test_validateYamlTypes_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertSame(true, data_get($deployment->yamlConfigs, 'setup.kernel.debug'));
        $this->assertSame(true, data_get($deployment->yamlConfigs, 'setup.provisioning.smart_app_instance_key'));
        //$this->assertSame(false, data_get($deployment->yamlConfigs, 'setup.build_steps.create_bundle'));
        $this->assertSame(27100, data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_start'));
        $this->assertSame(1.234, data_get($deployment->yamlConfigs, 'setup.unit_test.my_double'));
    }

    /**
     * @return void
     */
    public function test_getFirstRequiredBlockWithThemeNames_withoutInstallConfig_true(): void
    {
        // prepare paths and make clean the config path
        $this->prepareCleanConfigPath();

        // copy the "setup.yaml" to the blank directory (was already modified by user)
        $this->filesystem->copy($this->blueprintDir.'/resources/setup/setup.yaml', $this->deploymentConfigDir.'/setup.yaml');

        $deployment = $this->getDeploymentInstance();

        $requiredObject = $deployment->getFirstRequiredBlockWithThemeNames();
        $themeGit = data_get($requiredObject, 'names.0');
        $this->assertSame('mercy-blank-theme', $themeGit);
    }

    /**
     * @return void
     */
    public function test_prepareEnvFile_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertTrue($deployment->prepareEnvFile('setup-local.env', $deployment->deploymentDestinationRootPath.'/.env'));

        // validate the blueprint 'setup-local.env'
        $env = $deployment->getEnvConfig();
        $env->load($this->blueprintDir.'/resources/setup/setup-local.env');
        $this->assertSame('mercy-blank', $env->getValue('THEME_NAME')->value);

        // validate the 'setup-local.env'
        //$deployment->prepareAndLoadRequiredYamlConfigs();
        $env = $deployment->getEnvConfig();
        $env->load($deployment->configPath.'/setup-local.env');
        $this->assertSame('central-ui', $env->getValue('THEME_NAME')->value);
        $this->assertSame($env->getValue('MODULE_DEPLOYENV_REQUIRE_MODULES_GIT')->value, data_get($deployment->yamlConfigs, 'setup.git.source').':${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git');

        // validate the additional
        $env = $deployment->getEnvConfig();
        $env->load($deployment->deploymentDestinationRootPath.'/.env');
        $this->assertSame('central-ui', $env->getValue('THEME_NAME')->value);
    }

    /**
     * @return void
     */
    public function test_envConfig_loadEnvAndValidating_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $envFilename = $this->deploymentConfigDir.'/setup-local.env';
        $this->assertTrue(file_exists($envFilename));

        // simple read and validate
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        $this->assertSame(true, $env->getValue('APP_VERSION')->parsable);
        $this->assertSame('2.0.0', $env->getValue('APP_VERSION')->value);
        $this->assertSame(true, $env->getValue('APP_URL')->parsable);
        $this->assertSame('https://${APP_DOMAIN}', $env->getValue('APP_URL')->value);
        $this->assertSame(true, $env->getValue('REVERB_APP_ID')->parsable); // double-quoted
        $this->assertSame('mercy-reverb-app-${APP_INSTANCE_KEY}', $env->getValue('REVERB_APP_ID')->value);
        $this->assertSame(false, $env->getValue('NPM_API_PASSWORD')->parsable); // single-quoted
        $this->assertSame('$MyPassword123', $env->getValue('NPM_API_PASSWORD')->value);
    }

    ///**
    // * @return void
    // */
    //public function test_is_numeric_true(): void
    //{
    //    $this->assertTrue(is_numeric('2.0'));
    //}

    /**
     * @return void
     */
    public function test_envConfig_load_manipulate_save_validating_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $envFilename = $this->deploymentConfigDir.'/setup-local.env';
        $this->assertTrue(file_exists($envFilename));

        // load
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);
        $this->assertSame(null, $env->getValue('NON_EXISTING_VAR_X1')?->value);

        // manipulate
        $env->setValue('APP_VERSION', '2.1.0');
        $env->setValue('TELEGRAM_WEBHOOK_URL', 'telegram_test_with_var_${APP_INSTANCE_KEY}', true);
        //$env->setValue('NPM_API_PASSWORD', '$MyPassword123');
        $env->setValue('NON_EXISTING_VAR_X1', true);
        $env->setValue('NON_EXISTING_VAR_X2', 42);
        $env->setValue('NON_EXISTING_VAR_X3', '');
        $env->setValue('NON_EXISTING_VAR_X4', null);
        $env->setValue('NON_EXISTING_VAR_X5', "12345'es");
        $env->setValue('NON_EXISTING_VAR_X6', "12345'es", false);
        $env->setValue('NON_EXISTING_VAR_X7', "12345'es", true);
        $env->setValue('NON_EXISTING_VAR_X8', '123456$poll', false);
        $env->setValue('NON_EXISTING_VAR_X9', '123456$poll', true);
        $env->setValue('NON_EXISTING_VAR_Y1', 'gitea@gitea.intranet.my-domain.de:${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git', true);

        // in-memory validation
        $this->assertSame("base64:ABC123456789", $env->getValue('APP_KEY2')->value);
        $this->assertSame('2.1.0', $env->getValue('APP_VERSION')->value);
        $this->assertSame('telegram_test_with_var_${APP_INSTANCE_KEY}', $env->getValue('TELEGRAM_WEBHOOK_URL')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X1')->value);
        $this->assertSame(42, $env->getValue('NON_EXISTING_VAR_X2')->value);
        $this->assertSame('', $env->getValue('NON_EXISTING_VAR_X3')->value);
        $this->assertSame(null, $env->getValue('NON_EXISTING_VAR_X4')->value);
        $this->assertSame('12345\'es', $env->getValue('NON_EXISTING_VAR_X5')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X6')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X7')->value);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X8')->value);
        $this->assertSame(false, $env->getValue('NON_EXISTING_VAR_X8')->parsable);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X9')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X9')->parsable);
        $this->assertSame('gitea@gitea.intranet.my-domain.de:${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git', $env->getValue('NON_EXISTING_VAR_Y1')->value);

        // save and reload (1)
        $env->save();
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        // validation after reload
        $this->assertSame("base64:ABC123456789", $env->getValue('APP_KEY2')->value);
        $this->assertSame('2.1.0', $env->getValue('APP_VERSION')->value);
        $this->assertSame(true, $env->getValue('APP_VERSION')->parsable);
        $this->assertSame('telegram_test_with_var_${APP_INSTANCE_KEY}', $env->getValue('TELEGRAM_WEBHOOK_URL')->value);
        $this->assertSame(true, $env->getValue('TELEGRAM_WEBHOOK_URL')->parsable);
        $this->assertSame(false, $env->getValue('NPM_API_PASSWORD')->parsable); // single-quoted
        $this->assertSame('$MyPassword123', $env->getValue('NPM_API_PASSWORD')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X1')->value);
        $this->assertSame(42, $env->getValue('NON_EXISTING_VAR_X2')->value);
        $this->assertSame('', $env->getValue('NON_EXISTING_VAR_X3')->value);
        $this->assertSame(null, $env->getValue('NON_EXISTING_VAR_X4')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X5')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X6')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X7')->value);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X8')->value);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X8')->value);
        $this->assertSame(false, $env->getValue('NON_EXISTING_VAR_X8')->parsable);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X9')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X9')->parsable);
        $this->assertSame('gitea@gitea.intranet.my-domain.de:${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git', $env->getValue('NON_EXISTING_VAR_Y1')->value);

        // save and reload (2)
        $env->save();
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        // validation after reload
        $this->assertSame("base64:ABC123456789", $env->getValue('APP_KEY2')->value);
        $this->assertSame('2.1.0', $env->getValue('APP_VERSION')->value);
        $this->assertSame(true, $env->getValue('APP_VERSION')->parsable);
        $this->assertSame('telegram_test_with_var_${APP_INSTANCE_KEY}', $env->getValue('TELEGRAM_WEBHOOK_URL')->value);
        $this->assertSame(true, $env->getValue('TELEGRAM_WEBHOOK_URL')->parsable);
        $this->assertSame(false, $env->getValue('NPM_API_PASSWORD')->parsable); // single-quoted
        $this->assertSame('$MyPassword123', $env->getValue('NPM_API_PASSWORD')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X1')->value);
        $this->assertSame(42, $env->getValue('NON_EXISTING_VAR_X2')->value);
        $this->assertSame('', $env->getValue('NON_EXISTING_VAR_X3')->value);
        $this->assertSame(null, $env->getValue('NON_EXISTING_VAR_X4')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X5')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X6')->value);
        $this->assertSame("12345'es", $env->getValue('NON_EXISTING_VAR_X7')->value);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X8')->value);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X8')->value);
        $this->assertSame(false, $env->getValue('NON_EXISTING_VAR_X8')->parsable);
        $this->assertSame('123456$poll', $env->getValue('NON_EXISTING_VAR_X9')->value);
        $this->assertSame(true, $env->getValue('NON_EXISTING_VAR_X9')->parsable);
        $this->assertSame('gitea@gitea.intranet.my-domain.de:${MODULE_DEPLOYENV_REQUIRE_MODULES_DEFAULT_VENDOR}/\${{module_snake_name_git}}.git', $env->getValue('NON_EXISTING_VAR_Y1')->value);

        $env->setValue('NON_EXISTING_VAR_Y1', $env->getValue('NON_EXISTING_VAR_Y1')->value, false);
        $env->setValue('NON_EXISTING_VAR_Y1', $env->getValue('NON_EXISTING_VAR_Y1')->value);
    }

    /**
     * @return void
     */
    public function test_yamlToEnvCheck_validate_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        // prepare and save the env
        $deployment->prepareEnvFile('setup-local.env');

        // reload
        $envFilename = $deployment->configPath.'/setup-local.env';
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        $this->assertSame('$MySpecialPassword9000', $env->getValue('NPM_API_PASSWORD')->value);
        $this->assertSame(false, $env->getValue('NPM_API_PASSWORD')->parsable);
    }

    /**
     * 1) change yaml vars
     * 2) save yaml
     * 3) assign specific yaml section to env using {@see SailProcessDeployment::saveEnvBySections}
     * 4) validate specific secion has changed, but other section has not
     *
     * @return void
     */
    public function test_yamlToEnvCheckAndReparse_validate_true(): void
    {
        // prepare paths
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        // prepare and save the env
        $deployment->prepareEnvFile('setup-local.env');

        // reload
        $envFilename = $deployment->configPath.'/setup-local.env';
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        $this->assertSame(false, $env->getValue('LDAP_ENABLED')->value);

        // set yaml value we want save later
        data_set($deployment->yamlConfigs, 'setup.ldap.enabled', true);
        // set yaml value we DON'T want save later
        data_set($deployment->yamlConfigs, 'setup.kernel.env', 'integration');

        // save yaml
        $deployment->saveRequiredConfigs();

        // reload all yaml configs
        $deployment->prepareAndLoadRequiredYamlConfigs();

        // recalculate env
        $deployment->saveEnvBySections($envFilename, ['setup.ldap']);

        // reload env
        $env = $deployment->getEnvConfig();
        $env->load($envFilename);

        // this value should be changed
        $this->assertSame(true, $env->getValue('LDAP_ENABLED')->value);
        // this value should NOT be changed
        $this->assertSame('local', $env->getValue('APP_ENV')->value);
    }

    /**
     * A config env freshly created from the template gets the calculated project name,
     * not the template's placeholder value.
     *
     * @return void
     */
    public function test_stickyEnvKeys_freshFromTemplate_calculated(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $this->filesystem->remove($deployment->configPath.'/setup-local.env');

        $deployment->prepareEnvFile('setup-local.env', $this->deploymentDestinationDir.'/.env');

        $env = $deployment->getEnvConfig()
            ->load($deployment->configPath.'/setup-local.env');
        $this->assertSame('proxtop-${APP_INSTANCE_KEY}', $env->getValue('COMPOSE_PROJECT_NAME')->value);
    }

    /**
     * Auto ports keep their value on a second run, even if they are in use (by the own running containers).
     *
     * @return void
     */
    public function test_stickyEnvKeys_autoPortsKeptWhileInUse_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $configEnv = $deployment->configPath.'/setup-local.env';
        $portKey = data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_env.0');
        $this->assertNotEmpty($portKey);

        $deployment->prepareEnvFile('setup-local.env', $this->deploymentDestinationDir.'/.env');
        $port = (int) $deployment->getEnvConfig()
            ->load($configEnv)
            ->getValue($portKey)->value;

        // occupy the port like a running container would
        $server = @stream_socket_server("tcp://127.0.0.1:$port");
        $this->assertNotFalse($server, "Port $port could not be opened for the test.");
        try {
            $deployment->prepareEnvFile('setup-local.env', $this->deploymentDestinationDir.'/.env');
        } finally {
            fclose($server);
        }

        $this->assertSame($port,
            (int) $deployment->getEnvConfig()
                ->load($configEnv)
                ->getValue($portKey)->value);
    }

    /**
     * A new "provisioning.auto_port_start" moves the ports (otherwise they are sticky).
     *
     * @return void
     */
    public function test_stickyEnvKeys_autoPortsMoveWithNewStart_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $configEnv = $deployment->configPath.'/setup-local.env';
        $portKey = data_get($deployment->yamlConfigs, 'setup.provisioning.auto_port_env.0');

        data_set($deployment->yamlConfigs, 'setup.provisioning.auto_port_start', 41000);
        $deployment->prepareEnvFile('setup-local.env', $this->deploymentDestinationDir.'/.env');
        $first = (int) $deployment->getEnvConfig()->load($configEnv)->getValue($portKey)->value;
        $this->assertGreaterThanOrEqual(41000, $first);

        data_set($deployment->yamlConfigs, 'setup.provisioning.auto_port_start', 42000);
        $deployment->prepareEnvFile('setup-local.env', $this->deploymentDestinationDir.'/.env');
        $env = $deployment->getEnvConfig()->load($configEnv);

        $this->assertGreaterThanOrEqual(42000, (int) $env->getValue($portKey)->value);
        $this->assertSame(42000, (int) $env->getValue('MERCY_AUTO_PORT_START')->value);
    }

    /**
     * Project name and instance key keep their first value, also after the config env was deleted.
     *
     * @return void
     */
    public function test_stickyEnvKeys_keptWhenConfigChanges_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $target = $this->deploymentDestinationDir.'/.env';
        $configEnv = $deployment->configPath.'/setup-local.env';

        $deployment->prepareEnvFile('setup-local.env', $target);
        $first = $deployment->getEnvConfig()
            ->load($configEnv);
        $key = $first->getValue('APP_INSTANCE_KEY')->value;
        $project = $first->getValue('COMPOSE_PROJECT_NAME')->value;
        $this->assertNotEmpty($key);

        // rename the project and delete the config env (like a "fresh" install)
        data_set($deployment->yamlConfigs, 'setup.kernel.name', 'renamed-project');
        $this->filesystem->remove($configEnv);
        $deployment->prepareEnvFile('setup-local.env', $target);

        $second = $deployment->getEnvConfig()
            ->load($configEnv);
        $this->assertSame($key, $second->getValue('APP_INSTANCE_KEY')->value);
        $this->assertSame($project, $second->getValue('COMPOSE_PROJECT_NAME')->value);
    }

    /**
     * A second run (dev "update") replaces the target env with the config env, the APP_KEY of the target survives.
     *
     * @return void
     */
    public function test_prepareEnvFile_keepsAppKeyOfTarget_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $target = $this->deploymentDestinationDir.'/.env';

        $deployment->prepareEnvFile('setup-local.env', $target);
        // like "artisan key:generate" after the install
        $deployment->getEnvConfig()
            ->load($target)
            ->setValue('APP_KEY', 'base64:dGVzdC1rZXk=')
            ->save();

        $deployment->prepareEnvFile('setup-local.env', $target);

        $this->assertSame('base64:dGVzdC1rZXk=',
            $deployment->getEnvConfig()
                ->load($target)
                ->getValue('APP_KEY')->value);
        // the key stays out of the config env
        $this->assertEmpty((string) $deployment->getEnvConfig()
            ->load($deployment->configPath.'/setup-local.env')
            ->getValue('APP_KEY'));
    }

    /**
     * A new version does not change the instance key (it would mean a new docker project).
     *
     * @return void
     */
    public function test_instanceKey_independentOfVersion_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $before = $deployment->prepareEnvVars()['APP_INSTANCE_KEY'] ?? null;
        data_set($deployment->yamlConfigs, 'setup.kernel.version', '9.9.9');
        $after = $deployment->prepareEnvVars()['APP_INSTANCE_KEY'] ?? null;

        $this->assertNotEmpty($before);
        $this->assertSame($before, $after);
    }

    /**
     * Dev without "kernel.version": the version comes from "git describe" of the project.
     *
     * @return void
     */
    public function test_getAppVersion_withoutVersion_fromGit(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        data_set($deployment->yamlConfigs, 'setup.kernel.version', '');
        $root = $this->deploymentDestinationDir;
        exec('cd '.escapeshellarg($root).' && git init -q && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init && git tag v1.2.3', $out, $code);
        $this->assertSame(0, $code);

        $this->assertSame('v1.2.3', $deployment->getAppVersion());
        $this->assertStringEndsWith(':v1.2.3', $deployment->getImageNameVersion());
    }

    /**
     * Production needs the release to run, there is no git fallback.
     *
     * @return void
     */
    public function test_getAppVersion_prodWithoutVersion_throwsException(): void
    {
        $deployment = new DockerProcessDeployment();
        $deployment->yamlConfigs = ['setup' => ['kernel' => ['name' => 'mercy']]];

        $this->expectException(Exception::class);
        $deployment->getAppVersion();
    }

    /**
     * Prod "update": the calculated values follow setup.yaml, values written by hand stay.
     *
     * @return void
     */
    public function test_refreshEnvFile_updatesVersionKeepsOwnValues_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $target = $this->deploymentDestinationDir.'/.env';
        $deployment->prepareEnvFile('setup-local.env', $target);
        $deployment->getEnvConfig()
            ->load($target)
            ->setValue('MY_OWN_VALUE', 'by-hand')
            ->save();

        data_set($deployment->yamlConfigs, 'setup.kernel.version', '9.8.7-rc1');
        $this->assertTrue($deployment->refreshEnvFile($target));

        $env = $deployment->getEnvConfig()
            ->load($target);
        $this->assertSame('9.8.7-rc1', $env->getValue('APP_VERSION')->value);
        $this->assertSame('by-hand', $env->getValue('MY_OWN_VALUE')->value);
    }

    /**
     * build.lock.yaml lists the commit of the root and every module/theme repo and the dev-* composer packages.
     *
     * @return void
     */
    public function test_writeBuildLock_repositoriesAndComposer_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $root = $this->deploymentDestinationDir;

        $git = function (string $dir, string ...$args): void {
            $this->assertTrue($this->runGit($dir, ...$args), 'git '.implode(' ', $args));
        };
        foreach ([$root, "$root/Modules/SystemBase"] as $dir) {
            $this->filesystem->mkdir($dir);
            $git($dir, 'init', '-q', '-b', 'master');
            $git($dir, '-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '--allow-empty', '-m', 'init');
        }
        $git("$root/Modules/SystemBase", 'remote', 'add', 'origin', 'https://user:secret@git.example.com/org/system-base-module.git');
        $this->filesystem->mkdir("$root/Modules/NoRepo");
        $this->filesystem->dumpFile("$root/composer.lock", json_encode([
            'packages' => [
                ['name' => 'vendor/branch-package', 'version' => 'dev-master', 'source' => ['type' => 'git', 'reference' => 'abc123']],
                ['name' => 'vendor/tagged-package', 'version' => 'v1.0.0', 'source' => ['type' => 'git', 'reference' => 'def456']],
            ],
        ]));

        $this->assertTrue($deployment->writeBuildLock($root));
        $lock = Yaml::parseFile("$root/build.lock.yaml");

        $this->assertSame(['.', 'Modules/SystemBase'], array_column($lock['repositories'], 'path'));
        $this->assertSame('https://git.example.com/org/system-base-module.git', $lock['repositories'][1]['remote']);
        $this->assertSame(40, strlen($lock['repositories'][1]['commit']));
        $this->assertSame(['vendor/branch-package' => ['version' => 'dev-master', 'commit' => 'abc123']], $lock['composer']);
    }

    /**
     * @param  string  $dir
     * @param  string  ...$args
     *
     * @return bool
     */
    private function runGit(string $dir, string ...$args): bool
    {
        $process = new \Symfony\Component\Process\Process(['git', ...$args], $dir);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return void
     */
    public function test_getCoreConfig_fromTemplates_modulesAndTheme(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();

        $this->assertSame([
            'modules'       => [
                ['name' => 'system-base-module', 'directory' => 'Modules/SystemBase'],
                ['name' => 'deploy-env-module', 'directory' => 'Modules/DeployEnv'],
            ],
            'default_theme' => 'mercy-blank-theme',
        ], $deployment->getCoreConfig());
    }

    /**
     * @return void
     */
    public function test_getCoreConfig_missingFile_throwsException(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $this->filesystem->remove($this->deploymentConfigTemplateDir.'/core.yaml');

        $this->expectException(Exception::class);
        $deployment->getCoreConfig();
    }

    /**
     * Free env vars from "setup.env" are appended to the env (or overwrite existing keys).
     *
     * @return void
     */
    public function test_yamlFreeEnvVars_writtenToEnv_true(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        $deployment->prepareEnvFile('setup-local.env');
        $envFilename = $deployment->configPath.'/setup-local.env';

        data_set($deployment->yamlConfigs, 'setup.env', [
            'MY_MODULE_API_URL'    => 'https://api.example.com',
            'MY_MODULE_API_KEY'    => '',
            'MY_MODULE_VERIFY_TLS' => true,
            'APP_ENV'              => 'staging',
        ]);
        $deployment->saveEnvBySections($envFilename, ['setup.env']);

        $env = $deployment->getEnvConfig();
        $env->load($envFilename);
        $this->assertSame('https://api.example.com', $env->getValue('MY_MODULE_API_URL')->value);
        $this->assertSame('', $env->getValue('MY_MODULE_API_KEY')->value);
        $this->assertSame(true, $env->getValue('MY_MODULE_VERIFY_TLS')->value);
        $this->assertSame('staging', $env->getValue('APP_ENV')->value);
    }

    /**
     * @return void
     */
    public function test_yamlFreeEnvVars_invalidName_throwsException(): void
    {
        $this->makeAndValidatePaths(false);
        $deployment = $this->getDeploymentInstance();
        data_set($deployment->yamlConfigs, 'setup.env', ['lower-case' => 'x']);

        $this->expectException(Exception::class);
        $deployment->prepareEnvVars(['setup.env']);
    }

}