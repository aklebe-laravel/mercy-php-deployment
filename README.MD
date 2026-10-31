# Mercy PHP Deployment

The deployment library behind the `./mercy` CLI of the [Mercy Scaffold](https://github.com/aklebe-laravel/mercy-scaffold)
and the [Mercy-Installer](https://github.com/aklebe-laravel/mercy-installer). Plain PHP and Symfony Console, no Laravel
application needed: it runs on the host before the app exists.

It does three jobs, each a list of named, resumable steps driven by the project config:

| Job               | Where     | Command                     |
|-------------------|-----------|-----------------------------|
| dev environment   | scaffold  | `./mercy install`, `update` |
| production image  | scaffold  | `./mercy build`             |
| production server | installer | `./mercy install`, `update` |

Users of Mercy normally only read the scaffold and installer READMEs. This README is the reference for the
config model, every command and step, and the behaviour behind them.

## Contents

- [Usage](#usage)
- [Commands and steps](#commands-and-steps)
- [Configuration](#configuration)
- [Preparing the machine](#preparing-the-machine)
- [Behaviour worth knowing](#behaviour-worth-knowing)
- [Tests](#tests)
- [License](#license)

## Usage

Requirements: PHP 8.5, Composer, Git, Docker. `ext-zip` only for `build_steps.create_bundle`.

The entry point is a small script without extension, like Laravel's `artisan` (`./mercy` or `php mercy`):

```php
#!/usr/bin/env php
<?php
use AklebeLaravel\MercyPhpDeployment\MercyApp;

require __DIR__.'/vendor/autoload.php';

// destination, config templates, generated configs
new MercyApp(__DIR__, __DIR__.'/resources/setup', __DIR__.'/storage/app/last-setup')
    ->withProject(__DIR__.'/project', ['dev' => 'setup.yaml', 'build' => 'setup-build.yaml'])
    ->withBuild(__DIR__.'/storage/docker-builds/prod-build')   // adds "build"
    ->runDev();                                                 // installer: ->runProd()
```

| Method                               | Meaning                                                                              |
|--------------------------------------|--------------------------------------------------------------------------------------|
| `withProject($dir, [stage => file])` | the project repository holds the configs; each stage is merged and written as `file` |
| `withBuild($dir)`                    | registers `build`; the image is built in `$dir`                                      |
| `runDev()`                           | dev commands (scaffold)                                                              |
| `runProd()`                          | production commands (installer)                                                      |
| `runDevBuildToProd()`                | deprecated (before 2.1: `build.php create`), alias `create`                          |

## Commands and steps

| Command        | Tool                | Purpose                                                                                                                               |
|----------------|---------------------|---------------------------------------------------------------------------------------------------------------------------------------|
| `status`       | all                 | default without a command: project, instance, config warnings, secrets, installed, containers, URL, next step (read only, no network) |
| `config`       | all                 | effective config of a stage with the origin of every value: `[filter] [--stage] [--all] [--redundant]`                                |
| `install`      | scaffold, installer | full installation                                                                                                                     |
| `update`       | scaffold, installer | refresh an existing installation                                                                                                      |
| `build`        | scaffold            | production image                                                                                                                      |
| `destroy`      | scaffold, installer | remove the docker project (database!) and its proxy hosts; `--images`, `--files`, `--force`                                           |
| `project:init` | scaffold            | create `project/` from `<templates>/project`                                                                                          |
| `host:setup`   | scaffold            | prepare git and ssh of this machine, see [Preparing the machine](#preparing-the-machine)                                              |
| `library:sync` | scaffold            | copy libraries from `git.library_paths` again and `composer update` them                                                              |

Global option `--instance=<name>`, remembered in `project/.instance`.

Every command that changes something is a list of steps. `--info` lists them; a step number or name continues from
there (all following steps run too):

```bash
./mercy install --info
./mercy install copy_ssh
```

Steps that prepare config and env (`check_dev_stage`, `setup_host`, `preparing_env`, `copy_ssh`, `setup_env`;
production `prepare_env`, `refresh_env`; build `init_host_setup`, `init_target_setup`) also run when continuing at a
later step, so a config fix takes effect.

| Command        | Steps                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
|----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| dev `install`  | check_dev_stage, setup_host, copy_root_files, preparing_env, copy_ssh, clone_dependencies, library_branch, composer_update, setup_env, build_containers, sail_up, generate_key, link_storage, _sync_npm, get_requires, update, migrate, health_check, summary                                                                                                                                                                                         |
| dev `update`   | check_dev_stage, setup_host, copy_root_files, copy_ssh, clone_dependencies, library_branch, composer_install, setup_env, build_containers (cached), sail_up, get_requires, update, sync_npm, migrate, health_check, summary                                                                                                                                                                                                                           |
| `build`        | init_host_setup, setup_host, registry_login, check_host_php, cleanup_build, clone_app, init_target_setup, check_platform_requirements, copy_root_files, prepare_env, get_base_requirements, library_branches, composer_install_1, get_mercy_requirements, composer_autoload, composer_install_2, npm_ci, build_frontend, build_mercy_assets, npm_run, write_build_lock, cleanup_after_build, build_image, save_image, create_bundle, push_to_registry |
| prod `install` | check_host_php, check_platform_requirements, registry_login, shell_completion, pull_docker_image, fetch_release_files, prepare_env, start_containers, generate_key, link_storage, sync_npm, start_migration, show_status                                                                                                                                                                                                                              |
| prod `update`  | check_host_php, check_platform_requirements, registry_login, shell_completion, pull_docker_image, fetch_release_files, migrate_layout, refresh_env, start_containers, link_storage, sync_npm, start_migration, show_status                                                                                                                                                                                                                            |

Output while the steps run:

- **Terminal:** one line per step with a spinner and the elapsed time; finished steps stay as
  `✔ [08/16] Build containers (51.2s)`. Warnings and errors appear above the running step.
- **Debug mode** (`kernel.debug: true`) **or no terminal** (CI, `| tee install.log`): a heading per step, the output of
  all commands below it.
- A failed step stops the run (exit code 1) and names the step to continue with. Docker pull and push progress is
  filtered from error output; the last 20 lines are shown.

## Configuration

### Layers

A stage config is merged from these layers (maps recursively, lists replaced as a whole):

1. `mercy:setup.yaml`: the [Mercy defaults](resources/defaults/setup.yaml) of this library. Every key the deployment
   knows, with its default and an explanation. `~` means not set.
2. the project files the stage inherits (`config/setup.yaml` and its `extends`, e.g. `networks/home.yaml`)
3. `mercy:<stage>.yaml`: stage defaults ([dev](resources/defaults/dev.yaml): `env: local`, `dev_mode: true`;
   [build](resources/defaults/build.yaml): `env: production`, `dev_mode: false`)
4. the project stage file (`config/<stage>.yaml`)
5. the instance (`config/instances/<name>.yaml`, usually `extends` its network)
6. `config/secrets.yaml`, `config/<stage>.secrets.yaml`, `config/local.yaml` (not versioned)

Empty values: an empty section (`kernel:` with all children commented out, or `{}`) adds nothing and never removes
what an earlier layer set, so sections can be commented out freely. `~` of a single value means "not set" (e.g. back
to no `kernel.image`), an empty list (`[ ]`) replaces a list (e.g. `url_rewrites: [ ]` for an instance without them).

Every yaml may inherit others with `extends: [a.yaml, networks/b.yaml]` (relative to the file, inherited first,
cycles are an error). A stage file without `extends` inherits `setup.yaml`. The project build stage extends the dev
stage (`extends: [setup.yaml, dev.yaml]`), so an image is never built with a production domain.

Project files therefore contain only differences, and a new library version brings new keys with their defaults
without touching any project. Before every command unknown keys (typos, old names) are reported, renamed keys (`ProjectConfig::RENAMED_KEYS`) are moved to their new name with a warning. The merged
result is written to the
generated config dir (`storage/app/last-setup/`).

### Files

| File                                     | Meaning                                                                                                  |
|------------------------------------------|----------------------------------------------------------------------------------------------------------|
| `project/config/*.yaml`                  | the project (versioned), see the layers above                                                            |
| `project/config/secrets.yaml`            | secrets of this machine, merged last, masked in every output (ignored: `secrets.yaml`, `*.secrets.yaml`) |
| `project/config/*-secrets.yaml`          | versionable secrets (e.g. a local dev tool), masked, inherited with `extends`; not for production        |
| `project/config/local.yaml`              | branches and libraries of this machine (not versioned)                                                   |
| `<templates>/setup-*.env`                | env templates, filled with values calculated from the config                                             |
| `<templates>/core.yaml`                  | Mercy core modules and default theme (scaffold only, not copied)                                         |
| `<generated>/setup*.yaml`, `setup-*.env` | merged config and env of this machine; fixed instance key, ports and keys live here                      |

Templates are copied to the generated dir only if missing; calculated values are written into the generated files on
every run.

### Instances and networks

One installation is one **instance**: `config/instances/<name>.yaml` with a few lines (domain, ip, ports, release),
extending its **network** (`config/networks/<name>.yaml`: git server, fingerprints, NPM, LDAP, DNS). Chosen with
`--instance=<name>` and remembered in `project/.instance`. If a project has instances, every command needs one; the
first line of every command names project, instance and stage.

### Selected keys

| Key                                               | Meaning                                                                                       |
|---------------------------------------------------|-----------------------------------------------------------------------------------------------|
| `kernel.name`, `kernel.image`                     | container prefix; image name (default `name`)                                                 |
| `kernel.version`                                  | build: release number (image tag); production: release to run; dev: from git                  |
| `kernel.domain`, `kernel.ip`                      | domain and host ip of the instance                                                            |
| `git.source`, `git.namespace`                     | where modules, themes and the scaffold are cloned from                                        |
| `git.scaffold`                                    | scaffold url for the build (default `<git.source>:<git.namespace>/mercy-scaffold.git`)        |
| `git.registry_source`, `git.registry_namespace`   | registry of the image: `<source>/<namespace>/<image>:<version>`                               |
| `git.url_rewrites`                                | git `insteadOf` rewrites of this machine and its containers                                   |
| `git.branch`, `git.branches`, `git.library_paths` | development branches and local libraries, see [Releases and branches](#releases-and-branches) |
| `requires`, `base_requires`                       | modules and themes of the project; Mercy base modules (released versions)                     |
| `env`                                             | free env vars (`NAME: value`), written 1:1 after all calculated values                        |
| `env_strict_literals`                             | env keys written literally (no `$` expansion), e.g. passwords and API keys                    |
| `provisioning.auto_port_start`                    | first port of the auto ports of this instance                                                 |
| `npm.*`                                           | Nginx Proxy Manager: `enabled`, API, `forward` (`network` / `host`), hosts                    |
| `ssh.key`, `ssh.known_hosts`, `ssh.fingerprints`  | deploy key; git servers; their host key fingerprints (github.com built in)                    |
| `build_steps.*`                                   | `branch`, `use_registry`, `create_bundle`, `optimize_composer`, `build_assets`                |

All keys with explanations: [resources/defaults/setup.yaml](resources/defaults/setup.yaml).

## Preparing the machine

`host:setup` (also the step `setup_host` of dev `install`, `update` and `build`) does what git and ssh need, from the
config, instead of only checking it. A fresh machine needs no manual git or ssh commands. It only changes what is missing.

1. **Rewrites:** `git.url_rewrites` → `git config --global url.<base>.insteadOf <url>`. The same url rewritten to
   another base is removed, so moving to another git server only needs the new config.
2. **Known hosts:** missing hosts of `ssh.known_hosts` are added to `~/.ssh/known_hosts`, but only with a host key
   matching `ssh.fingerprints` (github.com is built in). Without a fingerprint it asks in a terminal, otherwise it stops
   and prints the config line to add (compare with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server).
3. **Deploy key:** `ssh.key` is created if missing (ed25519, no passphrase). It is the only key the deployment needs:
   the containers use it, and so does every git and composer call of the deployment on the host (`GIT_SSH_COMMAND`).
4. **Access:** `ssh -T` with that key against every git server. If one rejects it, the public key is shown; in a
   terminal it waits and tests again.
5. **Tab completion (bash):** `~/.local/share/bash-completion/completions/mercy` plus one line in `~/.bash_completion`
   that loads it, so commands and instances complete in a new terminal (`./mercy install --instance=<tab>`). Only with
   `./mercy`, not with `php mercy`.

Who needs which key:

| For                                         | What                         | Added at                          | Done by                             |
|---------------------------------------------|------------------------------|-----------------------------------|-------------------------------------|
| git of the deployment (modules, composer)   | deploy key `ssh.key`         | SSH keys at GitHub / Gitea        | `host:setup` creates and tests it   |
| your own git commands (`git clone project`) | your key `~/.ssh/id_ed25519` | SSH keys at GitHub / Gitea        | you; on servers use it as `ssh.key` |
| trusting the git server                     | host key fingerprint         | `ssh.fingerprints` of the network | once per network                    |
| push (build) or pull (production) the image | registry token               | the registry                      | step `registry_login` asks for it   |

## Behaviour worth knowing

### Versions

A version belongs to an image, not to an environment. `getAppVersion()` is the only source of `APP_VERSION`, the image
tag and `build.lock.yaml`:

- **build:** `kernel.version` of the build stage is required and must be a valid Docker tag. The image gets the labels
  `org.opencontainers.image.version`, `.revision` (commit) and `.created`.
- **production:** `kernel.version` is the release to run (required). `update` recalculates the env (`refresh_env`), so
  `.env` and the started image always match.
- **dev:** `git describe --tags --always --dirty` of the project (`dev` without git). A `kernel.version` in the dev
  config overrides it.

### Releases and branches

What is checked out in the dev folder stays: a clone on a branch keeps it, the IDE switches branches. New clones start
with `git.branch` / `git.branches.<repo>` (if the remote has it), otherwise with their release (`version`, e.g. `^2.0` in
`base_requires`) or their `branch`.

Libraries (the git repositories in the scaffold's `composer.json`) keep their release unless `git.branches.<repo>`
(cloned to `storage/app/library-branch/`) or `git.library_paths.<repo>` (a local folder, copied there because the
container only sees the scaffold) says otherwise. `LibraryBranch` then writes a `composer.local.json` with a path
repository and a fixed version of the same major (`2.99.0`), so constraints like `^2.1` still match. `library:sync`
refreshes it after a change. These settings belong in `config/local.yaml`; `status` shows them and warns if
`composer.lock` points to a local library.

`build` uses releases (latest scaffold tag, versions of the requires, `composer.lock`) and ignores `local.yaml`.
`build_steps.branch` builds from a branch instead, with the image tag `<version>-<branch>`.

### Instance identity and ports

`APP_INSTANCE_KEY` and `COMPOSE_PROJECT_NAME` are calculated once and then kept, also if the config changes or the
generated env was deleted (fallback: the target `.env`). A different value only produces a warning. Docker volumes
belong to the project name; a new name would mean an empty database.

The auto ports (`provisioning.auto_port_env`) are kept the same way. To move them, change
`provisioning.auto_port_start` (the env remembers it as `MERCY_AUTO_PORT_START`); the next `install`/`update` calculates
new ports.

### Nginx Proxy Manager

With `npm.enabled` (or `npm.url` set and `enabled` missing), `install` and `update` create and update the proxy hosts of
`npm.hosts`, so changed ports, ips or `npm.forward` are applied. `forward: network` points to the container in the
docker network `proxy-gateway`, `forward: host` to `kernel.ip` and `APP_PORT` (default: `host` in production, otherwise
`network`). The tool remembers its domains (`storage/app/mercy-npm-hosts.json`), removes hosts of former domains and
removes all of them on `destroy`. External docker networks of the compose file are created before `up`.

### Dev environment

- **`update`** is a shorter install for an existing environment: `composer install` (the scaffold's `composer.lock`
  decides), cached container build, no `key:generate` or `storage:link`.
- **`check_dev_stage`** refuses a dev install with `kernel.dev_mode: false` (no `--no-dev` in a dev environment).
- **`APP_KEY` survives:** the generated env replaces the target env on every run; `APP_KEY` is carried over.
- **Git rewrites in containers:** `copy_ssh` writes the machine's `insteadOf` rewrites to `<ssh.docker_dir>/gitconfig`;
  the scaffold's `start-container` includes it.

### Build and production

- **`build.lock.yaml`:** version, remote, branch, commit and dirty state of the scaffold, every module, theme and the
  `dev-*` composer packages, written to the image root (`/var/www/html/build.lock.yaml`).
- **Registry login** happens before anything is built (build) or pulled (production); it asks for user and token in a
  terminal (`docker login --password-stdin`). In production it first checks the image without a login (`docker manifest inspect`): a public image needs no login, a missing tag stops with a hint before
  the pull.
- **Release files:** production `install` and `update` copy `compose.yaml` and the templates (`resources/setup/`) out of
  the pulled image (`docker create` + `docker cp`), so they always match the release.
- **`migrate_layout`** moves an installer of the old layout (`install/config/.env`) to
  `storage/app/last-setup/setup-production.env` once.

### Destroy

`destroy` takes the project name from `docker compose config`, asks you to type it, removes containers, networks,
volumes and the proxy hosts, and the images built locally (`--images`: also pulled images). `--files` deletes the folder,
but only if no git repository in it has uncommitted or unpushed changes. `--force` skips both checks.

## Tests

```bash
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
```

Feature tests copy `tests/Fixtures/app-blueprint/dev` to a temp folder per test; some use `git`.

## License

MIT, see [LICENSE.md](LICENSE.md).
