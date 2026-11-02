<?php
declare(strict_types = 1);

namespace AklebeLaravel\MercyPhpDeployment;

use Closure;
use InvalidArgumentException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * "kernel.version: auto": the image versions come from the tags of the image in the docker registry.
 *
 * - build: the next release, see {@see self::next()}; "./mercy build --tag=3|2.7|2.7.0" jumps, see {@see self::forTag()}
 * - production: the newest release, see {@see self::newest()}
 *
 * Releases are tags like "2.0.5" (or "v2.0.5"). Pre-releases and test images ("2.0.6-rc1", "2.0.6-feature-x")
 * are never installed by "auto", but a build continues after them ("2.0.6-rc1" => "2.0.6").
 *
 * The tags are read with the registry API ("GET /v2/<namespace>/<image>/tags/list"), with the login of
 * "docker login" (~/.docker/config.json or its credential store), so no extra tool is needed.
 */
final class ImageVersions
{
    /**
     * The value of "kernel.version" that switches this on.
     */
    public const string AUTO = 'auto';

    /**
     * First release if the registry has no tag of the image yet.
     */
    public const string FIRST = '1.0.0';

    private const string PATTERN = '/^(v?)(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z][0-9A-Za-z._-]*))?$/';

    private const string TAG_OPTION = '/^(v?)(\d+)(?:\.(\d+)(?:\.(\d+))?)?$/';

    /**
     * Reason of the last failed {@see self::tags()}.
     */
    public string $lastError = '';

    /**
     * @var Closure(string, list<string>): array{0: int, 1: array<string, string>, 2: string}
     */
    private Closure $http;

    /**
     * @var Closure(string): ?array{0: string, 1: string}
     */
    private Closure $credentials;

    /**
     * @param  (callable(string, list<string>): array{0: int, 1: array<string, string>, 2: string})|null  $http
     *         GET url with headers => status, lower-cased response headers, body (tests replace it)
     * @param  (callable(string): ?array{0: string, 1: string})|null                                      $credentials
     *         registry => user and token of "docker login", null if not logged in (tests replace it)
     */
    public function __construct(?callable $http = null, ?callable $credentials = null)
    {
        $this->http = $http !== null ? $http(...) : self::httpGet(...);
        $this->credentials = $credentials !== null ? $credentials(...) : self::dockerCredentials(...);
    }

    /**
     * @param  string  $value  the configured "kernel.version"
     *
     * @return bool
     */
    public static function isAuto(string $value): bool
    {
        return strtolower(trim($value)) === self::AUTO;
    }

    /**
     * Newest release, null if there is none.
     *
     * @param  list<string>  $tags
     *
     * @return string|null
     */
    public static function newest(array $tags): ?string
    {
        $best = null;
        foreach ($tags as $tag) {
            $v = self::parse($tag);
            if ($v !== null && $v['pre'] === '' && ($best === null || self::compareCore($v, $best) > 0)) {
                $best = $v;
            }
        }

        return $best['tag'] ?? null;
    }

    /**
     * Next release after all tags: the patch after the newest release, or the release of the newest
     * pre-release / test image ("2.0.6-rc1" => "2.0.6"). {@see self::FIRST} if there is no version tag.
     *
     * @param  list<string>  $tags
     *
     * @return string
     */
    public static function next(array $tags): string
    {
        $best = null;
        $released = false;
        foreach ($tags as $tag) {
            $v = self::parse($tag);
            if ($v === null) {
                continue;
            }
            $cmp = $best === null ? 1 : self::compareCore($v, $best);
            if ($cmp > 0) {
                $best = $v;
                $released = $v['pre'] === '';
            } elseif ($cmp === 0 && $v['pre'] === '') {
                $released = true;
            }
        }
        if ($best === null) {
            return self::FIRST;
        }

        return sprintf('%s%d.%d.%d', $best['prefix'], $best['major'], $best['minor'], $best['patch'] + ($released ? 1 : 0));
    }

    /**
     * Valid value of "./mercy build --tag": "3", "2.7" or "2.7.0" (optionally with "v").
     *
     * @param  string  $tag
     *
     * @return bool
     */
    public static function isTagOption(string $tag): bool
    {
        return (bool) preg_match(self::TAG_OPTION, $tag);
    }

