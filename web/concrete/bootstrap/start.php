<?php defined('C5_EXECUTE') or die("Access Denied.");

/**
 * ----------------------------------------------------------------------------
 * Ensure we're not accessing this file directly.
 * ----------------------------------------------------------------------------
 */
if (basename($_SERVER['PHP_SELF']) == DISPATCHER_FILENAME_CORE) {
    die("Access Denied.");
}

/**
 * ----------------------------------------------------------------------------
 * Import relevant classes.
 * ----------------------------------------------------------------------------
 */
use Concrete\Core\Application\Application;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Config\DatabaseLoader;
use Concrete\Core\Config\DatabaseSaver;
use Concrete\Core\Config\FileLoader;
use Concrete\Core\Config\FileSaver;
use Concrete\Core\Config\Repository\Repository as ConfigRepository;
use Concrete\Core\File\Type\TypeList;
use Concrete\Core\Foundation\ClassAliasList;
use Concrete\Core\Foundation\Service\ProviderList;
use Concrete\Core\Support\Facade\Facade;
use Illuminate\Filesystem\Filesystem;
use Patchwork\Utf8\Bootup as PatchworkUTF8;

/**
 * ----------------------------------------------------------------------------
 * Handle text encoding.
 * ----------------------------------------------------------------------------
 */
PatchworkUTF8::initAll();

/**
 * ----------------------------------------------------------------------------
 * Instantiate concrete5.
 * ----------------------------------------------------------------------------
 */
/** @var Application $cms */
$cms = require DIR_APPLICATION . '/bootstrap/start.php';
$cms->instance('app', $cms);

// Bind fully application qualified class names
$cms->instance('Concrete\Core\Application\Application', $cms);
$cms->instance('Illuminate\Container\Container', $cms);

/**
 * ----------------------------------------------------------------------------
 * Bind the IOC container to our facades
 * Completely indebted to Taylor Otwell & Laravel for this.
 * ----------------------------------------------------------------------------
 */
Facade::setFacadeApplication($cms);

/**
 * ----------------------------------------------------------------------------
 * Load path detection for relative assets, URL and path to home.
 * ----------------------------------------------------------------------------
 */
require DIR_BASE_CORE . '/bootstrap/paths.php';


/**
 * ----------------------------------------------------------------------------
 * Add install environment detection
 * ----------------------------------------------------------------------------
 */
$db_config = array();
if (file_exists(DIR_APPLICATION . '/config/database.php')) {
    $db_config = include DIR_APPLICATION . '/config/database.php';
}
$environment = $cms->environment();
$cms->detectEnvironment(function() use ($db_config, $environment, $cms) {
    try {
        $installed = $cms->isInstalled();
        return $installed;
    } catch (\Exception $e) {}

    return isset($db_config['default-connection']) ? $environment : 'install';
});

/**
 * ----------------------------------------------------------------------------
 * Enable Filesystem Config.
 * ----------------------------------------------------------------------------
 */
if (!$cms->bound('config')) {
    $cms->bindShared('config', function(Application $cms) {
        $file_system = new Filesystem();
        $file_loader = new FileLoader($file_system);
        $file_saver = new FileSaver($file_system);
        return new ConfigRepository($file_loader, $file_saver, $cms->environment());
    });
}

$config = $cms->make('config');

/*
 * ----------------------------------------------------------------------------
 * Finalize paths.
 * ----------------------------------------------------------------------------
 */
require DIR_BASE_CORE . '/bootstrap/paths_configured.php';

/**
 * ----------------------------------------------------------------------------
 * Timezone Config
 * ----------------------------------------------------------------------------
 */
if (!$config->has('app.timezone')) {
    // There is no timezone set.
    $config->set('app.timezone', @date_default_timezone_get());
}

if (!$config->has('app.server_timezone')) {
    // There is no server timezone set.
    $config->set('app.server_timezone', @date_default_timezone_get());
}

