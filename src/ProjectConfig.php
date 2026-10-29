<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * The project folder ("project/" inside the scaffold or the installer) is the one place
 * to edit the configs of a project. The Mercy defaults (resources/defaults of this library) come with it,
 * so a project file contains only what differs:
 *
 *   mercy:setup.yaml (lowest) -> project files the stage inherits (setup.yaml, networks/...)
 *   -> mercy:<stage>.yaml -> project <stage>.yaml -> secrets
 *
 * The stage defaults sit right before the stage file: they win over the product base (e.g. dev_mode of the
 * dev stage), the stage file wins over them. An instance (one installation, chosen with --instance) comes after
 * the stage file: config/instances/<name>.yaml with its "extends" (usually a network file). Project files:
 *
 *   project/config/setup.yaml           base config of the product
 *   project/config/<stage>.yaml         entry of a stage (dev, build, prod): its differences
 *   project/config/secrets.yaml         passwords and keys of this machine, NOT versioned
 *   project/config/<stage>.secrets.yaml optional, NOT versioned
 *
 * Every yaml may inherit other yamls with "extends" (list, paths relative to the file):
 *
 *   extends: [ setup.yaml, networks/home.yaml ]
 *
 * The inherited files come first (in the given order, each with its own "extends"), then the file itself.
 * A stage file without "extends" inherits "setup.yaml". The secrets are merged last.
 * The result is written to the config dir (e.g. storage/app/last-setup/setup.yaml), where the deployment reads it.
 *
 * Keys that the defaults do not know are reported by unknownKeys() (typos, old names); keys that were renamed
 * are moved to their new name (RENAMED_KEYS) and reported by warnings().
 */
final class ProjectConfig
{
    public const string EXTENDS_KEY = 'extends';

    /**
     * Label prefix of the layers that come from the Mercy defaults.
     */
    public const string DEFAULTS_PREFIX = 'mercy:';

    /**
     * Old key => new key. Old project files keep working, with a warning.
     */
    public const array RENAMED_KEYS = [
        'url_rewrites'       => 'git.url_rewrites',
        'ldap.guid_auth_nam' => 'ldap.guid_auth_name',
    ];

    public readonly string $defaultsDir;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @param  string       $projectDir
     * @param  string|null  $defaultsDir  default: resources/defaults of this library
     * @param  string|null  $instance     name of config/instances/<name>.yaml
     */
    public function __construct(public readonly string $projectDir, ?string $defaultsDir = null, public readonly ?string $instance = null)
    {
        $this->defaultsDir = $defaultsDir ?? dirname(__DIR__).'/resources/defaults';
    }

    /**
     * Names of the instances of this project (config/instances/*.yaml).
     *
     * @return list<string>
     */
    public function instances(): array
    {
        $names = array_map(fn(string $file) => basename($file, '.yaml'), glob($this->configDir().'/instances/*.yaml') ?: []);
        sort($names);

        return $names;
    }

