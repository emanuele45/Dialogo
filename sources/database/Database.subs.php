<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Beta
 *
 * This file has all the main functions in it that set up the database connection
 * and initializes the appropriate adapters.
 *
 */

/**
 * Initialize database classes and connection.
 *
 * @param string $db_server
 * @param string $db_name
 * @param string $db_user
 * @param string $db_passwd
 * @param string $db_prefix
 * @param array $db_options
 * @return null
 */
function elk_db_initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options = array(), $db_type = 'mysql')
{

	// quick 'n dirty initialization of the right database class.
	if ($db_type == 'mysql')
		return Database_MySQL::initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options);
	elseif ($db_type == 'postgresql')
		return Database_PostgreSQL::initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options);
	elseif ($db_type == 'sqlite')
		return Database_SQLite::initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options);
}

/**
 * Extend the database functionality.
 *
 * @param string $type = 'extra'
 */
function db_extend($type = 'extra')
{
	// this can be removed.
}

/**
 * Retrieve existing instance of the active database class.
 *
 * @return Database
 */
function database()
{
	global $db_type;
	static $db = null;

	if (isset($db))
		return $db;

	require_once(SOURCEDIR . '/database/Db.php');
	require_once(SOURCEDIR . '/database/Db-' . $db_type . '.class.php');

	// quick 'n dirty retrieval
	if ($db_type == 'mysql')
		$db = new Database_MySQL();
	elseif ($db_type == 'postgresql')
		$db = new Database_PostgreSQL();
	elseif ($db_type == 'sqlite')
		$db = new Database_SQLite();

	return $db;
}

/**
 * This function retrieves an existing instance of DbTable
 * and returns it.
 *
 * @return DbTable
 */
function db_table()
{
	global $db_type;
	static $tbl = null;

	if (isset($tbl))
		return $tbl;

	require_once(SOURCEDIR . '/database/DbTable.class.php');
	require_once(SOURCEDIR . '/database/DbTable-' . $db_type . '.php');

	// quick 'n dirty retrieval
	if ($db_type == 'mysql')
		$tbl = DbTable_MySQL();
	elseif ($db_type == 'postgresql')
		$tbl = DbTable_PostgreSQL();
	elseif ($db_type == 'sqlite')
		$tbl = DbTable_SQLite();

	return $tbl;
}

/**
 * This function returns an instance of DbSearch,
 * specifically designed for database utilities related to search.
 *
 * @return DbSearch
 *
 */
function db_search()
{
	global $db_type;
	static $db_search = null;

	if (isset($db_search))
		return $db_search;

	require_once(SOURCEDIR . '/database/DbSearch.php');
	require_once(SOURCEDIR . '/database/DbSearch-' . $db_type . '.php');

	// quick 'n dirty retrieval
	if ($db_type == 'mysql')
		$db_search = DbSearch_MySQL();
	elseif ($db_type == 'postgresql')
		$db_search = DbSearch_PostgreSQL();
	elseif ($db_type == 'sqlite')
		$db_search = DbSearch_SQLite();

	return $db_search;
}