<?php
/**
 * Shared entry point for every PHP file: defines the site root and loads config.php.
 *
 * config.php is gitignored and lives only on each machine/server. Its home is
 * includes/config.php; the site-root location is still accepted so a server
 * that has not moved its copy yet keeps working.
 */
if (!defined('EINSTEIN_ROOT')) {
    define('EINSTEIN_ROOT', dirname(__DIR__));
    define('CONFIG_PATH', is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : EINSTEIN_ROOT . '/config.php');
}
require_once CONFIG_PATH;
