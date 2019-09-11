<?php
namespace Pulsestorm\Magento2\Generate\Registerpackage;
use function Pulsestorm\Pestle\Importer\pestle_import;
use stdClass;
pestle_import('Pulsestorm\Pestle\Library\output');

pestle_import('Pulsestorm\Pestle\Library\exitWithErrorMessage');
pestle_import('Pulsestorm\Cli\Token_Parse\pestle_token_get_all');
pestle_import('Pulsestorm\Pestle\Library\writeStringToFile');
pestle_import('Pulsestorm\Pestle\Library\loadJsonFromFile');
pestle_import('Pulsestorm\Pestle\Library\fetchObjectPath');
pestle_import('Pulsestorm\Pestle\Importer\loadConfig');
pestle_import('Pulsestorm\Pestle\Importer\saveConfig');
function createOrValidateRegistrationFile($modulePath, $moduleName) {
    $pathRegistration   = $modulePath . '/registration.php';
    $hasRegistration    = file_exists($pathRegistration);
    if($hasRegistration) {
        $tokens = pestle_token_get_all(file_get_contents($pathRegistration));

        $moduleCanidates = array_filter($tokens, function($token){
            $parts = explode('_', $token->token_value);
            return count($parts) === 2 &&
                $token->token_name == 'T_CONSTANT_ENCAPSED_STRING';
        });

        $values = array_map(function($token){
            return trim($token->token_value, "'\"");
        }, $moduleCanidates);
        if(!in_array($moduleName, $values)) {
            exitWithErrorMessage("Found registration.php file, but did not find $moduleName in that file.");
        }
    }
}

function createComposerFileIfNotThere($pathComposer, $hasComposer,
    $moduleNamespacePrefix, $packageName) {
    if(!$hasComposer) {
        $pathAutoload = 'src/';
        $composer = new stdClass;
        $composer->name = $packageName;
        $composer->description = 'A Magento Module';
        $composer->type = 'magento2-module';
        $composer->{'minimum-stability'} = 'stable';
        $composer->require = new stdClass;
        $composer->autoload = (object) ([
            'files'=>['registration.php'],
            'psr4'=> ((object)([
                $moduleNamespacePrefix=>$pathAutoload
            ]))
        ]);
        writeStringToFile($pathComposer,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        mkdir(dirname($pathComposer) . '/' . $pathAutoload);
    }
}

function validateComposerFileIfThere($pathComposer, $hasComposer,
    $moduleNamespacePrefix) {
    if($hasComposer) {
        $composer = json_decode(file_get_contents($pathComposer));
        $psr4Modules = array_keys((array)$composer->autoload->psr4);
        $hasPsr4 = in_array($moduleNamespacePrefix, $psr4Modules);
        if(!$hasPsr4) {
            exitWithErrorMessage("Found composer.json, but did not find a " .
                "$moduleNamespacePrefix psr4 autoloader");
        }
    }
}

/**
* This command will register a folder on your computers
* as the composer package for a particular module. This
* will tell pestle that files for this particular module
* should be generated in this folder.
*
* This command will also, if neccesary, create the module's
* registration.php file and composer.json file.
*
* If your module already has a composer.json, this command
* will look for a psr-4 autoload section for the module
* namespace.  If found, code will be generated in the
* configured folder. If not found, this command will add
* the `src/` folder as a psr-4 autoloader for your module
* namespace.
*
* If your module folder already has a regsitration.php file
* and it does not actually registers a module by the name
* you've indicated, this command will exit.
*
* @command magento2:generate:register-package
* @argument module What module are you registering? [Pulsestorm_HelloWorld]
* @argument path Where will this module live? [/path/to/module/folder]
*/
function pestle_cli($argv)
{
    $modulePath = $argv['path'];
    $moduleName = $argv['module'];
    $moduleParts = explode('_', $moduleName);
    $packageName = strToLower(implode('/', $moduleParts));
    $moduleNamespacePrefix = $packageName = implode("\\", $moduleParts) . "\\";

    if(!is_dir($modulePath)) {
        exitWithErrorMessage("ERROR: no such path $modulePath");
    }

    // generate the regsitration file if not there

    // validate the registration file
    createOrValidateRegistrationFile($modulePath, $moduleName);

    // create the composer.json file if it's not there
    $pathComposer       = $modulePath . '/composer.json';
    $hasComposer        = file_exists($pathComposer);

    validateComposerFileIfThere($pathComposer, $hasComposer, $moduleNamespacePrefix);
    createComposerFileIfNotThere($pathComposer, $hasComposer,
        $moduleNamespacePrefix, $packageName);

    // load composer.json file and extract PSR path for our module
    $object = loadJsonFromFile($pathComposer);
    $psr4AutoLoaders = fetchObjectPath($object, 'autoload/psr4');

    $pathForGeneration = null;
    foreach($psr4AutoLoaders as $loaderPrefix=>$loader) {
        if($loaderPrefix !== $moduleNamespacePrefix) { continue; }
        $pathForGeneration = dirname($pathComposer) . '/' . $loader;

        //match first then bail
        break;
    }

    if(!$pathForGeneration) {
        exitWithErrorMessage("Found no psr4 autoloader for $moduleNamespacePrefix");
    }

    if(!is_dir($pathForGeneration)) {
        exitWithErrorMessage("Found psr4 autoloader, but $pathForGeneration directory does not exist");
    }

    $action = 'Saved';
    $config = loadConfig('package-folders');

    if(isset($config->{$moduleName})) {
        $action = 'Edited';
    }

    // save module + psr path to config
    $config->{$moduleName} = $pathForGeneration;
    saveConfig('package-folders', $config);

    output($action . "\n    " . $moduleName . "=>" . $pathForGeneration .
        "\n    in package-folders");
}
