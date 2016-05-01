<?php
/**
 * Joomla! plugin YireoUpgrader
 *
 * @author      Yireo (http://www.yireo.com/)
 * @package     YireoUpgrader
 * @copyright   Copyright (c) 2014 Yireo (http://www.yireo.com/)
 * @license     GNU Public License (GPL) version 3 (http://www.gnu.org/licenses/gpl-3.0.html)
 * @link        http://www.yireo.com/
 */

// Check to ensure this file is included in Joomla!
defined('JPATH_BASE') or die;

jimport('joomla.plugin.plugin');

/**
 * Plugin class for allowing commercial Yireo extensions to be updated using the Joomla! Update Manager
 *
 * @package YireoUpgrader
 */
class plgSystemYireoUpgrader extends JPlugin
{
	/**
	 * Method to add support key to download URL
	 *
	 * @param string &$url The URL to download the package from
	 * @param array &$headers An optional associative array of headers
	 * @return boolean
	 */
	public function onInstallerBeforePackageDownload(&$url, &$headers)
	{
		// Not a Yireo extension
		if (preg_match('/yireo.com\//', $url) == false)
		{
			return true;
		}

		// The updater key has already been added
		if (preg_match('/(\?|\&)key=/', $url) == true)
		{
			return true;
		}

		// Extract the extension name
		$filename = basename($url);
		$filename = preg_replace('/\.(zip|tgz|tar.gz)$/', '', $filename);
		$filename = preg_replace('/_j([0-9]+)/', '', $filename);
		$extensionName = $filename;

		// Only components are supported for now
		if (preg_match('/^com_/', $extensionName) == false)
		{
			return false;
		}

		// Load the component
		JLoader::import('joomla.application.component.helper');
		$component = JComponentHelper::getComponent($extensionName);
		if (empty($component))
		{
			return false;
		}

		// Fetch the support key
		$support_key = $component->params->get('support_key', '');
		if (empty($support_key))
		{
			return false;
		}

		// Add the support key to the URL
		$separator = strpos($url, '?') !== false ? '&' : '?';
		$url_addition = $separator . 'key=' . $support_key;

		// Check if this key is valid
		$tmpUrl = $url . $url_addition . '&validate=1';
		$http = JHttpFactory::getHttp();
		$response = $http->get($tmpUrl, array());
		if (empty($response))
		{
			return false;
		}

		// Add the key to the update URL
		if ($response->body == '1')
		{
			$url .= $url_addition;

			return false;
		}

		return false;
	}
}