@date_default_timezone_set($config->get('app.timezone'));

/**
 * ----------------------------------------------------------------------------
 * Setup core classes aliases.
 * ----------------------------------------------------------------------------
 */
$list = ClassAliasList::getInstance();
$list->registerMultiple($config->get('app.aliases'));
$list->registerMultiple($config->get('app.facades'));

/**
 * ----------------------------------------------------------------------------
 * Set up Database Config.
 * ----------------------------------------------------------------------------
 */

if (!$cms->bound('config/database')) {
    $cms->bindShared('config/database', function(Application $cms) {
        $database_loader = new DatabaseLoader();
        $database_saver = new DatabaseSaver();
        return new ConfigRepository($database_loader, $database_saver, $cms->environment());
    });
}

$database_config = $cms->make('config/database');

/**
 * ----------------------------------------------------------------------------
 * Setup the core service groups.
 * ----------------------------------------------------------------------------
 */

$list = new ProviderList($cms);

// Register events first so that they can be used by other providers.
$list->registerProvider($config->get('app.providers.core_events'));

// Register all other providers
$list->registerProviders($config->get('app.providers'));

/**
 * ----------------------------------------------------------------------------
 * Legacy Definitions
 * ----------------------------------------------------------------------------
 */
define('APP_VERSION', $config->get('concrete.version'));
define('APP_CHARSET', $config->get('concrete.charset'));
try {
    define('BASE_URL', \Core::getApplicationURL());
} catch (\Exception $x) {
    echo $x->getMessage();
    die(1);
}
define('DIR_REL', $cms['app_relative_path']);


/**
 * ----------------------------------------------------------------------------
 * Setup file cache directories. Has to come after we define services
 * because we use the file service.
 * ----------------------------------------------------------------------------
 */
$cms->setupFilesystem();

/**
 * ----------------------------------------------------------------------------
 * Registries for theme paths, assets, routes and file types.
 * ----------------------------------------------------------------------------
 */
$asset_list = AssetList::getInstance();

$asset_list->registerMultiple($config->get('app.assets', array()));
$asset_list->registerGroupMultiple($config->get('app.asset_groups', array()));

Route::registerMultiple($config->get('app.routes'));
Route::setThemesByRoutes($config->get('app.theme_paths', array()));

$type_list = TypeList::getInstance();
$type_list->defineMultiple($config->get('app.file_types', array()));
$type_list->defineImporterAttributeMultiple($config->get('app.importer_attributes', array()));

/**
 * ----------------------------------------------------------------------------
 * If we are running through the command line, we don't proceed any further
 * ----------------------------------------------------------------------------
 */
if ($cms->isRunThroughCommandLineInterface()) {
    return $cms;
}

/**
 * ----------------------------------------------------------------------------
 * If not through CLI, load up the application/bootstrap/app.php
 */
include DIR_APPLICATION . '/bootstrap/app.php';

/**
 * Enable PSR-7 http middleware stack
 */
$request = Zend\Diactoros\ServerRequestFactory::fromGlobals();
$response = $cms->make('Zend\Diactoros\Response');

/** @type \Concrete\Core\Http\Middleware\RequestHandler $handler */
$handler = $cms->make('Concrete\Core\Http\Middleware\RequestHandler');

// Set Middlewares
$middlewares = $cms['config']->get('http.middleware');
foreach ($middlewares as $middleware) {
    list($class, $priority) = $middleware;
    $handler->addMiddleware($cms->make($class), $priority);
}

// Set Router
if ($router = $cms['config']->get('http.router')) {
    $handler->setRouter($cms->make($router));
}

// Bootstrap diactoros server
$server = $cms->make('Zend\Diactoros\Server', [
    function($request, $response) use ($handler) {
        return $handler->handleRequest($request, $response, function($request, $response) {
            return $response;
        });
    }, $request, $response]);

// Begin Listening
$server->listen();

return $cms;