    /**
     * Version of "./mercy build --tag":
     * - full ("2.7.0"): exactly this one; an existing release of it is never built again
     * - short ("3", "2.7"): the next free number of this series ("3" => "3.0.0", "2.7" with 2.7.1 => "2.7.2"),
     *   also for an older series (hotfix)
     *
     * @param  list<string>  $tags  the tags in the registry
     * @param  string        $tag   the option value
     *
     * @return string
     * @throws InvalidArgumentException invalid value or the release exists
     */
    public static function forTag(array $tags, string $tag): string
    {
        if (!preg_match(self::TAG_OPTION, $tag, $m)) {
            throw new InvalidArgumentException("Invalid --tag '$tag', use e.g. 3, 2.7 or 2.7.0.");
        }
        [$prefix, $major, $minor, $patch] = [$m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null, isset($m[4]) ? (int) $m[4] : null];

        $series = [];
        foreach ($tags as $existing) {
            $v = self::parse($existing);
            if ($v === null || $v['major'] !== $major || ($minor !== null && $v['minor'] !== $minor)) {
                continue;
            }
            if ($patch !== null && $v['minor'] === $minor && $v['patch'] === $patch && $v['pre'] === '') {
                throw new InvalidArgumentException("The release '$existing' exists already in the registry and is never built again; --tag=$major.$minor builds the next free number of $major.$minor.");
            }
            $series[] = $existing;
        }
        if ($patch !== null) {
            return $tag;
        }
        if ($series === []) {
            return sprintf('%s%d.%d.0', $prefix, $major, $minor ?? 0);
        }

        return self::next($series);
    }

    /**
     * All tags of an image, [] if the image does not exist yet, null on an error (see {@see self::$lastError}).
     *
     * @param  string  $registry    e.g. ghcr.io
     * @param  string  $repository  e.g. my-org/my-product-web
     *
     * @return list<string>|null
     */
    public function tags(string $registry, string $repository): ?array
    {
        $this->lastError = '';
        $registry = preg_replace('#^https?://#', '', rtrim($registry, '/'));
        $url = "https://$registry/v2/$repository/tags/list?n=1000";
        $authorization = null;
        $tags = [];

        // the registry answers in pages, the next one is in the "Link" header
        for ($page = 0; $page < 100 && $url !== null; $page++) {
            [$status, $headers, $body] = ($this->http)($url, $authorization !== null ? ["Authorization: $authorization"] : []);
            if ($status === 401 && $authorization === null) {
                $authorization = $this->authorize($registry, $headers['www-authenticate'] ?? '');
                if ($authorization === null) {
                    return null;
                }
                [$status, $headers, $body] = ($this->http)($url, ["Authorization: $authorization"]);
            }
            if ($status === 404) {
                // the image was never pushed
                return $tags;
            }
            if ($status !== 200) {
                $this->lastError = $status === 0 ? "Registry '$registry' not reachable." : "Registry '$registry' answered $status for '$repository'".($status === 401 || $status === 403 ? ' (docker login missing or without read access)' : '').'.';

                return null;
            }
            $data = json_decode($body, true);
            foreach ((array) ($data['tags'] ?? []) as $tag) {
                $tags[] = (string) $tag;
            }
            $url = self::nextPage($registry, $headers['link'] ?? '');
        }

        return $tags;
    }

    /**
     * Value of the "Authorization" header after a 401, null if the registry refused.
     *
     * @param  string  $registry
     * @param  string  $challenge  the "WWW-Authenticate" header
     *
     * @return string|null
     */
    private function authorize(string $registry, string $challenge): ?string
    {
        $credentials = ($this->credentials)($registry);
        $basic = $credentials !== null ? 'Basic '.base64_encode($credentials[0].':'.$credentials[1]) : null;

        if (stripos($challenge, 'Basic') === 0) {
            if ($basic === null) {
                $this->lastError = "Registry '$registry' needs a login: docker login $registry";
            }

            return $basic;
        }

        // Bearer realm="https://ghcr.io/token",service="ghcr.io",scope="repository:org/image:pull"
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER);
        $params = array_column($matches, 2, 1);
        if (stripos($challenge, 'Bearer') !== 0 || empty($params['realm'])) {
            $this->lastError = "Registry '$registry' sent an unknown login challenge: $challenge";

            return null;
        }
        $realm = $params['realm'];
        unset($params['realm']);
        $tokenUrl = $realm.(str_contains($realm, '?') ? '&' : '?').http_build_query($params);

