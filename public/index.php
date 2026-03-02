<?php

use App\Kernel;

/**
 * Disable Symfony Runtime dotenv loading: bootstrap handles env precedence explicitly.
 *
 * @return array<string, mixed>
 */
$runtimeOptions = static function (): array {
    $options = $_SERVER['APP_RUNTIME_OPTIONS'] ?? $_ENV['APP_RUNTIME_OPTIONS'] ?? [];
    if (is_string($options) && $options !== '') {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
            $options = is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            $options = [];
        }
    }

    if (!is_array($options)) {
        $options = [];
    }
    $options['disable_dotenv'] = true;

    return $options;
};
$_SERVER['APP_RUNTIME_OPTIONS'] = $_ENV['APP_RUNTIME_OPTIONS'] = $runtimeOptions();

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    require_once dirname(__DIR__).'/config/bootstrap.php';

    $env = (string) ($_SERVER['APP_ENV'] ?? $context['APP_ENV']);
    $debug = (bool) ($_SERVER['APP_DEBUG'] ?? $context['APP_DEBUG']);

    return new Kernel($env, $debug);
};
