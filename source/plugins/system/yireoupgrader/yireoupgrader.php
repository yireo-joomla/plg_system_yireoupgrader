<?php
/**
 * Joomla! plugin YireoUpgrader
 *
 * @author      Yireo (http://www.yireo.com/)
 * @package     YireoUpgrader
 * @copyright   Copyright (c) 2016 Yireo (http://www.yireo.com/)
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
class PlgSystemYireoUpgrader extends JPlugin
{
	/**
	 * Event onAfterDispatch
	 */
	public function onAfterRoute()
	{
		$this->fixManifestCaches();
		$this->fixUpdateUrls();
	}

	/**
	 * Method to add support key to download URL
	 *
	 * @param string &$url     The URL to download the package from
	 * @param array  &$headers An optional associative array of headers
	 *
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
		$extensionName = $this->getExtensionNameFromUrl($url);

		// Fetch the support key
		$support_key = $this->getSupportKeyFromExtension($extensionName);

		if (empty($support_key))
		{
			return false;
		}

		// Add the support key to the URL
		$newUrl = $this->getNewUpdateUrl($url, $support_key);

		// Add the key to the update URL
		if ($this->checkIfUpdateUrlIsValid($newUrl))
		{
			$url = $newUrl;

			return false;
		}

		return false;
	}

	/**
	 * Filter an URL into an extension name
	 *
	 * @param $url
	 *
	 * @return mixed
	 */
	protected function getExtensionNameFromUrl($url)
	{
		$filename = basename($url);
		$filename = preg_replace('/\.(zip|tgz|tar.gz)$/', '', $filename);
		$filename = preg_replace('/_j([0-9]+)([x]?)/', '', $filename);
		$extensionName = $filename;

		return $extensionName;
	}

	/**
	 * Get the support key from the extension
	 *
	 * @param $extensionName
	 *
	 * @return bool
	 */
	protected function getSupportKeyFromExtension($extensionName)
	{
        // Convert plugin-names to basic component name
        if (preg_match('/plg_([a-z0-9A-Z\-]+)_(.*)/', $extensionName, $extensionMatch))
        {
            $pluginName = $extensionMatch[2];
            $pluginGroup = $extensionMatch[1];
            $extensionName = 'com_' . $pluginName;
        }

		// Only components are supported for now
		if (preg_match('/^com_/', $extensionName) === false)
		{
			return false;
		}

		// Load the component
		if ($component = $this->getComponentFromName($componentName))
        {
		    return $component->params->get('support_key', '');
        }

        if (!empty($pluginGroup))
        {
            $componentName = 'com_' . $pluginGroup;

		    if ($component = $this->getComponentFromName($componentName))
            { 
	            return $component->params->get('support_key', '');
            }  
        }

		return false;
	}

    protected function getComponentFromName($componentName)
    {
		JLoader::import('joomla.application.component.helper');
		$component = JComponentHelper::getComponent($componentName);

		if (empty($component))
		{
			return false;
		}

        return $component;
    }

	/**
	 * Create a licensed updater URL out of a regular update URL
	 *
	 * @param $url
	 * @param $supportKey
	 *
	 * @return string
	 */
	protected function getNewUpdateUrl($url, $supportKey)
	{
		$separator = strpos($url, '?') !== false ? '&' : '?';
		$urlAddition = $separator . 'key=' . $supportKey;

		return $url . $urlAddition;
	}

	/**
	 * Check whether this new updater URL is working
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	protected function checkIfUpdateUrlIsValid($url)
	{
		// Check if this key is valid
		$tmpUrl = $url . '&validate=1';
		$http = JHttpFactory::getHttp();
		$response = $http->get($tmpUrl, array());

		if (empty($response))
		{
			return false;
		}

		if ($response->body !== '1')
		{
			return false;
		}

		return true;
	}

	/**
	 * Fix outdated manifest caches when files are manually updated (f.i. via git)
	 *
	 * @throws Exception
	 */
	protected function fixManifestCaches()
	{
		if ($this->isInstallerClearCacheRequest() === false)
		{
			return;
		}

		$items = $this->getYireoExtensions();

		foreach ($items as $item)
		{
			$installer = JInstaller::getInstance();
			$installer->refreshManifestCache($item->extension_id);
		}
	}

	/**
	 * Fix old Yireo updater URLs
	 */
	protected function fixUpdateUrls()
	{
		if ($this->isInstallerClearCacheRequest() === false)
		{
			return;
		}

		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->update($db->quoteName('#__update_sites'));
		$query->set($db->quoteName('enabled') . '=1');

		$original = $db->quote('http://www.yireo.com/');
		$replacement = $db->quote('https://www.yireo.com/');
		$location = $db->quoteName('location');

		$query->set($location . '= REPLACE(' . $location . ', ' . $original . ', ' . $replacement . ')');
		$query->where($location . ' LIKE ' . $db->quote('http://www.yireo.com%'));

		$db->setQuery($query);
		$db->execute();
	}

	/**
	 * Get a list of Yireo extensions from the database
	 *
	 * @return mixed
	 */
	protected function getYireoExtensions()
	{
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select($db->quoteName(['extension_id', 'type', 'element', 'folder']));
		$query->from($db->quoteName('#__extensions'));
		$query->where($db->quoteName('manifest_cache') . ' LIKE ' . $db->quote('%yireo%'));
		$db->setQuery($query);

		return $db->loadObjectList();
	}

	/**
	 * Check whether this is the request for clearing the installer cache
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected function isInstallerClearCacheRequest()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		if ($input->getCmd('option') !== 'com_installer')
		{
			return false;
		}

		if ($input->getCmd('task') !== 'update.purge')
		{
			return false;
		}

		return true;
	}
}
