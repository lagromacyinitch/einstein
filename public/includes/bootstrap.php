<?php
/**
 * Shared entry point for every PHP file: defines the site root and loads config.php.
 *
 * The local XAMPP setup keeps its private config at the project root, while
 * the deployed layout keeps the environment-driven config beside this file.
 * Prefer the local root config when it exists, then fall back to the deployed
 * public/includes/config.php.
 */
if (!defined('EINSTEIN_ROOT')) {
    define('EINSTEIN_ROOT', dirname(__DIR__));
    $localConfigPath = dirname(EINSTEIN_ROOT) . '/config.php';
    $deployedConfigPath = __DIR__ . '/config.php';
    define('CONFIG_PATH', is_file($localConfigPath) ? $localConfigPath : $deployedConfigPath);
}
require_once CONFIG_PATH;
