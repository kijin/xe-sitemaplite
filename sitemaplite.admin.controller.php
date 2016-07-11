<?php

/**
 * @file sitemaplite.admin.controller.php
 * @author Kijin Sung <kijin@kijinsung.com>
 * @license GPLv2 or Later <https://www.gnu.org/licenses/gpl-2.0.html>
 * @brief Sitemap Lite Admin Controller
 */
class SitemapLiteAdminController extends SitemapLite
{
	/**
	 * Save admin config
	 */
	public function procSitemapliteAdminInsertConfig()
	{
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		
		$menu_srls = $vars->sitemaplite_menu_srls;
		$config->menu_srls = is_array($menu_srls) ? $menu_srls : array();
		
		$file_path = $vars->sitemaplite_file_path;
		$config->sitemap_file_path = ($file_path === 'root') ? 'root' : 'sub';
		
		$ping_search_engines = $vars->sitemaplite_ping_search_engines;
		$config->ping_search_engines = is_array($ping_search_engines) ? $ping_search_engines : array();
		
		$only_public_menus = $vars->sitemaplite_only_public_menus;
		$config->only_public_menus = ($only_public_menus === 'Y') ? true : false;
		
		$config->additional_urls = array();
		$additional_urls = explode("\n", $vars->sitemaplite_additional_urls);
		foreach ($additional_urls as $additional_url)
		{
			$additional_url = trim($additional_url);
			if ($additional_url)
			{
				$config->additional_urls[] = $additional_url;
			}
		}
		
		$oModuleController = getController('module');
		$output = $oModuleController->insertModuleConfig('sitemaplite', $config);
		
		if ($output->toBool())
		{
			$write_success = $this->writeSitemapXml($config);
			if ($write_success)
			{
				$this->setMessage('success_registed');
			}
			else
			{
				return new Object(-1, 'msg_sitemaplite_failed_to_write_xml_file');
			}
		}
		else
		{
			return $output;
		}
		
		if (Context::get('success_return_url'))
		{
			$this->setRedirectUrl(Context::get('success_return_url'));
		}
		else
		{
			$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispSitemapliteAdminConfig'));
		}
	}
	
	/**
	 * Write sitemap.xml
	 */
	public function writeSitemapXml($config = null)
	{
		// Use module config if a different config is not given
		if (!$config)
		{
			$config = $this->getConfig();
		}
		
		// Check XML path
		$xml_path = $this->getSitemapXmlPath($config->sitemap_file_path);
		if (!$this->isWritable($xml_path))
		{
			return false;
		}
		
		// Insert default URL
		$urls = array(rtrim(Context::getDefaultUrl(), '\\/') . '/');
		
		// Insert URL for each item in menu
		$oMenuAdminModel = getAdminModel('menu');
		foreach ($config->menu_srls as $menu_srl)
		{
			$menu_items = $oMenuAdminModel->getMenuItems($menu_srl);
			foreach ($menu_items->data as $item)
			{
				if (intval($item->group_srls) !== 0 && $config->only_public_menus !== false)
				{
					continue;
				}
				
				$url = $this->_formatUrl($item->url);
				if ($url !== false && $this->_isAllowedUrl($url))
				{
					$urls[] = $url;
				}
			}
		}
		
		// Register additional URLs
		if ($config->additional_urls)
		{
			foreach ($config->additional_urls as $url)
			{
				$url = $this->_formatUrl($item->url);
				if ($url !== false)
				{
					$urls[] = $url;
				}
			}
		}
		
		// Remove duplicate URLs
		$urls = array_unique($urls);
		
		// Write XML
		$xml = '<' . '?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
		foreach ($urls as $url)
		{
			$xml .= "\t" . '<url><loc>' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8', true) . '</loc></url>' . PHP_EOL;
		}
		$xml .= '</urlset>' . PHP_EOL;
		FileHandler::writeFile($xml_path, $xml);
		
		// Ping search engines
		if ($config->ping_search_engines)
		{
			$xml_url = $this->getSitemapXmlUrl($config->sitemap_file_path);
			$this->_pingSearchEngines($xml_url, $config->ping_search_engines);
		}
		return true;
	}
	
	/**
	 * Format a URL
	 */
	protected function _formatUrl($url)
	{
		static $dui = null;
		static $baseurl = null;
		static $rewrite = null;
		if ($dui === null)
		{
			$dui = parse_url(Context::getDefaultUrl());
			$baseurl = rtrim(Context::getDefaultUrl(), '\\/') . '/';
			$rewrite = Context::isAllowRewrite();
		}
		$url = trim($url);
		
		if (preg_match('@^(https?:)?//.+@', $url))
		{
			if ($this->_isInternalUrl($url) && ($url . '/' !== $baseurl))
			{
				return $url;
			}
		}
		elseif (preg_match('@^/.*@', $url))
		{
			return $dui['scheme'] . '://' . $dui['host'] . ($dui['port'] ? (':' . $dui['port']) : '') . $url;
		}
		elseif (preg_match('@(?:^#|\.php\?)@', $url))
		{
			return $baseurl . $url;
		}
		elseif ($url)
		{
			if ($rewrite)
			{
				return $baseurl . $url;
			}
			else
			{
				return $baseurl . 'index.php?mid=' . $url;
			}
		}
		else
		{
			return false;
		}
	}
	
	/**
	 * Check whether a URL is internal
	 */
	protected function _isInternalUrl($url)
	{
		static $regexp = null;
		if ($regexp === null)
		{
			$dui = parse_url(Context::getDefaultUrl());
			$regexp = '@^(https?:)?//' . preg_quote($dui['host'], '@') . '(:[0-9]+)?(/.*)?@';
		}
		return preg_match($regexp, $url) ? true : false;
	}
	
	/**
	 * Check whether a URL is allowed (block admin and member module URLs)
	 */
	protected function _isAllowedUrl($url)
	{
		if (preg_match('@\b(?:admin|module=admin|act=(?:disp|proc)(?:member|socialxe)\w+)\b@i', $url))
		{
			return false;
		}
		else
		{
			return true;
		}
	}
	
	/**
	 * Ping search engines
	 */
	protected function _pingSearchEngines($url, $search_engines = array())
	{
		$pings = array(
			'google' => 'http://www.google.com/webmasters/sitemaps/ping?sitemap=%s',
			'bing' => 'http://www.bing.com/ping?sitemap=%s',
		);
		
		$config = array('ssl_verify_host' => false);
		if (extension_loaded('curl'))
		{
			$config['adapter'] = 'curl';
		}
		
		if ($search_engines)
		{
			foreach ($search_engines as $search_engine)
			{
				if (isset($pings[$search_engine]))
				{
					$ping_url = sprintf($pings[$search_engine], urlencode($url));
					FileHandler::getRemoteResource($ping_url, null, 3, 'GET', null, array(), array(), array(), $config);
				}
			}
		}
	}
}
