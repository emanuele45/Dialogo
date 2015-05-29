<?php

/**
 * This file handles tasks related to personal messages. It performs all
 * the necessary (database updates, statistics updates) to add, delete, mark
 * etc personal messages.
 *
 * The functions in this file do NOT check permissions.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

use ElkArte\ValuesContainer;

if (!defined('ELK'))
	die('No access...');

class Personal_Message_Labels extends AbstractModel
{
	protected $_member = null;
	protected $_labels = array();

	public function __construct($member, $db)
	{
		parent::__construct($db);

		if ($member instanceof ValuesContainer)
			$this->_member = $member;
		elseif (is_array($member))
			$this->_member = new ValuesContainer($member);
		else
			throw new Elk_Exception('Errors.wrong_member_parameter');
	}

	/**
	 * Loads the list of PM labels.
	 *
	 */
	public function countLabels($force = false)
	{
		if ($force || ($labels = cache_get_data('labelCounts__' . $this->_member->id, 720)) === null)
		{
			$labels = $this->_labels;
			// Looks like we need to reseek!
			$result = $this->_db->query('', '
				SELECT
					labels, is_read, COUNT(*) AS num
				FROM {db_prefix}pm_recipients
				WHERE id_member = {int:current_member}
					AND deleted = {int:not_deleted}
				GROUP BY labels, is_read',
				array(
					'current_member' => $this->_member->id,
					'not_deleted' => 0,
				)
			);
			while ($row = $this->_db->fetch_assoc($result))
			{
				$this_labels = explode(',', $row['labels']);

				foreach ($this_labels as $this_label)
				{
					if (!isset($labels[(int) $this_label]))
						continue;

					$labels[(int) $this_label]['messages'] += $row['num'];
					if (!($row['is_read'] & 1))
						$labels[(int) $this_label]['unread_messages'] += $row['num'];
				}
			}
			$this->_db->free_result($result);

			// Store it please!
			cache_put_data('labelCounts__' . $this->_member->id, $this->_labels, 720);
			$this->_labels = $labels;
		}

		return $this->_labels;
	}

	public function addLabels($labels)
	{
		$inserts = array();
		foreach ($labels as $label)
		{
			$inserts[] = array($this->_member->id, $label);
		}

		if (empty($inserts))
			return;

		$this->_db->insert('',
			'{db_prefix}pm_user_labels',
			array(
				'id_member' => 'int',
				'label' => 'string-255'
			),
			$inserts,
			array('id_member')
		);
	}

	/**
	 * Determines the PMs which need an updated label.
	 *
	 * @param mixed[] $to_label
	 * @param int[] $label_type
	 * @param int $user_id
	 * @return integer|null
	 */
	public function changePMLabels($to_label, $label_type, $user_id)
	{
		global $options;

		$to_update = array();

		// Get information about each message...
		$request = $this->_db->query('', '
			SELECT
				id_pm, labels
			FROM {db_prefix}pm_recipients
			WHERE id_member = {int:current_member}
				AND id_pm IN ({array_int:to_label})
			LIMIT ' . count($to_label),
			array(
				'current_member' => $user_id,
				'to_label' => array_keys($to_label),
			)
		);
		while ($row = $this->_db->fetch_assoc($request))
		{
			$labels = $row['labels'] == '' ? array('-1') : explode(',', trim($row['labels']));

			// Already exists?  Then... unset it!
			$id_label = array_search($to_label[$row['id_pm']], $labels);

			if ($id_label !== false && $label_type[$row['id_pm']] !== 'add')
				unset($labels[$id_label]);
			elseif ($label_type[$row['id_pm']] !== 'rem')
				$labels[] = $to_label[$row['id_pm']];

			if (!empty($options['pm_remove_inbox_label']) && $to_label[$row['id_pm']] != '-1' && ($key = array_search('-1', $labels)) !== false)
				unset($labels[$key]);

			$set = implode(',', array_unique($labels));
			if ($set == '')
				$set = '-1';

			$to_update[$row['id_pm']] = $set;
		}
		$this->_db->free_result($request);

		if (!empty($to_update))
			return $this->updatePMLabels($to_update, $user_id);
	}

	/**
	 * Detects personal messages which need a new label.
	 *
	 * @param int[] $labels_to_remove
	 * @return integer|null
	 */
	public function removeLabelsFromPMs($labels_to_remove)
	{
		// Now find the messages to change.
		$request = $this->_db->query('', '
			SELECT
				id_pm, labels
			FROM {db_prefix}pm_recipients
			WHERE FIND_IN_SET({raw:find_label_implode}, labels) != 0
				AND id_member = {int:current_member}',
			array(
				'current_member' => $this->_member->id,
				'find_label_implode' => '\'' . implode('\', labels) != 0 OR FIND_IN_SET(\'', $labels_to_remove) . '\'',
			)
		);
		$to_update = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			// Do the long task of updating them...
			$toChange = array_diff(explode(',', $row['labels']), $labels_to_remove);

			if (empty($toChange))
				$toChange[] = '-1';

			$to_update[$row['id_pm']] = implode(',', array_unique($toChange));
		}
		$this->_db->free_result($request);

		if (!empty($to_update))
			return $this->updatePMLabels($to_update);
	}

	/**
	 * Updates PMs with their new label.
	 *
	 * @param mixed[] $to_update
	 * @return int
	 */
	protected function updatePMLabels($to_update)
	{
		$updateErrors = 0;

		foreach ($to_update as $id_pm => $set)
		{
			// Check that this string isn't going to be too large for the database.
			if (strlen($set) > 60)
			{
				$updateErrors++;

				// Make the string as long as possible and update anyway
				$set = substr($set, 0, 60);
				$set = substr($set, 0, strrpos($set, ','));
			}

			$this->_db->query('', '
				UPDATE {db_prefix}pm_recipients
				SET labels = {string:labels}
				WHERE id_pm = {int:id_pm}
					AND id_member = {int:current_member}',
				array(
					'current_member' => $this->_member->id,
					'id_pm' => $id_pm,
					'labels' => $set,
				)
			);
		}

		return $updateErrors;
	}

	public function updateLabels($to_update)
	{
		foreach ($to_update as $id => $text)
		{
			$this->_db->query('', '
				UPDATE {db_prefix}pm_user_labels
				SET label = {string:text}
				WHERE id_label = {int:id_label}',
				array(
					'text' => $text,
					'id_label' => $id,
				)
			);
		}
	}

	public function getLabels()
	{
		global $txt;

		$request = $this->_db->query('', '
			SELECT id_label, label
			FROM {db_prefix}pm_user_labels
			WHERE id_member = {int:id_member}',
			array(
				'id_member' => $this->_member->id,
			)
		);

		$this->_labels = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			$this->_labels[$row['id_label']] = array(
				'id' => $row['id_label'],
				'name' => trim($row['label']),
				'messages' => 0,
				'unread_messages' => 0,
			);
		}
		$this->_db->free_result($request);

		$this->_labels[-1] = array(
			'id' => -1,
			'name' => $txt['pm_msg_label_inbox'],
			'messages' => 0,
			'unread_messages' => 0,
		);

		return $this->_labels;
	}
}