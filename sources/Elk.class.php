<?php

/**
 * This is the Elk app, in other words *everything*
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Release Candidate 1
 *
 */

if (!defined('ELK'))
	die('No access...');

class Elk
{
	public static $app = null;
	public $db = null;
	public $request = null;
	public $security = null;
	public $modSettings = array();

	public static function init()
	{
		if (Elk::$app === null)
		{
			Elk::$app = new Elk();

			// Clean the request.
			cleanRequest();

			// Initiate the database connection and define some database functions to use.
			loadDatabase();

			Elk::$app->db = database();
			Elk::$app->request = Request::instance();

			// It's time for settings loaded from the database.
			Elk::$app->modSettings = new Elk_Settings(reloadSettings());

			Elk::$app->_elk_seed_generator();

			Elk::$app->security = new Security();
		}
	}

	private static function _start()
	{
	
	}

	/**
	 * Generate a random seed and ensure it's stored in settings.
	 */
	protected function _elk_seed_generator()
	{
		// Change the seed.
		if (mt_rand(1, 250) == 69 || empty($this->modSettings->rand_seed))
			$this->modSettings->update(array('rand_seed' => mt_rand()));
	}

	/**
	 * Generate a random seed and ensure it's stored in settings.
	 * @deprecated since 1.1 - introduced for backward compatibility with
	 *             the function elk_seed_generator
	 */
	public function elk_seed_generator()
	{
		$this->_elk_seed_generator();
	}
}