<?php

namespace InstancePro\Composer\Installer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use ReflectionClass;
use TYPO3\CMS\Composer\Plugin\Config as Typo3Config;

/**
 * Class Plugin
 * @package Instance\Composer\Installer
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @return \array[][]
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => [
                ['postCmdEvent']
            ],
            ScriptEvents::POST_UPDATE_CMD => [
                ['postCmdEvent']
            ],
        ];
    }

    /**
     * @param Event $event
     */
    public function postCmdEvent(Event $event)
    {
        $config = $this->composer->getConfig();
        $typo3Config = Typo3Config::load($this->composer);

        $this->createHtaccessFile($config, $typo3Config);
    }

    /**
     * @param Config $config
     * @param Typo3Config $typo3Config
     */
    protected function createHtaccessFile(Config $config, Typo3Config $typo3Config)
    {
        $filesystem = new Filesystem();

        $baseDir = $this->extractBaseDir($config);
        $webDir = $typo3Config->get('web-dir');

        $vendorHtaccessPath = $this->getPathByArguments($webDir, ...explode('/', 'typo3/sysext/install/Resources/Private/FolderStructureTemplateFiles/root-htaccess'));
        $webHtaccessPath = $this->getPathByArguments($webDir, '.htaccess');
        $instancePath = $this->getPathByArguments($baseDir, 'config', 'instance');
        $customHtaccessPath = $this->getPathByArguments($instancePath, 'htaccess');

        $templateFilesPath = $this->getPathByArguments(dirname(__FILE__), '..', 'config', 'instance');

        if (!is_dir($instancePath)) {
            mkdir($instancePath, 0777, true);
            $filesystem->copy($templateFilesPath, $instancePath);
        }

        if (is_file($webHtaccessPath)) {
            unlink($webHtaccessPath);
        }

        if (copy($vendorHtaccessPath, $webHtaccessPath)) {
            $htaccessContent = file_get_contents($webHtaccessPath);

            foreach (glob($customHtaccessPath . DIRECTORY_SEPARATOR . '*') as $filename) {
                $basename = basename($filename);
                $fileContent = file_get_contents($filename);
                $fileContentArray = explode("\n", $fileContent);
                $config = ltrim(array_shift($fileContentArray), '# ');

                list($key, $searchString) = explode(':', $config, 2);

                $pattern = '/(' . preg_quote($searchString, '/') . ')/';
                $subject = implode("\n", $fileContentArray);

                $filePath = 'config/instance/htaccess/' . $basename;

                $subject = "\n\n" . '### Begin: import(' . $filePath . ') ###' . "\n" . $subject . "\n" .  '### End: import(' . $filePath . ') ###' . "\n\n";

                if ($key === 'prepend') {
                    $subject = $subject . '\1';
                }
                if ($key === 'append') {
                    $subject = '\1' . $subject;
                }

                $subject = addcslashes($subject, '$');
                $htaccessContent = preg_replace($pattern, $subject, $htaccessContent);
            }

            $replaceConfigVars = [
                '%composer-base-dir%' => $baseDir,
                '%composer-web-dir%' => $webDir,
            ];

            $htaccessContent = str_replace(array_keys($replaceConfigVars), array_values($replaceConfigVars), $htaccessContent);

            file_put_contents($webHtaccessPath, $htaccessContent);
        }
    }

    /**
     * @return string
     */
    protected function getPathByArguments()
    {
        return implode(DIRECTORY_SEPARATOR, func_get_args());
    }

    /**
     * @param Config $config
     * @return mixed
     */
    protected function extractBaseDir(Config $config)
    {
        $reflectionClass = new ReflectionClass($config);
        $reflectionProperty = $reflectionClass->getProperty('baseDir');
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($config);
    }
}
