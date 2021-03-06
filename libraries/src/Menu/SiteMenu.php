<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  Copyright (C) 2005 - 2019 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Menu;

defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Cache\Exception\CacheExceptionInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\Exception\ExecutionFailureException;

/**
 * Menu class
 *
 * @since  1.5
 */
class SiteMenu extends AbstractMenu
{
	/**
	 * Application object
	 *
	 * @var    CMSApplication
	 * @since  3.5
	 */
	protected $app;

	/**
	 * Database driver
	 *
	 * @var    DatabaseDriver
	 * @since  3.5
	 */
	protected $db;

	/**
	 * Language object
	 *
	 * @var    Language
	 * @since  3.5
	 */
	protected $language;

	/**
	 * Class constructor
	 *
	 * @param   array  $options  An array of configuration options.
	 *
	 * @since   1.5
	 */
	public function __construct($options = array())
	{
		// Extract the internal dependencies before calling the parent constructor since it calls $this->load()
		$this->app      = isset($options['app']) && $options['app'] instanceof CMSApplication ? $options['app'] : Factory::getApplication();
		$this->db       = isset($options['db']) && $options['db'] instanceof DatabaseDriver ? $options['db'] : Factory::getDbo();
		$this->language = isset($options['language']) && $options['language'] instanceof Language ? $options['language'] : Factory::getLanguage();

		parent::__construct($options);
	}

	/**
	 * Loads the entire menu table into memory.
	 *
	 * @return  boolean  True on success, false on failure
	 *
	 * @since   1.5
	 */
	public function load()
	{
		$loader = function ()
		{
			$nulldate    = $this->db->quote($this->db->getNullDate());
			$currentDate = Factory::getDate()->toSql();
			$query = $this->db->getQuery(true)
				->select('m.id, m.menutype, m.title, m.alias, m.note, m.path AS route, m.link, m.type, m.level, m.language')
				->select($this->db->qn('m.browserNav') . ', m.access, m.params, m.home, m.img, m.template_style_id, m.component_id, m.parent_id')
				->select('e.element as component')
				->from('#__menu AS m')
				->join('LEFT', '#__extensions AS e ON m.component_id = e.extension_id')
				->where('m.published = 1')
				->where('m.parent_id > 0')
				->where('m.client_id = 0')
				->where('(m.publish_up = ' . $nulldate . ' OR m.publish_up <= ' . $this->db->quote($currentDate) . ')')
				->where('(m.publish_down = ' . $nulldate . ' OR m.publish_down >= ' . $this->db->quote($currentDate) . ')')
				->order('m.lft');

			// Set the query
			$this->db->setQuery($query);

			return $this->db->loadObjectList('id', MenuItem::class);
		};

		try
		{
			/** @var CallbackController $cache */
			$cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
				->createCacheController('callback', ['defaultgroup' => 'com_menus']);

			$this->items = $cache->get($loader, array(), md5(get_class($this)), false);
		}
		catch (CacheExceptionInterface $e)
		{
			try
			{
				$this->items = $loader();
			}
			catch (ExecutionFailureException $databaseException)
			{
				$this->app->enqueueMessage(Text::sprintf('JERROR_LOADING_MENUS', $databaseException->getMessage()), 'warning');

				return false;
			}
		}
		catch (ExecutionFailureException $e)
		{
			$this->app->enqueueMessage(Text::sprintf('JERROR_LOADING_MENUS', $e->getMessage()), 'warning');

			return false;
		}

		foreach ($this->getMenu() as &$item)
		{
			// Get parent information.
			$parent_tree = array();

			if (isset($this->getMenu()[$item->parent_id]))
			{
				$item->setParent($this->getMenu()[$item->parent_id]);
				$parent_tree  = $this->getMenu()[$item->parent_id]->tree;
			}

			// Create tree.
			$parent_tree[] = $item->id;
			$item->tree = $parent_tree;

			// Create the query array.
			$url = str_replace('index.php?', '', $item->link);
			$url = str_replace('&amp;', '&', $url);

			parse_str($url, $item->query);
		}

		return true;
	}

	/**
	 * Gets menu items by attribute
	 *
	 * @param   string   $attributes  The field name
	 * @param   string   $values      The value of the field
	 * @param   boolean  $firstonly   If true, only returns the first item found
	 *
	 * @return  MenuItem|MenuItem[]  An array of menu item objects or a single object if the $firstonly parameter is true
	 *
	 * @since   1.6
	 */
	public function getItems($attributes, $values, $firstonly = false)
	{
		$attributes = (array) $attributes;
		$values     = (array) $values;

		if ($this->app->isClient('site'))
		{
			// Filter by language if not set
			if (($key = array_search('language', $attributes)) === false)
			{
				if (Multilanguage::isEnabled())
				{
					$attributes[] = 'language';
					$values[]     = array(Factory::getLanguage()->getTag(), '*');
				}
			}
			elseif ($values[$key] === null)
			{
				unset($attributes[$key], $values[$key]);
			}

			// Filter by access level if not set
			if (($key = array_search('access', $attributes)) === false)
			{
				$attributes[] = 'access';
				$values[] = $this->user->getAuthorisedViewLevels();
			}
			elseif ($values[$key] === null)
			{
				unset($attributes[$key], $values[$key]);
			}
		}

		// Reset arrays or we get a notice if some values were unset
		$attributes = array_values($attributes);
		$values = array_values($values);

		return parent::getItems($attributes, $values, $firstonly);
	}

	/**
	 * Get menu item by id
	 *
	 * @param   string  $language  The language code.
	 *
	 * @return  MenuItem|null  The item object or null when not found for given language
	 *
	 * @since   1.6
	 */
	public function getDefault($language = '*')
	{
		if (array_key_exists($language, $this->default) && $this->app->isClient('site') && $this->app->getLanguageFilter())
		{
			return $this->getMenu()[$this->default[$language]];
		}

		if (array_key_exists('*', $this->default))
		{
			return $this->getMenu()[$this->default['*']];
		}
	}
}
