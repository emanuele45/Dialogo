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

class Personal_Message_List extends AbstractModel
{
	const ALLATONCE = 0;
	const ONEBYONE = 1;
	const CONVERSATION = 2;

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

		if (isset($this->_member->pm['prefs']))
		{
			$this->_display_mode = $this->_member->pm['prefs'] & 3;
		}
		else
		{
			$this->_display_mode = Personal_Message_List::CONVERSATION;
		}
	}

	public function getDisplayMode()
	{
		return $this->_display_mode;
	}

	public function toggleDisplayMode()
	{
		$this->_display_mode = $this->_display_mode > 1 ? 0 : $this->_display_mode + 1;
		require_once(SUBSDIR . '/Members.subs.php');
		updateMemberData($this->_member->id, array('pm_prefs' => ($this->_member->pm['prefs'] & 252) | $this->_display_mode));

		return $this->_display_mode;
	}

	public function isConversationMode()
	{
		return $this->_display_mode == Personal_Message_List::CONVERSATION;
	}

	public function isOnebyoneMode()
	{
		return $this->_display_mode == Personal_Message_List::ONEBYONE;
	}

	public function isAllatonceMode()
	{
		return $this->_display_mode == Personal_Message_List::ALLATONCE;
	}

	public function countDiscussions($queryConditions = '')
	{
		$request = $this->_db->query('', '
			SELECT COUNT(DISTINCT id_pm_head)
			FROM {db_prefix}pm_topics
			WHERE id_member = {int:current_member}' . $queryConditions,
			array(
				'current_member' => $this->_member->id
			)
		);

		list ($count) = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		return $count;
	}

	/**
	 * Get the number of PMs.
	 *
	 * @param bool $descending
	 * @param int|null $pmID
	 * @param string $labelQuery
	 * @return int
	 */
	public function getCount($descending = false, $pmID = null, $labelQuery = '')
	{
		$request = $this->_db->query('', '
			SELECT
				COUNT(' . ($this->_display_mode == Personal_Message_List::CONVERSATION ? 'DISTINCT pm.id_pm_head' : '*') . ')
			FROM {db_prefix}pm_recipients AS pmr' . ($this->_display_mode == Personal_Message_List::CONVERSATION ? '
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)' : '') . '
			WHERE pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}' . $labelQuery . ($pmID !== null ? '
				AND pmr.id_pm ' . ($descending ? '>' : '<') . ' {int:id_pm}' : ''),
			array(
				'current_member' => $this->_member->id,
				'not_deleted' => 0,
				'id_pm' => $pmID,
			)
		);

		list ($count) = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		return $count;
	}

	/**
	 * Delete the specified personal messages.
	 *
	 * @param int[]|null $personal_messages array of pm ids
	 */
	public function deleteTopic($pm_head)
	{
		global $user_info;

		$request = $this->_db->query('', '
			SELECT id_pm
			FROM {db_prefix}pm_messages
			WHERE id_member = {int:current_member}
				AND id_pm_head IN ({array_int:pm_list})',
			array(
				'current_member' => $this->_member->id,
				'pm_list' => array_unique($pm_head),
			)
		);
		$pm_to_delete = array();
		while ($row = $this->_db->fetch_assoc($request))
			$pm_to_delete[] = $row['id_pm'];
		$this->_db->free_result($request);

		return $this->deleteMessages($pm_to_delete);
	}

	/**
	 * Delete the specified personal messages.
	 *
	 * @param int[]|null $personal_messages array of pm ids
	 */
	public function deleteMessages($personal_messages)
	{
		global $user_info;

		$request = $this->_db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}pm_messages
			WHERE id_member = {int:current_member}
				AND id_pm IN ({array_int:pm_list})',
			array(
				'current_member' => $this->_member->id,
				'pm_list' => array_unique($personal_messages),
			)
		);
		list ($total_deleted) = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		$this->_db->query('', '
			DELETE FROM {db_prefix}pm_messages
			WHERE id_member = {int:current_member}
				AND id_pm IN ({array_int:pm_list})',
			array(
				'current_member' => $this->_member->id,
				'pm_list' => array_unique($personal_messages),
			)
		);

		// If sender and recipients all have deleted their message, it can be removed.
		$request = $this->_db->query('', '
			SELECT
				pm.id_pm AS sender, pmr.id_pm
			FROM {db_prefix}personal_messages AS pm
				LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm AND pmr.deleted = {int:not_deleted})
			WHERE pm.deleted_by_sender = {int:is_deleted}
				' . str_replace('id_pm', 'pm.id_pm', $where) . '
			GROUP BY sender, pmr.id_pm
			HAVING pmr.id_pm IS null',
			array(
				'not_deleted' => 0,
				'is_deleted' => 1,
				'pm_list' => array_unique($personal_messages),
			)
		);
		$remove_pms = array();
		while ($row = $this->_db->fetch_assoc($request))
			$remove_pms[] = $row['sender'];
		$this->_db->free_result($request);

		if (!empty($remove_pms))
		{
			$this->_db->query('', '
				DELETE FROM {db_prefix}personal_messages
				WHERE id_pm IN ({array_int:pm_list})',
				array(
					'pm_list' => $remove_pms,
				)
			);

			$this->_db->query('', '
				DELETE FROM {db_prefix}pm_recipients
				WHERE id_pm IN ({array_int:pm_list})',
				array(
					'pm_list' => $remove_pms,
				)
			);
		}

		// Any cached numbers may be wrong now.
		cache_put_data('labelCounts__' . $this->_member->id, null, 720);
	}

	/**
	 * Mark the specified personal messages read.
	 *
	 * @param int[]|int|null $personal_messages null or array of pm ids
	 * @param string|null $label = null, if label is set, only marks messages with that label
	 * @param int|null $owner = null, if owner is set, marks messages owned by that member id
	 */
	public function markMessagesRead($personal_messages = null, $label = null, $owner = null)
	{
		if ($owner === null)
			$owner = $this->_member->id;

		if (!is_null($personal_messages) && !is_array($personal_messages))
			$personal_messages = array($personal_messages);

		$this->_db->query('', '
			UPDATE {db_prefix}pm_topics
			SET is_read = 1
			WHERE id_member = {int:id_member}
				AND is_read = 0' . ($label === null ? '' : '
				AND FIND_IN_SET({string:label}, labels) != 0') . ($personal_messages !== null ? '
				AND id_first_pm >= {int:min_personal_message}
				AND id_last_pm <= {int:max_personal_message}' : ''),
			array(
				'min_personal_message' => min($personal_messages),
				'max_personal_message' => max($personal_messages),
				'id_member' => $owner,
				'label' => $label,
			)
		);

		// If something wasn't marked as read, get the number of unread messages remaining.
		if ($this->_db->affected_rows() > 0)
			$this->updateMenuCounts($owner);
	}

	/**
	 * Mark the specified personal messages as unread.
	 *
	 * @param integer|integer[] $personal_messages
	 */
	public function markMessagesUnread($personal_messages = null, $label = null, $owner = null)
	{
		if ($owner === null)
			$owner = $this->_member->id;

		if (!is_null($personal_messages) && !is_array($personal_messages))
			$personal_messages = array($personal_messages);

		// Flip the "read" bit on this
		$this->_db->query('', '
			UPDATE {db_prefix}pm_topics
			SET is_read = 0
			WHERE id_member = {int:id_member}
				AND is_read = 1' . ($label === null ? '' : '
				AND FIND_IN_SET({string:label}, labels) != 0') . ($personal_messages !== null ? '
				AND id_pm IN ({array_int:personal_messages})' : ''),
			array(
				'personal_messages' => $personal_messages,
				'id_member' => $owner,
			)
		);

		// If something was marked unread, update the number of unread messages remaining.
		if ($this->_db->affected_rows() > 0)
			$this->updateMenuCounts($owner);
	}

	/**
	 * Updates the number of unread messages for a user
	 *
	 * - Updates the per label totals as well as the overall total
	 *
	 * @param int $owner
	 */
	protected function updateMenuCounts($owner)
	{
		global $user_info, $context;

		if ($owner == $this->_member->id)
		{
			foreach ($context['labels'] as $label)
				$context['labels'][(int) $label['id']]['unread_messages'] = 0;
		}

		$result = $this->_db->query('', '
			SELECT
				labels, COUNT(*) AS num
			FROM {db_prefix}pm_recipients
			WHERE id_member = {int:id_member}
				AND NOT (is_read & 1 >= 1)
				AND deleted = {int:is_not_deleted}
			GROUP BY labels',
			array(
				'id_member' => $owner,
				'is_not_deleted' => 0,
			)
		);
		$total_unread = 0;
		while ($row = $this->_db->fetch_assoc($result))
		{
			$total_unread += $row['num'];

			if ($owner != $this->_member->id)
				continue;

			$this_labels = explode(',', $row['labels']);
			foreach ($this_labels as $this_label)
				$context['labels'][(int) $this_label]['unread_messages'] += $row['num'];
		}
		$this->_db->free_result($result);

		// Need to store all this.
		cache_put_data('labelCounts__' . $owner, $context['labels'], 720);
		require_once(SUBSDIR . '/Members.subs.php');
		updateMemberData($owner, array('unread_messages' => $total_unread));

		// If it was for the current member, reflect this in the $this->_member array too.
		if ($owner == $this->_member->id)
			$user_info['unread_messages'] = $total_unread;
	}

	/**
	 * Load personal messages.
	 *
	 * This function loads messages considering the options given, an array of:
	 * - 'display_mode' - the PMs display mode (i.e. conversation, all)
	 * - 'is_postgres' - (temporary) boolean to allow choice of PostgreSQL-specific sorting query
	 * - 'sort_by_query' - query to sort by
	 * - 'descending' - whether to sort descending
	 * - 'sort_by' - field to sort by
	 * - 'pmgs' - personal message id (if any). Note: it may not be set.
	 * - 'label_query' - query by labels
	 * - 'start' - start id, if any
	 *
	 * @param mixed[] $pm_options options for loading
	 * @param int $id_member id member
	 */
	public function loadPMs($pm_options, $id_member)
	{
		global $options;

		// First work out what messages we need to see - if grouped is a little trickier...
		// Conversation mode
		if ($pm_options['display_mode'] == 2)
		{
			// On a non-default sort, when using PostgreSQL we have to do a harder sort.
			if ($this->_db->db_title() == 'PostgreSQL' && $pm_options['sort_by_query'] != 'pm.id_pm')
			{
				$sub_request = $this->_db->query('', '
					SELECT
						MAX({raw:sort}) AS sort_param, pm.id_pm_head
					FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
						LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
						INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
							AND pmr.id_member = {int:current_member}
							AND pmr.deleted = {int:not_deleted}
							' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ( '
						LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
					WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
						AND pm.deleted_by_sender = {int:not_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
						AND pm.id_pm = {int:id_pm}') . '
					GROUP BY pm.id_pm_head
					ORDER BY sort_param' . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
					LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'not_deleted' => 0,
						'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
						'id_pm' => isset($pm_options['pmsg']) ? $pm_options['pmsg'] : '0',
						'sort' => $pm_options['sort_by_query'],
					)
				);
				$sub_pms = array();
				while ($row = $this->_db->fetch_assoc($sub_request))
					$sub_pms[$row['id_pm_head']] = $row['sort_param'];
				$this->_db->free_result($sub_request);

				// Now we use those results in the next query
				$request = $this->_db->query('', '
					SELECT
						pm.id_pm AS id_pm, pm.id_pm_head
					FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
						LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
						INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
							AND pmr.id_member = {int:current_member}
							AND pmr.deleted = {int:not_deleted}
							' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ( '
						LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
					WHERE ' . (empty($sub_pms) ? '0=1' : 'pm.id_pm IN ({array_int:pm_list})') . '
					ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
					LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'pm_list' => array_keys($sub_pms),
						'not_deleted' => 0,
						'sort' => $pm_options['sort_by_query'],
						'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					)
				);
			}
			// Otherwise we can just use the the pm_conversation_list option
			else
			{
				$request = $this->_db->query('pm_conversation_list', '
					SELECT
						MAX(pm.id_pm) AS id_pm, pm.id_pm_head
					FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
						LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
						INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
							AND pmr.id_member = {int:current_member}
							AND pmr.deleted = {int:deleted_by}
							' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ( '
						LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
					WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
						AND pm.deleted_by_sender = {int:deleted_by}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
						AND pm.id_pm = {int:pmsg}') . '
					GROUP BY pm.id_pm_head
					ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
					LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'deleted_by' => 0,
						'sort' => $pm_options['sort_by_query'],
						'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
						'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
					)
				);
			}
		}
		// If not in conversation view, then this is kinda simple!
		else
		{
			// @todo SLOW This query uses a filesort. (inbox only.)
			$request = $this->_db->query('', '
				SELECT
					pm.id_pm, pm.id_pm_head, pm.id_member_from
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? '' . ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:is_deleted}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ( '
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
				WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {raw:current_member}
					AND pm.deleted_by_sender = {int:is_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
					AND pm.id_pm = {int:pmsg}') . '
				ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'pmr.id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
				array(
					'current_member' => $id_member,
					'is_deleted' => 0,
					'sort' => $pm_options['sort_by_query'],
					'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
				)
			);
		}
		// Load the id_pms and initialize recipients.
		$pms = array();
		$lastData = array();
		$posters = $pm_options['folder'] == 'sent' ? array($id_member) : array();
		$recipients = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			if (!isset($recipients[$row['id_pm']]))
			{
				if (isset($row['id_member_from']))
					$posters[$row['id_pm']] = $row['id_member_from'];

				$pms[$row['id_pm']] = $row['id_pm'];

				$recipients[$row['id_pm']] = array(
					'to' => array(),
					'bcc' => array()
				);
			}

			// Keep track of the last message so we know what the head is without another query!
			if ((empty($pm_options['pmid']) && (empty($options['view_newest_pm_first']) || !isset($lastData))) || empty($lastData) || (!empty($pm_options['pmid']) && $pm_options['pmid'] == $row['id_pm']))
				$lastData = array(
					'id' => $row['id_pm'],
					'head' => $row['id_pm_head'],
				);
		}
		$this->_db->free_result($request);

		return array($pms, $posters, $recipients, $lastData);
	}

	/**
	 * How many PMs have you sent lately?
	 *
	 * @param int $id_member id member
	 * @param int $time time interval (in seconds)
	 */
	public function pmCount($id_member, $time)
	{
		$request = $this->_db->query('', '
			SELECT
				COUNT(*) AS post_count
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pr ON (pr.id_pm = pm.id_pm)
			WHERE pm.id_member_from = {int:current_member}
				AND pm.msgtime > {int:msgtime}',
			array(
				'current_member' => $id_member,
				'msgtime' => time() - $time,
			)
		);
		list ($pmCount) = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		return $pmCount;
	}

	/**
	 * This will apply rules to all unread messages.
	 *
	 * - If all_messages is set will, clearly, do it to all!
	 *
	 * @param bool $all_messages = false
	 */
	public function applyRules($all_messages = false)
	{
		global $context, $options;

		// Want this - duh!
		loadRules();

		// No rules?
		if (empty($context['rules']))
			return;

		// Just unread ones?
		$ruleQuery = $all_messages ? '' : ' AND pmr.is_new = 1';

		// @todo Apply all should have timeout protection!
		// Get all the messages that match this.
		$request = $this->_db->query('', '
			SELECT
				pmr.id_pm, pm.id_member_from, pm.subject, pm.body, mem.id_group, pmr.labels
			FROM {db_prefix}pm_recipients AS pmr
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
			WHERE pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}
				' . $ruleQuery,
			array(
				'current_member' => $this->_member->id,
				'not_deleted' => 0,
			)
		);
		$actions = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			foreach ($context['rules'] as $rule)
			{
				$match = false;

				// Loop through all the criteria hoping to make a match.
				foreach ($rule['criteria'] as $criterium)
				{
					if (($criterium['t'] == 'mid' && $criterium['v'] == $row['id_member_from']) || ($criterium['t'] == 'gid' && $criterium['v'] == $row['id_group']) || ($criterium['t'] == 'sub' && strpos($row['subject'], $criterium['v']) !== false) || ($criterium['t'] == 'msg' && strpos($row['body'], $criterium['v']) !== false))
						$match = true;
					// If we're adding and one criteria don't match then we stop!
					elseif ($rule['logic'] == 'and')
					{
						$match = false;
						break;
					}
				}

				// If we have a match the rule must be true - act!
				if ($match)
				{
					if ($rule['delete'])
						$actions['deletes'][] = $row['id_pm'];
					else
					{
						foreach ($rule['actions'] as $ruleAction)
						{
							if ($ruleAction['t'] == 'lab')
							{
								// Get a basic pot started!
								if (!isset($actions['labels'][$row['id_pm']]))
									$actions['labels'][$row['id_pm']] = empty($row['labels']) ? array() : explode(',', $row['labels']);

								$actions['labels'][$row['id_pm']][] = $ruleAction['v'];
							}
						}
					}
				}
			}
		}
		$this->_db->free_result($request);

		// Deletes are easy!
		if (!empty($actions['deletes']))
			$this->deleteMessages($actions['deletes']);

		// Re-label?
		if (!empty($actions['labels']))
		{
			foreach ($actions['labels'] as $pm => $labels)
			{
				// Quickly check each label is valid!
				$realLabels = array();
				foreach ($context['labels'] as $label)
					if (in_array($label['id'], $labels) && ($label['id'] != -1 || empty($options['pm_remove_inbox_label'])))
						$realLabels[] = $label['id'];

				$this->_db->query('', '
					UPDATE {db_prefix}pm_recipients
					SET labels = {string:new_labels}
					WHERE id_pm = {int:id_pm}
						AND id_member = {int:current_member}',
					array(
						'current_member' => $this->_member->id,
						'id_pm' => $pm,
						'new_labels' => empty($realLabels) ? '' : implode(',', $realLabels),
					)
				);
			}
		}
	}

	/**
	 * Load up all the rules for the current user.
	 *
	 * @param bool $reload = false
	 */
	public function loadRules($reload = false)
	{
		global $context;

		if (isset($context['rules']) && !$reload)
			return;

		// This is just a simple list of "all" known rules
		$context['known_rules'] = array(
			// member_id == "Sender Name"
			'mid',
			// group_id == "Sender's Groups"
			'gid',
			// subject == "Message Subject Contains"
			'sub',
			// message == "Message Body Contains"
			'msg',
			// buddy == "Sender is Buddy"
			'bud',
		);

		$request = $this->_db->query('', '
			SELECT
				id_rule, rule_name, criteria, actions, delete_pm, is_or
			FROM {db_prefix}pm_rules
			WHERE id_member = {int:current_member}',
			array(
				'current_member' => $this->_member->id,
			)
		);
		$context['rules'] = array();
		// Simply fill in the data!
		while ($row = $this->_db->fetch_assoc($request))
		{
			$context['rules'][$row['id_rule']] = array(
				'id' => $row['id_rule'],
				'name' => $row['rule_name'],
				'criteria' => unserialize($row['criteria']),
				'actions' => unserialize($row['actions']),
				'delete' => $row['delete_pm'],
				'logic' => $row['is_or'] ? 'or' : 'and',
			);

			if ($row['delete_pm'])
				$context['rules'][$row['id_rule']]['actions'][] = array('t' => 'del', 'v' => 1);
		}
		$this->_db->free_result($request);
	}

	/**
	 * Update PM recipient when they receive or read a new PM
	 *
	 * @param boolean $new = false
	 */
	public function toggleNewPM($new = false)
	{
		$this->_db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET is_new = ' . ($new ? '{int:new}' : '{int:not_new}') . '
			WHERE id_member = {int:current_member}',
			array(
				'current_member' => $this->_member->id,
				'new' => 1,
				'not_new' => 0
			)
		);
	}

	/**
	 * Retrieve the discussion one or more PMs belong to
	 *
	 * @param int[] $id_pms
	 */
	public function getDiscussions($id_pms)
	{
		$request = $this->_db->query('', '
			SELECT
				id_pm_head, id_pm
			FROM {db_prefix}personal_messages
			WHERE id_pm IN ({array_int:id_pms})',
			array(
				'id_pms' => $id_pms,
			)
		);
		$pm_heads = array();
		while ($row = $this->_db->fetch_assoc($request))
			$pm_heads[$row['id_pm_head']] = $row['id_pm'];
		$this->_db->free_result($request);

		return $pm_heads;
	}

	/**
	 * Return all the PMs belonging to one or more discussions
	 *
	 * @param int[] $pm_heads array of pm id head nodes
	 */
	public function getPmsFromDiscussion($pm_heads)
	{
		$pms = array();
		$request = $this->_db->query('', '
			SELECT
				id_pm, id_pm_head
			FROM {db_prefix}personal_messages
			WHERE id_pm_head IN ({array_int:pm_heads})',
			array(
				'pm_heads' => $pm_heads,
			)
		);
		// Copy the action from the single to PM to the others.
		while ($row = $this->_db->fetch_assoc($request))
			$pms[$row['id_pm']] = $row['id_pm_head'];
		$this->_db->free_result($request);

		return $pms;
	}

	/**
	 * Gets PMs older than a specific date.
	 *
	 * @param int $user_id the user's id.
	 * @param int $time timestamp with a specific date
	 * @return array
	 */
	public function getPMsOlderThan($user_id, $time)
	{
		// Array to store the IDs in.
		$pm_ids = array();

		// Select all the messages they have sent older than $time.
		$request = $this->_db->query('', '
			SELECT
				id_pm
			FROM {db_prefix}personal_messages
			WHERE deleted_by_sender = {int:not_deleted}
				AND id_member_from = {int:current_member}
				AND msgtime < {int:msgtime}',
			array(
				'current_member' => $user_id,
				'not_deleted' => 0,
				'msgtime' => $time,
			)
		);
		while ($row = $this->_db->fetch_row($request))
			$pm_ids[] = $row[0];
		$this->_db->free_result($request);

		// This is the inbox
		$request = $this->_db->query('', '
			SELECT
				pmr.id_pm
			FROM {db_prefix}pm_recipients AS pmr
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
			WHERE pmr.deleted = {int:not_deleted}
				AND pmr.id_member = {int:current_member}
				AND pm.msgtime < {int:msgtime}',
			array(
				'current_member' => $user_id,
				'not_deleted' => 0,
				'msgtime' => $time,
			)
		);
		while ($row = $this->_db->fetch_row($request))
			$pm_ids[] = $row[0];
		$this->_db->free_result($request);

		return $pm_ids;
	}

	/**
	 * Used to delete PM rules from the given member.
	 *
	 * @param int $id_member
	 * @param int[] $rule_changes
	 */
	public function deletePMRules($id_member, $rule_changes)
	{
		$this->_db->query('', '
			DELETE FROM {db_prefix}pm_rules
			WHERE id_rule IN ({array_int:rule_list})
			AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'rule_list' => $rule_changes,
			)
		);
	}

	/**
	 * Updates a personal messaging rule action for the given member.
	 *
	 * @param int $id_rule
	 * @param int $id_member
	 * @param mixed[] $actions
	 */
	public function updatePMRuleAction($id_rule, $id_member, $actions)
	{
		$this->_db->query('', '
			UPDATE {db_prefix}pm_rules
			SET actions = {string:actions}
			WHERE id_rule = {int:id_rule}
				AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'id_rule' => $id_rule,
				'actions' => serialize($actions),
			)
		);
	}

	/**
	 * Add a new PM rule to the database.
	 *
	 * @param int $id_member
	 * @param string $ruleName
	 * @param string $criteria
	 * @param string $actions
	 * @param int $doDelete
	 * @param int $isOr
	 */
	public function addPMRule($id_member, $ruleName, $criteria, $actions, $doDelete, $isOr)
	{
		$this->_db->insert('',
			'{db_prefix}pm_rules',
			array(
				'id_member' => 'int', 'rule_name' => 'string', 'criteria' => 'string', 'actions' => 'string',
				'delete_pm' => 'int', 'is_or' => 'int',
			),
			array(
				$id_member, $ruleName, $criteria, $actions, $doDelete, $isOr,
			),
			array('id_rule')
		);
	}

	/**
	 * Updates a personal messaging rule for the given member.
	 *
	 * @param int $id_member
	 * @param int $id_rule
	 * @param string $ruleName
	 * @param string $criteria
	 * @param string $actions
	 * @param int $doDelete
	 * @param int $isOr
	 */
	public function updatePMRule($id_member, $id_rule, $ruleName, $criteria, $actions, $doDelete, $isOr)
	{
		$this->_db->query('', '
			UPDATE {db_prefix}pm_rules
			SET rule_name = {string:rule_name}, criteria = {string:criteria}, actions = {string:actions},
				delete_pm = {int:delete_pm}, is_or = {int:is_or}
			WHERE id_rule = {int:id_rule}
				AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'delete_pm' => $doDelete,
				'is_or' => $isOr,
				'id_rule' => $id_rule,
				'rule_name' => $ruleName,
				'criteria' => $criteria,
				'actions' => $actions,
			)
		);
	}

	/**
	 * Given the head PM, loads all other PM's that share the same head node
	 *
	 * - Used to load the conversation view of a PM
	 *
	 * @param int $head id of the head pm of the conversation
	 * @param mixed[] $recipients
	 * @param string $folder the current folder we are working in
	 */
	public function loadConversationList($head, &$recipients, $folder = '')
	{
		$request = $this->_db->query('', '
			SELECT
				pm.id_pm, pm.id_member_from, pm.deleted_by_sender, pmr.id_member, pmr.deleted
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
			WHERE pm.id_pm_head = {int:id_pm_head}
				AND ((pm.id_member_from = {int:current_member} AND pm.deleted_by_sender = {int:not_deleted})
					OR (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted}))
			ORDER BY pm.id_pm',
			array(
				'current_member' => $this->_member->id,
				'id_pm_head' => $head,
				'not_deleted' => 0,
			)
		);
		$display_pms = array();
		$posters = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			// This is, frankly, a joke. We will put in a workaround for people sending to themselves - yawn!
			if ($folder == 'sent' && $row['id_member_from'] == $this->_member->id && $row['deleted_by_sender'] == 1)
				continue;
			elseif (($row['id_member'] == $this->_member->id) && $row['deleted'] == 1)
				continue;

			if (!isset($recipients[$row['id_pm']]))
				$recipients[$row['id_pm']] = array(
					'to' => array(),
					'bcc' => array()
				);

			$display_pms[] = $row['id_pm'];
			$posters[$row['id_pm']] = $row['id_member_from'];
		}
		$this->_db->free_result($request);

		return array($display_pms, $posters);
	}

	/**
	 * Used to determine if any message in a conversation thread is unread
	 *
	 * - Returns array of keys with the head id and value details of the the newest
	 * unread message.
	 *
	 * @param int[] $pms array of pm ids to search
	 */
	public function loadConversationUnreadStatus($pms)
	{
		// Make it an array if its not
		if (!is_array($pms))
			$pms = array($pms);

		// Find the heads for this group of PM's
		$request = $this->_db->query('', '
			SELECT
				id_pm_head, id_pm
			FROM {db_prefix}personal_messages
			WHERE id_pm IN ({array_int:id_pm})',
			array(
				'id_pm' => $pms,
			)
		);
		$head_pms = array();
		while ($row = $this->_db->fetch_assoc($request))
			$head_pms[$row['id_pm_head']] = $row['id_pm'];
		$this->_db->free_result($request);

		// Find any unread PM's this member has under these head pm id's
		$request = $this->_db->query('', '
			SELECT
				MAX(pm.id_pm) AS id_pm, pm.id_member_from, pm.deleted_by_sender, pm.id_pm_head,
				pmr.id_member, pmr.deleted, pmr.is_read
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
			WHERE pm.id_pm_head IN ({array_int:id_pm_head})
				AND (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted})
				AND (pmr.is_read & 1 = 0)
			GROUP BY pm.id_pm_head',
			array(
				'current_member' => $this->_member->id,
				'id_pm_head' => array_keys($head_pms),
				'not_deleted' => 0,
			)
		);
		$unread_pms = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			// Return the results under the original index since thats what we are
			// displaying in the subject list
			$index = $head_pms[$row['id_pm_head']];
			$unread_pms[$index] = $row;
		}
		$this->_db->free_result($request);

		return $unread_pms;
	}

	/**
	 * Get all recipients for a given group of PM's, loads some basic member information for each
	 *
	 * - Will not include bcc-recipients for an inbox
	 * - Keeps track if a message has been replied / read
	 * - Tracks any message labels in use
	 * - If optional search parameter is set to true will return message first label, useful for linking
	 *
	 * @param int[] $all_pms
	 * @param mixed[] $recipients
	 * @param string $folder
	 * @param boolean $search
	 */
	public function loadPMRecipientInfo($all_pms, &$recipients, $folder = '', $search = false)
	{
		global $txt, $scripturl;

		// Get the recipients for all these PM's
		$request = $this->_db->query('', '
			SELECT
				pmr.id_pm, pmr.bcc, pmr.labels, pmr.is_read,
				mem_to.id_member AS id_member_to, mem_to.real_name AS to_name
			FROM {db_prefix}pm_recipients AS pmr
				LEFT JOIN {db_prefix}members AS mem_to ON (mem_to.id_member = pmr.id_member)
			WHERE pmr.id_pm IN ({array_int:pm_list})',
			array(
				'pm_list' => $all_pms,
			)
		);
		$message_labels = array();
		$message_replied = array();
		$message_unread = array();
		$message_first_label = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			// Sent folder recipients
			if ($folder === 'sent' || empty($row['bcc']))
				$recipients[$row['id_pm']][empty($row['bcc']) ? 'to' : 'bcc'][] = empty($row['id_member_to']) ? $txt['guest_title'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member_to'] . '">' . $row['to_name'] . '</a>';

			// Don't include bcc-recipients if its your inbox, you're not supposed to know :P
			if ($row['id_member_to'] == $this->_member->id && $folder !== 'sent')
			{
				// Read and replied to status for this message
				$message_replied[$row['id_pm']] = $row['is_read'] & 2;
				$message_unread[$row['id_pm']] = $row['is_read'] == 0;

				$row['labels'] = $row['labels'] == '' ? array() : explode(',', $row['labels']);
				foreach ($row['labels'] as $v)
				{
					if (isset($message_labels[(int) $v]))
						$message_labels[$row['id_pm']][(int) $v] = array('id' => $v, 'name' => $message_labels[(int) $v]['name']);

					// Here we find the first label on a message - used for linking to posts
					if ($search && (!isset($message_first_label[$row['id_pm']]) && !in_array('-1', $row['labels'])))
						$message_first_label[$row['id_pm']] = (int) $v;
				}
			}
		}
		$this->_db->free_result($request);

		return array($message_labels, $message_replied, $message_unread, ($search ? $message_first_label : ''));
	}

	/**
	 * This is used by preparePMContext_callback.
	 *
	 * - That function uses these query results and handles the free_result action as well.
	 *
	 * @param int[] $pms array of PM ids to fetch
	 * @param string[] $orderBy raw query defining how to order the results
	 */
	public function loadPMSubjectRequest($pms, $orderBy)
	{
		// Separate query for these bits!
		$subjects_request = $this->_db->query('', '
			SELECT
				pm.id_pm, pm.subject, pm.id_member_from, pm.msgtime, IFNULL(mem.real_name, pm.from_name) AS from_name,
				IFNULL(mem.id_member, 0) AS not_guest
			FROM {db_prefix}personal_messages AS pm
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
			WHERE pm.id_pm IN ({array_int:pm_list})
			ORDER BY ' . implode(', ', $orderBy) . '
			LIMIT ' . count($pms),
			array(
				'pm_list' => $pms,
			)
		);

		return $subjects_request;
	}

	/**
	 * Similar to loadSubjectRequest, this is used by preparePMContext_callback.
	 *
	 * - That function uses these query results and handles the free_result action as well.
	 *
	 * @param int[] $display_pms list of PM's to fetch
	 * @param string $sort_by_query raw query used in the sorting option
	 * @param string $sort_by used to signal when addition joins are needed
	 * @param boolean $descending if true descending order of display
	 * @param int|string $display_mode how are they being viewed, all, conversation, etc
	 * @param string $folder current pm folder
	 */
	public function loadPMMessageRequest($display_pms, $sort_by_query, $sort_by, $descending, $display_mode = '', $folder = '')
	{
		$messages_request = $this->_db->query('', '
			SELECT
				pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name
			FROM {db_prefix}personal_messages AS pm' . ($folder == 'sent' ? '
				LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') . ($sort_by == 'name' ? '
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})' : '') . '
			WHERE pm.id_pm IN ({array_int:display_pms})' . ($folder == 'sent' ? '
			GROUP BY pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name' : '') . '
			ORDER BY ' . ($display_mode == 2 ? 'pm.id_pm' : $sort_by_query) . ($descending ? ' DESC' : ' ASC') . '
			LIMIT ' . count($display_pms),
			array(
				'display_pms' => $display_pms,
				'id_member' => $folder == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
			)
		);

		return $messages_request;
	}

	/**
	 * Finds the number of results that a search would produce
	 *
	 * @param string $userQuery raw query, used if we are searching for specific users
	 * @param string $labelQuery raw query, used if we are searching only specific labels
	 * @param string $timeQuery raw query, used if we are limiting results to time periods
	 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
	 * @param mixed[] $searchq_parameters value parameters used in the above query
	 * @return integer
	 */
	public function numPMSeachResults($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters)
	{
		// Get the amount of results.
		$request = $this->_db->query('', '
			SELECT
				COUNT(*)
			FROM {db_prefix}pm_recipients AS pmr
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
			WHERE pm.id_member_from = {int:current_member}
				AND pm.deleted_by_sender = {int:not_deleted}
				' . $userQuery . $labelQuery . $timeQuery . '
				AND (' . $searchQuery . ')',
			array_merge($searchq_parameters, array(
				'current_member' => $this->_member->id,
				'not_deleted' => 0,
			))
		);
		list ($numResults) = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		return $numResults;
	}

	/**
	 * Gets all the matching message ids, senders and head pm nodes, using standard search only (No caching and the like!)
	 *
	 * @param string $userQuery raw query, used if we are searching for specific users
	 * @param string $labelQuery raw query, used if we are searching only specific labels
	 * @param string $timeQuery raw query, used if we are limiting results to time periods
	 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
	 * @param mixed[] $searchq_parameters value parameters used in the above query
	 * @param mixed[] $search_params additional search parameters, like sort and direction
	 */
	public function loadPMSearchMessages($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters, $search_params)
	{
		global $context, $modSettings;

		$request = $this->_db->query('', '
			SELECT
				pm.id_pm, pm.id_pm_head, pm.id_member_from
			FROM {db_prefix}pm_recipients AS pmr
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
			WHERE pm.id_member_from = {int:current_member}
				AND pm.deleted_by_sender = {int:not_deleted}
				' . $userQuery . $labelQuery . $timeQuery . '
				AND (' . $searchQuery . ')
			ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
			LIMIT ' . $context['start'] . ', ' . $modSettings['search_results_per_page'],
			array_merge($searchq_parameters, array(
				'current_member' => $this->_member->id,
				'not_deleted' => 0,
			))
		);
		$foundMessages = array();
		$posters = array();
		$head_pms = array();
		while ($row = $this->_db->fetch_assoc($request))
		{
			$foundMessages[] = $row['id_pm'];
			$posters[] = $row['id_member_from'];
			$head_pms[$row['id_pm']] = $row['id_pm_head'];
		}
		$this->_db->free_result($request);

		return array($foundMessages, $posters, $head_pms);
	}

	/**
	 * When we are in conversation view, we need to find the base head pm of the
	 * conversation.  This will set the root head id to each of the node heads
	 *
	 * @param int[] $head_pms array of pm ids that were found in the id_pm_head col
	 * during the initial search
	 */
	public function loadPMSearchHeads($head_pms)
	{
		$request = $this->_db->query('', '
			SELECT
				MAX(pm.id_pm) AS id_pm, pm.id_pm_head
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
			WHERE pm.id_pm_head IN ({array_int:head_pms})
				AND pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}
			GROUP BY pm.id_pm_head
			LIMIT {int:limit}',
			array(
				'head_pms' => array_unique($head_pms),
				'current_member' => $this->_member->id,
				'not_deleted' => 0,
				'limit' => count($head_pms),
			)
		);
		$real_pm_ids = array();
		while ($row = $this->_db->fetch_assoc($request))
			$real_pm_ids[$row['id_pm_head']] = $row['id_pm'];
		$this->_db->free_result($request);

		return $real_pm_ids;
	}

	/**
	 * Loads the actual details of the PM's that were found during the search stage
	 *
	 * @param int[] $foundMessages array of found message id's
	 * @param mixed[] $search_params as specified in the form, here used for sorting
	 */
	public function loadPMSearchResults($foundMessages, $search_params)
	{
		// Prepare the query for the callback!
		$request = $this->_db->query('', '
			SELECT
				pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name
			FROM {db_prefix}personal_messages AS pm
			WHERE pm.id_pm IN ({array_int:message_list})
			ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
			LIMIT ' . count($foundMessages),
			array(
				'message_list' => $foundMessages,
			)
		);
		$search_results = array();
		while ($row = $this->_db->fetch_assoc($request))
			$search_results[] = $row;
		$this->_db->free_result($request);

		return $search_results;
	}
}