<?php

/**
 * The class in this file is meant to store all the settings of ElkArte
 * For those soming from SMF, is basically the same as $modSettings
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

class Elk_Settings
{
	private $_settings = array();

	public function __construct($settings)
	{
		$this->_settings = $settings;
	}

	public function __get($name)
	{
		if (isset($_settings[$name]))
			return $_settings[$name];
		else
			return null;
	}

	public function __set($name, $val)
	{
		// @deprecated since 1.1
		global $modSettings;

		$this->_settings[$name] = $val;

		// The global and this line are for backward compatibility
		// @deprecated since 1.1
		$modSettings[$name] = $val;
	}

	public function __isset($name)
	{
		return isset($this->data[$name]);
	}

	public function __unset($name)
	{
		// @deprecated since 1.1
		global $modSettings;

		unset($this->data[$name]);

		// The global and this line are for backward compatibility
		// @deprecated since 1.1
		unset($modSettings[$name]);
	}

	protected function _update($variable, $value, $nullcache = false)
	{
		if ($value === true)
			$this->$variable = $this->$variable + 1;
		elseif ($value === false)
			$this->$variable = $this->$variable - 1;
		else
			$this->$variable = $value;

		Elk::$app->db->query('', '
			UPDATE {db_prefix}settings
			SET value = {string:value}
			WHERE variable = {string:variable}',
			array(
				'value' => $this->$variable,
				'variable' => $variable,
			)
		);

		// Clean out the cache and make sure the cobwebs are gone too.
		if ($nullcache)
			cache_put_data('modSettings', null, 90);
	}

	protected function _insert($changeArray)
	{
		$replaceArray = array();
		foreach ($changeArray as $variable => $value)
		{
			// Don't bother if it's already like that ;).
			if (isset($this->$variable) && $this->$variable == $value)
				continue;
			// If the variable isn't set, but would only be set to nothing'ness, then don't bother setting it.
			elseif (!isset($this->$variable) && empty($value))
				continue;

			$replaceArray[] = array($variable, $value);

			$this->$variable = $value;
		}

		if (empty($replaceArray))
			return;

		Elk::$app->db->insert('replace',
			'{db_prefix}settings',
			array('variable' => 'string-255', 'value' => 'string-65534'),
			$replaceArray,
			array('variable')
		);
	}

	/**
	 * Updates the settings table as well as $modSettings... only does one at a time if $update is true.
	 *
	 * What it does:
	 * - updates both the settings table and $modSettings array.
	 * - all of changeArray's indexes and values are assumed to have escaped apostrophes (')!
	 * - if a variable is already set to what you want to change it to, that
	 *   variable will be skipped over; it would be unnecessary to reset.
	 * - When update is true, UPDATEs will be used instead of REPLACE.
	 * - when update is true, the value can be true or false to increment
	 *  or decrement it, respectively.
	 *
	 * @param mixed[] $changeArray associative array of variable => value
	 * @param bool $update = false
	 * @param bool $debug = false
	 * @todo: add debugging features, $debug isn't used
	 */
	public function update($changeArray, $update = false, $debug = false)
	{
		if (empty($changeArray) || !is_array($changeArray))
			return;

		// In some cases, this may be better and faster, but for large sets we don't want so many UPDATEs.
		if ($update)
		{
			foreach ($changeArray as $variable => $value)
				$this->_update($variable, $value, true);
		}
		else
			$this->_insert($changeArray);

			// Clean out the cache and make sure the cobwebs are gone too.
		cache_put_data('modSettings', null, 90);
	}

	/**
	 * Deletes one setting from the settings table and takes care of Elk_Settings as well
	 *
	 * @param string $toRemove the setting or the settings to be removed
	 */
	public function remove($toRemove)
	{
		if (empty($toRemove))
			return;

		if (!is_array($toRemove))
			$toRemove = array($toRemove);

		// Remove the setting from the db
		Elk::$app->db->query('', '
			DELETE FROM {db_prefix}settings
			WHERE variable IN ({array_string:setting_name})',
			array(
				'setting_name' => $toRemove,
			)
		);

		// Remove it from Elk_Settings now so it does not persist
		foreach ($toRemove as $setting)
			if (isset($this->$setting))
				unset($this->$setting);

		// Kill the cache - it needs redoing now, but we won't bother ourselves with that here.
		cache_put_data('modSettings', null, 90);
	}
}