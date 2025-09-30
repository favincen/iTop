<?php
/**
 * @copyright   Copyright (C) 2010-2025 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace Combodo\iTop\Core\Configuration;

use ConfigException;
use Error;
use Exception;
use IssueLog;
use SetupUtils;
use utils;

class ConfigManager
{
	private static ConfigManager $oInstance;
	private ?array $aMySettings = null;
	private ?array $aMyModuleSettings = null;
	private ?array $aMyModules = null;

	protected function __construct()
	{
	}

	final public static function GetInstance(): ConfigManager
	{
		if (!isset(static::$oInstance)) {
			static::$oInstance = new ConfigManager();
		}

		return static::$oInstance;
	}

	/**
	 * Load iTop configuration from regular php file and json files
	 * @param string $sConfigFile
	 *
	 * @return array returns [$MySettings, $MyModuleSettings, $MyModules]
	 * @throws \ConfigException
	 */
	public function Load(string $sConfigFile): array
	{
		$sConfigCode = trim(file_get_contents($sConfigFile));

		if (!is_null($this->aMySettings)) {
			return [$this->aMySettings, $this->aMyModuleSettings, $this->aMyModules];
		}

		// Variables created when doing an eval() on the config file
		/** @var array $MySettings */
		$MySettings = null;
		/** @var array $MyModuleSettings */
		$MyModuleSettings = [];
		/** @var array $MyModules */
		$MyModules = null;

		// This does not work on several lines
		// preg_match('/^<\\?php(.*)\\?'.'>$/', $sConfigCode, $aMatches)...
		// So, I've implemented a solution suggested in the PHP doc (search for phpWrapper)
		try
		{
			ob_start();
			eval('?'.'>'.trim($sConfigCode));
			$sNoise = trim(ob_get_contents());
			ob_end_clean();
		}
		catch (Error $e)
		{
			// PHP 7
			throw new ConfigException('Error in configuration file',
				array('file' => $sConfigFile, 'error' => $e->getMessage().' at line '.$e->getLine()));
		}
		catch (Exception $e)
		{
			// well, never reach in case of parsing error :-(
			// will be improved in PHP 6 ?
			throw new ConfigException('Error in configuration file',
				array('file' => $sConfigFile, 'error' => $e->getMessage()));
		}
		if (strlen($sNoise) > 0)
		{
			// Note: sNoise is an html output, but so far it was ok for me (e.g. showing the entire call stack)
			throw new ConfigException('Syntax error in configuration file',
				array('file' => $sConfigFile, 'error' => '<tt>'.utils::EscapeHtml($sNoise, ENT_QUOTES).'</tt>'));
		}

		if (!is_array($MySettings))
		{
			throw new ConfigException('Missing array in configuration file',
				array('file' => $sConfigFile, 'expected' => '$MySettings'));
		}

		if (!array_key_exists('addons', $MyModules))
		{
			throw new ConfigException('Missing item in configuration file',
				array('file' => $sConfigFile, 'expected' => '$MyModules[\'addons\']'));
		}
		if (!array_key_exists('user rights', $MyModules['addons']))
		{
			// Add one, by default
			$MyModules['addons']['user rights'] = '/addons/userrights/userrightsnull.class.inc.php';
		}

		$this->aMySettings = $MySettings;
		$this->aMyModuleSettings = $MyModuleSettings;
		$this->aMyModules = $MyModules;

		$this->LoadModulesConfig(dirname($sConfigFile));

		return [$this->aMySettings, $this->aMyModuleSettings, $this->aMyModules];
	}

	private function LoadModulesConfig(string $sConfigDir): void
	{
		foreach ($this->ListConfigFiles($sConfigDir) as $sJSONModuleFiles) {
			$aConf = json_decode(file_get_contents($sJSONModuleFiles), true);
			if (is_null($aConf)) {
				IssueLog::Exception('Error reading configuration file: '.$sJSONModuleFiles, new Exception());
				continue;
			}
			if (is_array($aConf['MySettings'] ?? null)) {
				$this->aMySettings = array_merge($this->aMySettings, $aConf['MySettings']);
			}
			if (is_array($aConf['MyModuleSettings'] ?? null)) {
				$this->aMyModuleSettings = array_merge($this->aMyModuleSettings, $aConf['MyModuleSettings']);
			}
			if (is_array($aConf['MyModules'] ?? null)) {
				$this->aMyModules = array_merge($this->aMyModules, $aConf['MyModules']);
			}
		}
	}

	/**
	 * Find all json files under $sRootDir
	 * @param string $sRootDir
	 *
	 * @return array
	 */
	private function ListConfigFiles(string $sRootDir): array
	{
		$aConfigFiles = [];
		while($aDirs = glob($sRootDir . '/*', GLOB_ONLYDIR)) {
			$sRootDir .= '/*';
			foreach ($aDirs as $sConfDir) {
				$aConfigFiles = array_merge($aConfigFiles, glob($sConfDir.'/*.json'));
			}
		}

		return $aConfigFiles;
	}

	public function SaveModuleConfig(string $sModule, array $MyModuleSettings, array $MySettings = null, array $MyModules = null): void
	{
		$sConfigFile = dirname(utils::GetConfigFilePath()).'/modules/'.$sModule.'/'.$sModule.'.json';
		SetupUtils::builddir(dirname($sConfigFile));
		$aConf = ['MyModuleSettings' => $MyModuleSettings];
		if (is_array($MySettings)) {
			$aConf['MySettings'] = $MySettings;
		}
		if (is_array($MyModules)) {
			$aConf['MyModules'] = $MyModules;
		}
		file_put_contents($sConfigFile, json_encode($aConf, JSON_PRETTY_PRINT));
	}
}