        // without credentials an anonymous token, enough for a public image
        [$status, , $body] = ($this->http)($tokenUrl, $basic !== null ? ["Authorization: $basic"] : []);
        $token = json_decode($body, true);
        $token = $token['token'] ?? $token['access_token'] ?? '';
        if ($status !== 200 || $token === '') {
            $this->lastError = "Registry '$registry' refused the login ($status)".($basic === null ? ", run: docker login $registry" : ', check the token of "docker login"').'.';

            return null;
        }

        return 'Bearer '.$token;
    }

    /**
     * @param  string  $registry
     * @param  string  $link  e.g. </v2/org/image/tags/list?last=2.0.5&n=1000>; rel="next"
     *
     * @return string|null
     */
    private static function nextPage(string $registry, string $link): ?string
    {
        if (!preg_match('/<([^>]+)>\s*;\s*rel="?next"?/i', $link, $m)) {
            return null;
        }

        return str_starts_with($m[1], 'http') ? $m[1] : "https://$registry".$m[1];
    }

    /**
     * @param  string  $tag
     *
     * @return array{tag: string, prefix: string, major: int, minor: int, patch: int, pre: string}|null
     */
    private static function parse(string $tag): ?array
    {
        if (!preg_match(self::PATTERN, $tag, $m)) {
            return null;
        }

        return ['tag' => $tag, 'prefix' => $m[1], 'major' => (int) $m[2], 'minor' => (int) $m[3], 'patch' => (int) $m[4], 'pre' => $m[5] ?? ''];
    }

    /**
     * @param  array{major: int, minor: int, patch: int}  $a
     * @param  array{major: int, minor: int, patch: int}  $b
     *
     * @return int
     */
    private static function compareCore(array $a, array $b): int
    {
        return [$a['major'], $a['minor'], $a['patch']] <=> [$b['major'], $b['minor'], $b['patch']];
    }

    /**
     * @param  string        $url
     * @param  list<string>  $headers
     *
     * @return array{0: int, 1: array<string, string>, 2: string} status 0: not reachable
     */
    private static function httpGet(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", [...$headers, 'Accept: application/json', 'User-Agent: mercy-php-deployment']),
                'ignore_errors' => true,
                'timeout'       => 20,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $raw = http_get_last_response_headers() ?? [];
        if ($body === false && $raw === []) {
            return [0, [], ''];
        }

        $status = 0;
        $result = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                // after a redirect only the headers of the last response count
                $status = (int) $m[1];
                $result = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $result[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, $result, (string) $body];
    }

    /**
     * User and token of "docker login" for a registry: from a credential helper ("credHelpers", "credsStore")
     * or from "auths" of the docker client config (~/.docker/config.json or $DOCKER_CONFIG).
     *
     * @param  string  $registry
     *
     * @return array{0: string, 1: string}|null
     */
    public static function dockerCredentials(string $registry): ?array
    {
        $dir = getenv('DOCKER_CONFIG') ?: Process::expandTilde('~/.docker');
        $file = rtrim($dir, '/').'/config.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($config)) {
            return null;
        }

        $helper = $config['credHelpers'][$registry] ?? $config['credsStore'] ?? '';
        if ($helper !== '') {
            $process = new SymfonyProcess(['docker-credential-'.$helper, 'get']);
            $process->setInput($registry);
            $process->setTimeout(15);
            $process->run();
            $data = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
            if (is_array($data) && ($data['Secret'] ?? '') !== '') {
                return [(string) ($data['Username'] ?? ''), (string) $data['Secret']];
            }
        }

        foreach ((array) ($config['auths'] ?? []) as $key => $entry) {
            if (preg_replace('#^https?://#', '', rtrim((string) $key, '/')) !== $registry) {
                continue;
            }
            $auth = base64_decode((string) ($entry['auth'] ?? ''), true);
            if ($auth !== false && str_contains($auth, ':')) {
                [$user, $secret] = explode(':', $auth, 2);

                return [$user, $secret];
            }
        }

        return null;
    }
}
