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

	public function __construct()
	{
		// Clean the request.
		cleanRequest();

		// Initiate the database connection and define some database functions to use.
		loadDatabase();

		$this->db = database();
		$this->request = Request::instance();

		// It's time for settings loaded from the database.
		reloadSettings();

		$this->security = new Security();
	}

	public static function init()
	{
		if (Elk::$app === null)
			Elk::$app = new Elk();
	}
}