<?php

namespace AklebeLaravel\MercyPhpDeployment;

class EnvValue
{
    /**
     * @param  mixed  $value     The native PHP value (string, int, float, bool, or null)
     * @param  bool   $parsable  If true, allows variable expansion ($VAR) in double quotes
     */
    public function __construct(
        public mixed $value,
        public bool $parsable = true
    ) {
    }

    /**
     * Generates the raw string representation of the inner value.
     */
    public function __toString(): string
    {
        if (is_bool($this->value)) {
            return $this->value ? 'true' : 'false';
        }
        if ($this->value === null) {
            return '';
        }

        return (string) $this->value;
    }

    /**
     * Formats the value correctly for the .env file based on its PHP type.
     */
    public function render(): string
    {
        // Handle specific non-string primitive types first
        if (is_bool($this->value)) {
            return $this->value ? 'true' : 'false';
        }

        if ($this->value === null) {
            return 'null'; // Industry standard for framework-agnostic null values
        }

        if (is_int($this->value) || is_float($this->value)) {
            return (string) $this->value;
        }

        $stringValue = (string) $this->value;

        // Case 1: Dynamic values allowing variable expansion ($VAR)
        if ($this->parsable) {
            if (preg_match('/[\s#\$]/', $stringValue)) {
                return '"'.addcslashes($stringValue, '"').'"';
            }

            return $stringValue;
        }

        // Case 2: Static values / Passwords (Disables variable expansion via single quotes)
        if (preg_match('/[\$\s#\'"]/', $stringValue)) {
            return "'".str_replace("'", "\\'", $stringValue)."'";
        }

        return $stringValue;
    }
}
