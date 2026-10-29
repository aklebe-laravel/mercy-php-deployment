<?php

namespace AklebeLaravel\MercyPhpDeployment;

use Symfony\Component\Filesystem\Filesystem;

class EnvConfig
{
    private string $filePath;

    /**
     * @var array<int, array{type: string, key?: string, value?: EnvValue, raw: string}>
     */
    private array $lines = [];


    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @param  array  $unparsableKeys
     */
    public function __construct(private readonly array $unparsableKeys = [])
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Loads and parses the .env file line by line while preserving structure.
     *
     * @param  string  $filePath
     *
     * @return $this
     */
    public function load(string $filePath): self
    {
        $this->filePath = $filePath;
        $this->lines = [];

        if (!$this->filesystem->exists($filePath)) {
            return $this;
        }

        $rawLines = file($filePath, FILE_IGNORE_NEW_LINES);

        foreach ($rawLines as $rawLine) {
            $trimmed = trim($rawLine);

            // Skip empty lines or pure comments, but keep them for preservation
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $this->lines[] = ['type' => 'comment_or_empty', 'raw' => $rawLine];
                continue;
            }

            if (str_contains($rawLine, '=')) {
                [$key, $valueStr] = explode('=', $rawLine, 2);
                $key = trim($key);

                [$quote, $valueStr, $comment] = $this->parseRawValue($valueStr);
                $isQuoted1 = $quote === "'";
                $isQuoted2 = $quote === '"';
                $isQuoted = $quote !== '';

                if ($isQuoted2) {
                    // Nur das Anführungszeichen-Escape rückgängig machen,
                    // \$ , \\ , \n usw. bleiben unverändert erhalten
                    $valueStr = str_replace('\\"', '"', $valueStr);
                }
                $isParsable = $this->isKeyParsable($key) || $isQuoted2;

                // Handle missing values (e.g. API_KEY=) as empty strings
                if ($valueStr === '') {
                    $phpValue = '';
                } else {
                    if ($isQuoted1) {
                        $isParsable = false;
                        $valueStr = stripcslashes($valueStr);
                        $phpValue = $valueStr; // Explicitly quoted values are strictly kept as strings
                    }
                    if ($isQuoted2) {
                        $isParsable = true;
                        $phpValue = $valueStr; // Explicitly quoted values are strictly kept as strings
                    }
                    if (!$isQuoted) {
                        // Convert unquoted literal values to exact PHP types
                        $lowerValue = strtolower($valueStr);
                        $phpValue = match ($lowerValue) {
                            'true' => true,
                            'false' => false,
                            'null' => null,
                            default => is_numeric($valueStr) ? (str_contains($valueStr, '.') ? (float) $valueStr : (int) $valueStr) : $valueStr
                        };
                    }
                }

                $this->lines[] = [
                    'type'    => 'key_value',
                    'key'     => $key,
                    'value'   => new EnvValue($phpValue, $isParsable),
                    'comment' => $comment,
                    'dirty'   => false,
                    'raw'     => $rawLine,
                ];
            } else {
                // Fallback for unexpected line formats
                $this->lines[] = ['type' => 'comment_or_empty', 'raw' => $rawLine];
            }
        }

        return $this;
    }

    /**
     * Sets or updates a value. Evaluates the type whitelist automatically if no flag is provided.
     *
     * @param  string     $key
     * @param  mixed      $value
     * @param  bool|null  $parsable
     *
     * @return $this
     */
    public function setValue(string $key, mixed $value, ?bool $parsable = null): self
    {
        if ($parsable !== null) {
            $resolvedParsable = $parsable;
        } elseif ($oldValue = $this->getValue($key)) {
            // use preview method
            $resolvedParsable = $oldValue->parsable ?? true;
        } else {
            $resolvedParsable = $this->isKeyParsable($key);
        }

        foreach ($this->lines as &$line) {
            if ($line['type'] === 'key_value' && $line['key'] === $key) {
                $line['value'] = new EnvValue($value, $resolvedParsable);
                $line['dirty'] = true;

                return $this;
            }
        }

        // If the key does not exist yet, append it to the end
        $this->lines[] = [
            'type'    => 'key_value',
            'key'     => $key,
            'value'   => new EnvValue($value, $resolvedParsable),
            'comment' => '',
            'dirty'   => true,
            'raw'     => '',
        ];

        return $this;
    }

    /**
     * Retrieves an EnvValue wrapper or a default fallback.
     *
     * @param  string  $key
     * @param  mixed   $default
     *
     * @return EnvValue|null
     */
    public function getValue(string $key, mixed $default = null): ?EnvValue
    {
        foreach ($this->lines as $line) {
            if ($line['type'] === 'key_value' && $line['key'] === $key) {
                return $line['value'];
            }
        }

        return $default;
    }

    /**
     * Commits all current data back to the file system while maintaining original layouts.
     *
     * @param  string|null  $filePath
     *
     * @return void
     */
    public function save(?string $filePath = null): void
    {
        $targetPath = $filePath ?? $this->filePath;
        $content = "";

        foreach ($this->lines as $line) {
            if ($line['type'] === 'key_value') {
                if (empty($line['dirty']) && $line['raw'] !== '') {
                    $content .= $line['raw']."\n";
                } else {
                    $content .= sprintf("%s=%s%s\n",
                        $line['key'],
                        $line['value']->render(),
                        $line['comment'] ?? '');
                }
            } elseif ($line['type'] === 'comment_or_empty') {
                // write back comment rows and empty lines
                $content .= $line['raw']."\n";
            }
        }

        $this->filesystem->dumpFile($targetPath, $content);
    }

    /**
     * Verifies if a given key matches direct or wildcard patterns in the whitelist.
     */
    private function isKeyParsable(string $key): bool
    {
        foreach ($this->unparsableKeys as $pattern) {
            if ($pattern === $key || fnmatch($pattern, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Trennt Wert und Inline-Kommentar.
     *
     * @return array{0: string, 1: string, 2: string} [quote, value, comment]
     */
    private function parseRawValue(string $raw): array
    {
        $raw = trim($raw);
        $quote = $raw[0] ?? '';

        if ($quote === '"' || $quote === "'") {
            $len = strlen($raw);
            for ($i = 1; $i < $len; $i++) {
                if ($raw[$i] === '\\') {
                    $i++; // escaptes Zeichen überspringen
                    continue;
                }
                if ($raw[$i] === $quote) {
                    $inner = substr($raw, 1, $i - 1);
                    $rest = substr($raw, $i + 1);

                    // Nach dem schließenden Quote darf nur noch ein Kommentar folgen
                    if (trim($rest) === '' || str_starts_with(ltrim($rest), '#')) {
                        return [$quote, $inner, rtrim($rest)];
                    }
                    break;
                }
            }
            // kein sauberes Quote-Ende gefunden -> als unquotierten Wert behandeln
        }

        // Unquotiert: Kommentar beginnt bei Whitespace + '#'
        if (preg_match('/^(.*?)(\s+#.*)$/', $raw, $m)) {
            return ['', $m[1], $m[2]];
        }

        return ['', $raw, ''];
    }
}
