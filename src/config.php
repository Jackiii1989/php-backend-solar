<?php

/**
 * Configuration loader.
 *
 * Lookup order: real environment variable -> .env file -> error.
 */

# variables are strict declared
declare(strict_types=1);

/**
 * Parse a .env file into an associative array.
 * Minimal on purpose: KEY=VALUE lines, "#" starts a comment,
 * optional quotes around the value.
 */
function load_env_file(string $path): array{

	// check if the file can be read
	if (!is_readable($path)){
		return [];
	}
	
	$values = [];
	# file reads the file at $path line by line where it ignores new lines and skips empty lines
	foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
		$line = trim($line); // remove empty spaces in the front of file
		
		#skip comments and lines without "="
		if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
			continue;
        }
		
		[$key, $value] = explode('=', $line, 2); # explodes splits on "=" in variable $line and split maximal 2 parts. 
		$key = trim($key);
        $value = trim($value);
		
		// Strip surrounding quotes, if any.
        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]
        ) {
            $value = substr($value, 1, -1);
        }
		
		$values[$key] = $value;
	}
	return $values;
	
}

/**
 * Return one config value, or stop the program if a required key is missing.
 */
function config(string $key, ?string $default = null): string
{
    // "static" = parse the .env file only once, then remember it
    // across every config() call in this request.
	// preserves the value only during the current PHP execution: new api requst, refresh browser, PHP starts a new,
	// then it loads the file again
    static $envFile = null;
	
	# is $envFile exactly null
	if ($envFile === null) {
		$envFile = load_env_file(__DIR__ . '/../.env');
    }
	
	# get the env file if exist
	$value = getenv($key);

    if ($value !== false && $value !== '') {
		# if yes, return the value
        return $value;
    }
	// if not, get it from the local .env
    if (isset($envFile[$key]) && $envFile[$key] !== '') {
        return $envFile[$key];
    }
	// otherwise use the default value, if it is not null
    if ($default !== null) {
        return $default;
    }

	// last branch fail. 
    // Fail fast, but never echo secrets or key lists to the browser.
    http_response_code(500);
    error_log("Missing required config key: {$key}");
    exit('Server misconfigured.');
}


/**
 * Return a configuration value as a positive integer.
 *
 * Environment variables and .env values arrive as strings, even when they
 * contain digits. This helper validates the complete value before converting
 * it to an integer.
 *
 * Examples:
 *   "60"  -> 60
 *   "1"   -> 1
 *   "0"   -> configuration error
 *   "-5"  -> configuration error
 *   "abc" -> configuration error
 */
function config_positive_int(string $key, int $default): int
{
    /*
     * The default is written directly in application code. An invalid default
     * therefore indicates a programming error, not a deployment error.
     */
    if ($default < 1) {
        throw new InvalidArgumentException(
            'The default configuration value must be positive.'
        );
    }

    /*
     * config() applies the existing lookup order:
     *
     *     process environment -> .env file -> supplied default
     *
     * The integer default is converted to a string because config() returns
     * configuration values as strings.
     */
    $rawValue = config($key, (string) $default);

    /*
     * FILTER_VALIDATE_INT validates the complete string and returns the
     * converted integer. min_range rejects zero and negative values.
     *
     * A direct cast such as (int) "abc" would silently produce 0, which could
     * disable or break the limiter without clearly identifying the problem.
     */
    $value = filter_var(
        $rawValue,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($value === false) {
        /*
         * Log only the configuration key, never its value. This avoids
         * accidentally exposing a sensitive value if the helper is reused.
         */
        error_log("Configuration key must be a positive integer: {$key}");

        /*
         * Match the existing config() failure behavior: report a generic
         * server configuration problem without revealing internal details.
         */
        http_response_code(500);
        exit('Server misconfigured.');
    }

    return $value;
}

?>
