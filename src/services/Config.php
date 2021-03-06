<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\config\DbConfig;
use craft\config\GeneralConfig;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use yii\base\BaseObject;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;

/**
 * The Config service provides APIs for retrieving the values of Craft’s [config settings](http://craftcms.com/docs/config-settings),
 * as well as the values of any plugins’ config settings.
 *
 * An instance of the Config service is globally accessible in Craft via [[Application::config `Craft::$app->getConfig()`]].
 *
 * @property DbConfig      $db        the DB config settings
 * @property GeneralConfig $general   the general config settings
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Config extends Component
{
    // Constants
    // =========================================================================

    const CATEGORY_DB = 'db';
    const CATEGORY_GENERAL = 'general';

    // Properties
    // =========================================================================

    /**
     * @var string|null The environment ID Craft is currently running in.
     */
    public $env;

    /**
     * @var string The path to the config directory
     */
    public $configDir = '';

    /**
     * @var string The path to the directory containing the default application config settings
     */
    public $appDefaultsDir = '';

    /**
     * @var array
     */
    private $_configSettings = [];

    /**
     * @var bool|null
     */
    private $_dotEnvPath;

    // Public Methods
    // =========================================================================

    /**
     * Returns all of the config settings for a given category.
     *
     * @param string $category The config category
     *
     * @return BaseObject The config settings
     * @throws InvalidParamException if $category is invalid
     * @throws InvalidConfigException if the securityKey general config setting is not set, and a auto-generated one could not be saved
     */
    public function getConfigSettings(string $category): BaseObject
    {
        if (isset($this->_configSettings[$category])) {
            return $this->_configSettings[$category];
        }

        switch ($category) {
            case self::CATEGORY_DB:
                $class = DbConfig::class;
                break;
            case self::CATEGORY_GENERAL:
                $class = GeneralConfig::class;
                break;
            default:
                throw new InvalidParamException('Invalid config category: '.$category);
        }

        // Get any custom config settings
        $config = $this->getConfigFromFile($category);
        $config = $this->_configSettings[$category] = new $class($config);

        // todo: remove this eventually
        if ($category === self::CATEGORY_GENERAL) {
            /** @var GeneralConfig $config */
            if ($config->securityKey === null) {
                $keyPath = Craft::$app->getPath()->getRuntimePath().DIRECTORY_SEPARATOR.'validation.key';
                if (file_exists($keyPath)) {
                    $config->securityKey = trim(file_get_contents($keyPath));
                } else {
                    $key = Craft::$app->getSecurity()->generateRandomString();
                    try {
                        FileHelper::writeToFile($keyPath, $key);
                    } catch (ErrorException $e) {
                        throw new InvalidConfigException('The securityKey config setting is required, and an auto-generated value could not be generated: '.$e->getMessage());
                    }
                    $config->securityKey = $key;
                }
                Craft::$app->getDeprecator()->log('validation.key', "The auto-generated validation key stored at {$keyPath} has been deprecated. Copy its value to the “securityKey” config setting in config/general.php.");
            }
            if ($config->siteUrl === null && defined('CRAFT_SITE_URL')) {
                Craft::$app->getDeprecator()->log('CRAFT_SITE_URL', 'The CRAFT_SITE_URL constant has been deprecated. Set the “siteUrl” config setting in config/general.php instead.');
                $config->siteUrl = CRAFT_SITE_URL;
            }
        }

        return $config;
    }

    /**
     * Returns the DB config settings.
     *
     * @return DbConfig
     */
    public function getDb(): DbConfig
    {
        return $this->getConfigSettings(self::CATEGORY_DB);
    }

    /**
     * Returns the general config settings.
     *
     * @return GeneralConfig
     */
    public function getGeneral(): GeneralConfig
    {
        return $this->getConfigSettings(self::CATEGORY_GENERAL);
    }

    /**
     * Loads a config file from the config/ folder, checks if it's a multi-environment
     * config, and returns the values.
     *
     * @param $filename
     *
     * @return array
     */
    public function getConfigFromFile(string $filename): array
    {
        $path = $this->configDir.DIRECTORY_SEPARATOR.$filename.'.php';

        if (!file_exists($path)) {
            return [];
        }

        if (!is_array($config = @include $path)) {
            return [];
        }

        // If it's not a multi-environment config, return the whole thing
        if (!array_key_exists('*', $config)) {
            return $config;
        }

        // If no environment was specified, just look in the '*' array
        if ($this->env === null) {
            return $config['*'];
        }

        $mergedConfig = [];
        foreach ($config as $env => $envConfig) {
            if ($env === '*' || StringHelper::contains($this->env, $env)) {
                $mergedConfig = ArrayHelper::merge($mergedConfig, $envConfig);
            }
        }

        return $mergedConfig;
    }

    /**
     * Returns the path to the .env file (regardless of whether it exists).
     *
     * @return string
     */
    public function getDotEnvPath(): string
    {
        return $this->_dotEnvPath ?? ($this->_dotEnvPath = Craft::getAlias('@root/.env'));
    }

    /**
     * Sets an environment variable value in the project's .env file.
     *
     * @param string $name  The environment variable name
     * @param string $value The environment variable value
     *
     * @throws Exception if the .env file doesn't exist
     */
    public function setDotEnvVar($name, $value)
    {
        $path = $this->getDotEnvPath();

        if (!file_exists($path)) {
            throw new Exception("No .env file exists at {$path}");
        }

        $contents = file_get_contents($path);
        $qName = preg_quote($name, '/');
        $contents = preg_replace("/^(\s*){$qName}=.*/m", "\$1{$name}=\"{$value}\"", $contents, -1, $count);
        if ($count === 0) {
            $contents = rtrim($contents);
            $contents = ($contents ? $contents.PHP_EOL.PHP_EOL : '')."{$name}=\"{$value}\"".PHP_EOL;
        }

        FileHelper::writeToFile($path, $contents);
    }
}
