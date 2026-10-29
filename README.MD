# Mercy PHP Deployment

Native PHP (no Laravel app needed) deployment library behind the Mercy scaffold:
install a dev environment, build the production image and install/update production.
Everything is driven by YAML configs (`setup.yaml`) and the step lists in `src/Commands`.

Used by:

| Project                                                              | Entry point                                       | Commands                     |
|----------------------------------------------------------------------|---------------------------------------------------|------------------------------|
| [mercy-scaffold](https://github.com/aklebe-laravel/mercy-scaffold)   | `mercy` (`MercyApp::runDev()` with `withBuild()`) | `install`, `update`, `build` |
| [mercy-installer](https://github.com/aklebe-laravel/mercy-installer) | `mercy` (`MercyApp::runProd()`)                   | `install`, `update`          |

The scripts have no extension, like Laravel's `artisan`: `./mercy install` or `php mercy install`. (Before 2.1: `mercy.php` and `build.php create`; `runDevBuildToProd()` still works, deprecated.)

```php
use AklebeLaravel\MercyPhpDeployment\MercyApp;

// destination, config templates, user configs
new MercyApp(__DIR__, __DIR__.'/resources/setup', __DIR__.'/storage/app/last-setup')
    ->withProject(__DIR__.'/project', ['dev' => 'setup.yaml', 'build' => 'setup-build.yaml'])
    ->runDev();
```

Requirements: PHP 8.5, Composer, Git, Docker. `ext-zip` only for `build_steps.create_bundle`.

## Steps

Every command is a list of named steps. `--info` lists them, a step number or name continues from there (all following steps run as well):

```
./mercy install --info
./mercy install copy_ssh
```

| Command                | Steps                                                                                                                                                                                                                                                                                                                                                                                               |
|------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| dev `project:init`     | creates `project/` from `resources/setup/project`                                                                                                                                                                                                                                                                                                                                                   |
| all `config`           | shows the effective project config of a stage                                                                                                                                                                                                                                                                                                                                                       |
| dev/build `host:setup` | prepares git and ssh of this machine (also step `setup_host` of install, update, build), see below                                                                                                                                                                                                                                                                                                  |
| dev/prod `destroy`     | removes the docker project (database!); `--files` also the folder, `--images` the pulled images                                                                                                                                                                                                                                                                                                     |
| dev `update`           | check_dev_stage, setup_host, copy_root_files, copy_ssh, clone_dependencies, composer_install, setup_env, build_containers (cached), sail_up, get_requires, update, migrate, health_check, summary                                                                                                                                                                                                   |
| dev `install`          | check_dev_stage, setup_host, copy_root_files, preparing_env, copy_ssh, clone_dependencies, composer_update, setup_env, build_containers, sail_up, generate_key, link_storage, get_requires, update, migrate, health_check, summary                                                                                                                                                                  |
| build `create`         | init_host_setup, setup_host, check_host_php, cleanup_build, clone_app, init_target_setup, check_platform_requirements, copy_root_files, prepare_env, get_base_requirements, composer_install_1, get_mercy_requirements, composer_autoload, composer_install_2, npm_ci, build_mercy_assets, npm_run, write_build_lock, cleanup_after_build, build_image, save_image, create_bundle, push_to_registry |
| prod `install`         | check_host_php, check_platform_requirements, pull_docker_image, fetch_release_files, prepare_env, start_containers, generate_key, link_storage, sync_npm, start_migration, show_status                                                                                                                                                                                                              |
| prod `update`          | check_host_php, check_platform_requirements, pull_docker_image, fetch_release_files, migrate_layout, refresh_env, start_containers, link_storage, start_migration, show_status                                                                                                                                                                                                                      |

Output while the steps run:

- **Terminal:** one line per step. The running step shows a spinner and the elapsed time,
  finished steps stay as `✔ [08/16] Build containers (51.2s)`. Warnings and errors appear above the running step.
- **Debug mode** (`setup.kernel.debug: true`) **or no terminal** (CI, `| tee install.log`): a heading
  `==> [08/16] Build containers` per step, the output of all commands flows below it.
- A failed step stops the run (exit code 1) and names the step to continue with.

## Configs

| File                  | Meaning                                                                                            |
|-----------------------|----------------------------------------------------------------------------------------------------|
| `setup.yaml`          | the project: kernel (name, version, domain, ...), git, requires, provisioning, npm, ssh, ldap, env |
| `setup-*.env`, `.env` | env templates, filled with values calculated from `setup.yaml`                                     |
| `core.yaml`           | Mercy core modules and default theme (scaffold only, not copied to the user configs)               |

Templates are copied to the user config dir only if missing. The user configs are never overwritten
by templates; calculated values are written into them on every run.

Selected `setup.yaml` keys:

| Key                            | Meaning                                                                                              |
|--------------------------------|------------------------------------------------------------------------------------------------------|
| `kernel.image`                 | optional: image name (default `kernel.name`)                                                         |
| `git.source`, `git.namespace`  | where modules, themes and the scaffold are cloned from                                               |
| `git.scaffold`                 | optional: scaffold git url for the build (default `<git.source>:<git.namespace>/mercy-scaffold.git`) |
| `git.url_rewrites`             | optional: git `insteadOf` rewrites this machine gets (set by `setup_host`)                           |
| `ssh.known_hosts`              | git servers; `setup_host` adds missing ones to `~/.ssh/known_hosts` (only with a known fingerprint)  |
| `ssh.fingerprints`             | `host: 'SHA256:...'` (or a list) of the git servers; github.com is built in                          |
| `ssh.key`                      | deploy key; `setup_host` creates it if missing and tests it against every git server                 |
| `env`                          | optional: free env vars (`NAME: value`), written 1:1 into the env after all calculated values        |
| `env_strict_literals`          | env keys whose values are written literally (no `$` expansion), e.g. passwords and API keys          |
| `provisioning.auto_port_start` | first port for the auto ports (several instances on one machine)                                     |

## Behaviour worth knowing

- **Mercy defaults:** `resources/defaults/setup.yaml` (and `<stage>.yaml`) of this library are the lowest layers of every
  project config. They list every key the deployment knows, with its default and an explanation, so project files
  contain only what differs and a new version brings new keys without touching the projects. `~` means not set.
  Order: `mercy:setup.yaml` → the project files the stage inherits (`setup.yaml`, `networks/...`) → `mercy:<stage>.yaml`
  → the project `<stage>.yaml` → the instance → secrets. The stage defaults (e.g. `dev_mode: true` for dev,
  `env: production` for the build) win over the product base, the stage file wins over them.
- **Secrets:** `secrets.yaml` and `<stage>.secrets.yaml` are merged last, exist only on the machine (not versioned)
  and are masked in `config`. Values that may be versioned (e.g. the password of a local dev tool) go into a file
  whose name ends with `-secrets.yaml` (e.g. `shared-secrets.yaml`), inherited where they belong (`extends: [ ../shared-secrets.yaml ]` in a network or instance): it is masked like the other secrets,
  but not
  ignored by git (the ignore patterns are `secrets.yaml` and `*.secrets.yaml`). Not for production secrets.
- **Keys and logins of a machine** (who needs what):

  | For                                         | What                                       | Where to add it                         | Done by                              |
        |---------------------------------------------|--------------------------------------------|-----------------------------------------|--------------------------------------|
  | git of the deployment (modules, composer)   | deploy key `ssh.key`                       | SSH keys at Gitea/GitHub                | `host:setup` creates and tests it    |
  | your own git commands (`git clone project`) | your key `~/.ssh/id_ed25519`               | SSH keys at Gitea/GitHub                | you; on servers use it as `ssh.key`  |
  | the git server is the right one             | host key fingerprint                       | `ssh.fingerprints` of the network       | once per network                     |
  | push (build) / pull (production) the image  | registry token (`docker login`)            | the registry (GitHub, Gitea)            | step `registry_login` asks for it    |

- **Instances:** one installation is one instance, `config/instances/<name>.yaml` of the project (a few lines:
  domain, ip, ports, release; `extends` its network file). Chosen with the global option `--instance=<name>`
  and remembered in `project/.instance`. If a project has instances, every command needs one; the first line of
  every command names project, instance and stage.
  Before every command unknown keys of the project (typos, old names) are reported; renamed keys (`ProjectConfig::RENAMED_KEYS`) are moved to the new name with a warning. `config --redundant` lists
  project values
  that equal a default.
- **Project repository:** `MercyApp::withProject($dir, ['dev' => 'setup.yaml'])` makes `<dir>` (e.g. `project/`) the
  one place of the configs: before every deployment command `ProjectConfig` merges the Mercy defaults → `config/setup.yaml` →
  `config/<stage>.yaml` → `config/secrets.yaml` → `config/<stage>.secrets.yaml` (maps recursively, lists replaced).
  Every yaml may inherit others with `extends: [a.yaml, networks/b.yaml]` (relative to the file, inherited first,
  cycles are an error); a stage file without `extends` inherits `setup.yaml`
  and writes the result to the config dir. `project:init` creates a project from `<templates>/project`,
  `config [filter] [--stage] [--all] [--redundant]` shows the effective values and their origin (secrets masked,
  defaults only with `--all`).
- **Versions:** a version belongs to an image, not to an environment. `getAppVersion()` is the only source for
  `APP_VERSION`, the image tag and `build.lock.yaml`:
    - build: `kernel.version` of `setup-build.yaml` is the release number (required, a valid Docker tag).
      The image also gets the labels `org.opencontainers.image.version`, `.revision` (commit) and `.created`.
    - production: `kernel.version` of the installer config is the release to run (required).
      `update` recalculates the env values (`refresh_env`), so `.env` and the started image always match.
    - dev: no fixed number, `git describe --tags --always --dirty` of the project (e.g. `v2.0.1-rc1-3-g1a2b3c4-dirty`),
      `dev` without git. A `kernel.version` in the dev `setup.yaml` overrides it.
- **Dev `update`:** a shorter install for an existing environment (after `git pull` or changes in `setup.yaml`).
  It runs `composer install` (the scaffold's `composer.lock` decides) and does not repeat `key:generate`, `storage:link` or the NPM sync.
- **Instance identity:** `APP_INSTANCE_KEY` and `COMPOSE_PROJECT_NAME` are calculated once and then kept,
  also if `setup.yaml` changes or the config env was deleted (fallback: the target `.env`). A different value
  only produces a warning (not for `APP_INSTANCE_KEY`, it is only a seed and does not depend on the version). Docker volumes belong to the project name, a new name would mean an empty database.
  The auto ports (`provisioning.auto_port_env`) are kept the same way, silently: on a second run the own running
  containers occupy them. To move them, change `provisioning.auto_port_start` (the env remembers the start in
  `MERCY_AUTO_PORT_START`), then `update`/`install` calculates new ports.
- **Preparing the machine (`host:setup`, step `setup_host`):** everything git and ssh need comes from the config
  and is done, not only checked, so a fresh machine needs no manual git/ssh commands:
    1. `git.url_rewrites` → `git config --global url.<base>.insteadOf <url>`; the same url rewritten to another
       base is removed (e.g. moving from one Gitea org to another only needs the new config).
    2. `ssh.known_hosts` → missing hosts are added to `~/.ssh/known_hosts`, but only with a host key matching
       `ssh.fingerprints` (github.com is built in, see GitHub's published fingerprints). No fingerprint: it asks in a
       terminal, otherwise it stops and prints the config line (compare it with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server).
    3. `ssh.key` → created if missing (ed25519, no passphrase). It is the only key the deployment needs:
       the containers use it, and every git/composer call of the deployment on the host too (`GIT_SSH_COMMAND`).
    4. Access: `ssh -T` with that key against every git server (the bases of the url rewrites, otherwise `git.source`).
       If one does not accept it, the public key is shown to add there; in a terminal it waits and tests again.
       Put `ssh.fingerprints` and `git.url_rewrites` into the network config (e.g. `networks/home.yaml`), then every
       instance in that network gets them.
    5. Tab completion (bash): writes `~/.local/share/bash-completion/completions/mercy`, so commands and
       instances complete in a new terminal: `./mercy install --instance=<tab>`. Only with `./mercy`
       (shebang, executable); with `php mercy` bash completes `php`, like with `php artisan`.
- **Git url rewrites in containers:** `copy_ssh` writes the machine's `insteadOf` rewrites to
  `<ssh.docker_dir>/gitconfig`; the scaffold's `start-container` includes it for the `sail` user.
- **APP_KEY survives:** the config env replaces the target env on every run, `APP_KEY` (written by `key:generate`
  into the target only) is carried over.
- **build.lock.yaml:** the build writes version, remote, branch, commit and dirty state of the scaffold, every module and
  theme, and the `dev-*` composer packages into the image root (`/var/www/html/build.lock.yaml`).
- **Release files:** prod `install`/`update` copy `compose.yaml` and `resources/setup/` (templates)
  out of the pulled image (`docker create` + `docker cp`), so they always match the image version.

## Tests

```
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
```

Feature tests copy `tests/Fixtures/app-blueprint/dev` to a temp folder per test; some use `git`.

## License

MIT, see [LICENSE.md](LICENSE.md).
