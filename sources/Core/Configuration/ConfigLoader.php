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

class ConfigLoader
{
	private static ConfigLoader $oInstance;

	protected function __construct()
	{
	}

	final public static function GetInstance(): ConfigLoader
	{
		if (!isset(static::$oInstance)) {
			static::$oInstance = new ConfigLoader();
		}

		return static::$oInstance;
	}

	public function Load(string $sConfigFile)
	{
		$sConfigCode = trim(file_get_contents($sConfigFile));

		// Variables created when doing an eval() on the config file
		/** @var array $MySettings */
		$MySettings = null;
		/** @var array $MyModuleSettings */
		$MyModuleSettings = null;
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

		if (!isset($MySettings) || !is_array($MySettings))
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

		$this->LoadModulesConfig(dirname($sConfigFile), $MySettings, $MyModuleSettings, $MyModules);

		return [$MySettings, $MyModuleSettings, $MyModules];
	}

	private function LoadModulesConfig(string $sConfigDir, array &$MySettings, array &$MyModuleSettings, array &$MyModules)
	{
		foreach (glob($sConfigDir.'/modules/*/*.json') as $sJSONModuleFiles) {
			$aConf = json_decode(file_get_contents($sJSONModuleFiles), true);
			if (is_null($aConf)) {
				IssueLog::Exception('Error reading configuration file: '.$sJSONModuleFiles, new Exception());
				continue;
			}
			// TODO ajouter les confs dans les tableaux
			if (is_array($aConf['MySettings'])) {
				$MySettings = array_merge($MySettings, $aConf['MySettings']);
			}
			if (is_array($aConf['MyModuleSettings'])) {
				$MyModuleSettings = array_merge($MyModuleSettings, $aConf['MyModuleSettings']);
			}
			if (is_array($aConf['MyModules'])) {
				$MyModules = array_merge($MyModules, $aConf['MyModules']);
			}
		}
	}
}