    /**
     * @param  string  $name
     *
     * @return string
     * @throws Exception
     */
    public function instanceFile(string $name): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new Exception("Invalid instance name '$name' (lowercase letters, digits, '-' and '_').");
        }
        $file = $this->configDir()."/instances/$name.yaml";
        if (!is_file($file)) {
            $known = $this->instances();
            throw new Exception(sprintf("Unknown instance '%s'. %s", $name, $known ? 'Instances: '.implode(', ', $known) : 'This project has no config/instances/*.yaml.'));
        }

        return $file;
    }

    /**
     * @return string
     */
    public function configDir(): string
    {
        return rtrim($this->projectDir, '/').'/config';
    }

    /**
     * Layers of a stage in merge order.
     *
     * @param  string  $stage
     *
     * @return array<string, string> label (path relative to config/) => file
     * @throws Exception
     */
    public function layers(string $stage): array
    {
        $dir = $this->configDir();
        $entry = "$dir/$stage.yaml";
        if (is_file($entry)) {
            $files = $this->resolve($entry, true, []);
            // the stage file itself is the last one of its chain
            $stageFile = array_pop($files);
        } elseif (is_file("$dir/setup.yaml")) {
            $files = $this->resolve("$dir/setup.yaml", false, []);
            $stageFile = null;
        } else {
            throw new Exception("Missing project config: neither $entry nor $dir/setup.yaml");
        }

        $defaults = $this->defaultLayers($stage);
        $layers = array_slice($defaults, 0, 1, true);
        foreach (array_unique($files) as $file) {
            $layers[$this->label($file)] = $file;
        }
        // stage defaults: after the product base, before the stage file
        $layers += array_slice($defaults, 1, null, true);
        if ($stageFile !== null) {
            $layers[$this->label($stageFile)] = $stageFile;
        }
        // the instance and what it inherits (network, ...); files already used keep their earlier position
        if ($this->instance !== null) {
            foreach ($this->resolve($this->instanceFile($this->instance), false, []) as $file) {
                $layers[$this->label($file)] ??= $file;
            }
        }
        foreach (['secrets.yaml', "$stage.secrets.yaml"] as $name) {
            if (is_file("$dir/$name")) {
                $file = (string) realpath("$dir/$name");
                $layers[$this->label($file)] = $file;
            }
        }

        return $layers;
    }

    /**
     * The Mercy defaults of a stage: setup.yaml, then <stage>.yaml (if any).
     *
     * @param  string  $stage
     *
     * @return array<string, string> label => file
     */
    public function defaultLayers(string $stage): array
    {
        $layers = [];
        foreach (['setup.yaml', "$stage.yaml"] as $name) {
            $file = rtrim($this->defaultsDir, '/').'/'.$name;
            if (is_file($file)) {
                $layers[self::DEFAULTS_PREFIX.$name] = $file;
            }
        }

        return $layers;
    }

    /**
     * @param  string  $label
     *
     * @return bool
     */
    public static function isDefaultsLayer(string $label): bool
    {
        return str_starts_with($label, self::DEFAULTS_PREFIX);
    }

    /**
     * Content of every layer of a stage, renamed keys already moved.
     *
     * @param  string  $stage
     *
     * @return array<string, array> label => data
     * @throws Exception
     */
    public function layerData(string $stage): array
    {
        $result = [];
        foreach ($this->layers($stage) as $label => $file) {
            $data = self::loadLayer($file);
            $result[$label] = self::isDefaultsLayer($label) ? $data : $this->renameKeys($data, $label);
        }

        return $result;
    }

    /**
     * Messages about renamed keys found while merging (each once).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Keys of the project layers that the Mercy defaults do not know.
     * Below a default that is a map or list (env, ssh.fingerprints, requires) any key is allowed.
     *
     * @param  string  $stage
     *
     * @return array<string, string> key => label of the layer
     * @throws Exception
     */
    public function unknownKeys(string $stage): array
    {
        $layers = $this->layerData($stage);
        $known = [];
        foreach ($layers as $label => $data) {
            if (self::isDefaultsLayer($label)) {
                $known += self::flatten($data);
            }
        }
        $unknown = [];
        foreach ($layers as $label => $data) {
            if (self::isDefaultsLayer($label)) {
                continue;
            }
            foreach (array_keys(self::flatten($data)) as $key) {
                if (!self::isKnownKey($key, $known)) {
                    $unknown[$key] = $label;
                }
            }
        }

        return $unknown;
    }

    /**
     * Keys a project layer sets to the value the layers before it already give (removing them changes nothing).
     * Compared with all earlier layers, not only the defaults: "dev_mode: true" in dev.yaml is needed if the
     * project setup.yaml sets it to false.
     *
     * @param  string  $stage
     *
     * @return array<string, string> key => label of the layer
     * @throws Exception
     */
    public function redundantKeys(string $stage): array
    {
        $before = [];
        $redundant = [];
        foreach ($this->layerData($stage) as $label => $data) {
            if (!self::isDefaultsLayer($label)) {
                $flatBefore = self::flatten($before);
                foreach (self::flatten($data) as $key => $value) {
                    if (array_key_exists($key, $flatBefore) && $flatBefore[$key] === $value) {
                        $redundant[$key] = $label;
                    }
                }
            }
            $before = self::merge($before, $data);
        }

        return $redundant;
    }

    /**
     * @param  string                $key    flat key, e.g. "ssh.fingerprints.git.example.com"
     * @param  array<string, mixed>  $known  flat keys of the defaults
     *
     * @return bool
     */
    private static function isKnownKey(string $key, array $known): bool
    {
        if (array_key_exists($key, $known)) {
            return true;
        }
        // a free map/list in the defaults (written as {} or [ ]) accepts any keys below it
        $parts = explode('.', $key);
        while (count($parts) > 1) {
            array_pop($parts);
            $prefix = implode('.', $parts);
            if (array_key_exists($prefix, $known) && is_array($known[$prefix])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moves renamed keys (RENAMED_KEYS) to their new name, the new name wins if both are set.
     *
     * @param  array   $data
     * @param  string  $label
     *
     * @return array
     */
    private function renameKeys(array $data, string $label): array
    {
        foreach (self::RENAMED_KEYS as $old => $new) {
            $value = data_get($data, $old, $missing = new \stdClass());
            if ($value === $missing) {
                continue;
            }
            $data = self::forget($data, $old);
            if (data_get($data, $new, $missing) === $missing) {
                $data = self::merge($data, self::nest($new, $value));
            }
            $this->warnings[] = sprintf("'%s' in %s is now called '%s', please rename it.", $old, $label, $new);
        }

        return $data;
    }

    /**
     * "a.b.c" + value -> ['a' => ['b' => ['c' => value]]]
     */
    private static function nest(string $key, mixed $value): array
    {
        foreach (array_reverse(explode('.', $key)) as $part) {
            $value = [$part => $value];
        }

        return $value;
    }

    /**
     * Removes the flat key "a.b.c" from a nested array.
     */
    private static function forget(array $data, string $key): array
    {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $ref = &$data;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                return $data;
            }
            $ref = &$ref[$part];
        }
        unset($ref[$last]);

        return $data;
    }

    /**
     * Removes null values ("~" = not set in the defaults), so the deployment sees a missing key.
     */
    private static function removeNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } elseif (is_array($value) && !array_is_list($value)) {
                $data[$key] = self::removeNulls($value);
            }
        }

        return $data;
    }

    /**
     * Files of the "extends" chain of $file (depth first, then the file itself).
     *
     * @param  string        $file
     * @param  bool          $isStageEntry  a stage file without "extends" inherits setup.yaml
     * @param  list<string>  $stack         files being resolved (cycle detection)
     *
     * @return list<string> real paths, a file inherited twice is used once (first position)
     * @throws Exception
     */
    private function resolve(string $file, bool $isStageEntry, array $stack): array
    {
        $real = (string) realpath($file);
        if (in_array($real, $stack, true)) {
            throw new Exception('Cycle in "extends": '.implode(' -> ', array_map($this->label(...), [...$stack, $real])));
        }

        $data = self::load($real);
        $extends = $data[self::EXTENDS_KEY] ?? ($isStageEntry ? ['setup.yaml'] : []);
        if (!is_array($extends)) {
            $extends = [$extends];
        }

        $files = [];
        foreach ($extends as $parent) {
            $path = str_starts_with((string) $parent, '/') ? (string) $parent : dirname($real).'/'.$parent;
            if (!is_file($path)) {
                throw new Exception(sprintf("'%s' extends '%s', but it does not exist.", $this->label($real), $parent));
            }
            foreach ($this->resolve($path, false, [...$stack, $real]) as $inherited) {
                if (!in_array($inherited, $files, true)) {
                    $files[] = $inherited;
                }
            }
        }
        if (!in_array($real, $files, true)) {
            $files[] = $real;
        }

        return $files;
    }

    /**
     * Short name of a layer: relative to config/ if inside, otherwise the full path.
     *
     * @param  string  $file
     *
     * @return string
     */
    private function label(string $file): string
    {
        $dir = (string) realpath($this->configDir()).'/';

        return str_starts_with($file, $dir) ? substr($file, strlen($dir)) : $file;
    }

    /**
     * Content of a layer without the "extends" key.
     *
     * @param  string  $file
     *
     * @return array
     * @throws Exception
     */
    public static function loadLayer(string $file): array
    {
        $data = self::load($file);
        unset($data[self::EXTENDS_KEY]);

        return $data;
    }

    /**
     * @param  string  $label  file name of a layer
     *
     * @return bool
     */
    public static function isSecretLayer(string $label): bool
    {
        return str_ends_with($label, 'secrets.yaml');
    }

    /**
     * The merged config of a stage.
     *
     * @param  string  $stage
     *
     * @return array
     * @throws Exception
     */
    public function merged(string $stage): array
    {
        $data = [];
        foreach ($this->layerData($stage) as $layer) {
            $data = self::merge($data, $layer);
        }

        return self::removeNulls($data);
    }

    /**
     * Writes the merged config of every stage into the config dir.
     *
     * @param  array<string, string>  $stages  stage => file name in $configDir, e.g. ['dev' => 'setup.yaml']
     * @param  string                 $configDir
     *
     * @return list<string> written files
     * @throws Exception
     */
    public function apply(array $stages, string $configDir): array
    {
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true)) {
            throw new Exception("Unable to create the config dir: $configDir");
        }

        $written = [];
        foreach ($stages as $stage => $target) {
            $header = sprintf("# Generated on every run from project/config (%s). Do not edit, change the project config.\n",
                implode(' + ', array_keys($this->layers($stage))));
            $targetFile = rtrim($configDir, '/').'/'.$target;
            if (file_put_contents($targetFile, $header.Yaml::dump($this->merged($stage), 10, 2)) === false) {
                throw new Exception("Unable to write: $targetFile");
            }
            $written[] = $targetFile;
        }

        return $written;
    }

    /**
     * Recursive merge: keys of $override win, maps are merged, lists are replaced as a whole
     * (a list of requires or hosts must not be mixed with the one of the base file).
     *
     * @param  array  $base
     * @param  array  $override
     *
     * @return array
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Flat view of a nested config: ['kernel.name' => 'mercy', 'requires' => [...]].
     * Lists stay one value (they are replaced as a whole, too).
     *
     * @param  array   $data
     * @param  string  $prefix
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "$prefix.$key";
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * @param  string  $file
     *
     * @return array
     * @throws Exception
     */
    public static function load(string $file): array
    {
        $data = Yaml::parseFile($file) ?? [];
        if (!is_array($data)) {
            throw new Exception("Not a yaml map: $file");
        }

        return $data;
    }
}
