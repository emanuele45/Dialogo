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

class Personal_Message extends AbstractModel
{
	protected $_member = null;
	protected $_pm_id = 0;
	protected $_allowed_groups = null;
	protected $_disallowed_groups = null;

	public function __construct($pm_id, $member, $db)
	{
		parent::__construct($db);

		$this->_pm_id = (int) $pm_id;

		if ($member instanceof ValuesContainer)
			$this->_member = $member;
		elseif (is_array($member))
			$this->_member = new ValuesContainer($member);
		else
			throw new Elk_Exception('Errors.wrong_member_parameter');
	}

	public function getId()
	{
		return $this->_pm_id;
	}

	/**
	 * Loads information about the users personal message limit.
	 *
	 */
	public function loadLimits()
	{
		$message_limit = 0;

		if ($this->_member->is_admin)
			$message_limit = 0;
		elseif (($message_limit = cache_get_data('msgLimit__' . $this->_member->id, 360)) === null)
		{
			$request = $this->_db->query('', '
				SELECT
					MAX(max_messages) AS top_limit, MIN(max_messages) AS bottom_limit
				FROM {db_prefix}membergroups
				WHERE id_group IN ({array_int:users_groups})',
				array(
					'users_groups' => $this->_member->groups,
				)
			);
			list ($maxMessage, $minMessage) = $this->_db->fetch_row($request);
			$this->_db->free_result($request);

			$message_limit = $minMessage == 0 ? 0 : $maxMessage;

			// Save us doing it again!
			cache_put_data('msgLimit__' . $this->_member->id, $message_limit, 360);
		}

		return $message_limit;
	}

	/**
	 * Check if the PM is available to the current user.
	 *
	 * @return boolean|null
	 */
	public function isAccessible()
	{
		if (empty($this->_pm_id))
			return true;

		$request = $this->_db->query('', '
			SELECT id_pm
			FROM {db_prefix}pm_messages
			WHERE pm.id_pm = {int:id_pm}
				AND id_member = {int:id_current_member}',
			array(
				'id_pm' => $this->_pm_id,
				'id_current_member' => $this->_member->id,
			)
		);
		$num_rows = $this->_db->num_rows($request);
		$this->_db->free_result($request);

		return $num_rows !== 0;
	}

	/**
	 * Sends a personal message from the specified person to the specified people
	 * ($from defaults to the user)
	 *
	 * @param mixed[] $recipients - an array containing the arrays 'to' and 'bcc', both containing id_member's.
	 * @param string $subject - should have no slashes and no html entities
	 * @param string $message - should have no slashes and no html entities
	 * @param bool $store_outbox
	 * @param mixed[]|null $from - an array with the id, name, and username of the member.
	 * @param int $pm_head - the ID of the chain being replied to - if any.
	 * @return mixed[] an array with log entries telling how many recipients were successful and which recipients it failed to send to.
	 */
	public function sendpm($recipients, $subject, $message, $store_outbox = true, $from = null, $pm_head = 0)
	{
		global $scripturl, $txt, $language, $modSettings, $webmaster_email;

		$db = database();

		// Make sure the PM language file is loaded, we might need something out of it.
		loadLanguage('PersonalMessage');

		// Needed for our email and post functions
		require_once(SUBSDIR . '/Mail.subs.php');
		require_once(SUBSDIR . '/Post.subs.php');

		// Initialize log array.
		$log = array(
			'failed' => array(),
			'sent' => array()
		);

		if ($from === null)
			$from = array(
				'id' => $this->_member->id,
				'name' => $this->_member->name,
				'username' => $this->_member->username
			);
		// Probably not needed.  /me something should be of the typer.
		else
			$this->_member->name = $from['name'];

		// This is the one that will go in their inbox.
		$htmlmessage = Util::htmlspecialchars($message, ENT_QUOTES);
		preparsecode($htmlmessage);
		$htmlsubject = strtr(Util::htmlspecialchars($subject), array("\r" => '', "\n" => '', "\t" => ''));
		if (Util::strlen($htmlsubject) > 100)
			$htmlsubject = Util::substr($htmlsubject, 0, 100);

		// Make sure is an array
		if (!is_array($recipients))
			$recipients = array($recipients);

		// Integrated PMs
		call_integration_hook('integrate_personal_message', array(&$recipients, &$from, &$subject, &$message));

		// Get a list of usernames and convert them to IDs.
		$usernames = array();
		foreach ($recipients as $rec_type => $rec)
		{
			foreach ($rec as $id => $member)
			{
				if (!is_numeric($recipients[$rec_type][$id]))
				{
					$recipients[$rec_type][$id] = Util::strtolower(trim(preg_replace('/[<>&"\'=\\\]/', '', $recipients[$rec_type][$id])));
					$usernames[$recipients[$rec_type][$id]] = 0;
				}
			}
		}

		if (!empty($usernames))
		{
			$request = $db->query('pm_find_username', '
				SELECT
					id_member, member_name
				FROM {db_prefix}members
				WHERE ' . (defined('DB_CASE_SENSITIVE') ? 'LOWER(member_name)' : 'member_name') . ' IN ({array_string:usernames})',
				array(
					'usernames' => array_keys($usernames),
				)
			);
			while ($row = $db->fetch_assoc($request))
				if (isset($usernames[Util::strtolower($row['member_name'])]))
					$usernames[Util::strtolower($row['member_name'])] = $row['id_member'];
			$db->free_result($request);

			// Replace the usernames with IDs. Drop usernames that couldn't be found.
			foreach ($recipients as $rec_type => $rec)
			{
				foreach ($rec as $id => $member)
				{
					if (is_numeric($recipients[$rec_type][$id]))
						continue;

					if (!empty($usernames[$member]))
						$recipients[$rec_type][$id] = $usernames[$member];
					else
					{
						$log['failed'][$id] = sprintf($txt['pm_error_user_not_found'], $recipients[$rec_type][$id]);
						unset($recipients[$rec_type][$id]);
					}
				}
			}
		}

		// Make sure there are no duplicate 'to' members.
		$recipients['to'] = array_unique($recipients['to']);

		// Only 'bcc' members that aren't already in 'to'.
		$recipients['bcc'] = array_diff(array_unique($recipients['bcc']), $recipients['to']);

		// Combine 'to' and 'bcc' recipients.
		$all_to = array_merge($recipients['to'], $recipients['bcc']);

		// Check no-one will want it deleted right away!
		$request = $db->query('', '
			SELECT
				id_member, criteria, is_or
			FROM {db_prefix}pm_rules
			WHERE id_member IN ({array_int:to_members})
				AND delete_pm = {int:delete_pm}',
			array(
				'to_members' => $all_to,
				'delete_pm' => 1,
			)
		);
		$deletes = array();
		// Check whether we have to apply anything...
		while ($row = $db->fetch_assoc($request))
		{
			$criteria = unserialize($row['criteria']);

			// Note we don't check the buddy status, cause deletion from buddy = madness!
			$delete = false;
			foreach ($criteria as $criterium)
			{
				if (($criterium['t'] == 'mid' && $criterium['v'] == $from['id']) || ($criterium['t'] == 'gid' && in_array($criterium['v'], $this->_member->groups)) || ($criterium['t'] == 'sub' && strpos($subject, $criterium['v']) !== false) || ($criterium['t'] == 'msg' && strpos($message, $criterium['v']) !== false))
					$delete = true;
				// If we're adding and one criteria don't match then we stop!
				elseif (!$row['is_or'])
				{
					$delete = false;
					break;
				}
			}
			if ($delete)
				$deletes[$row['id_member']] = 1;
		}
		$db->free_result($request);

		// Load the membergroup message limits.
		static $message_limit_cache = array();
		if (!allowedTo('moderate_forum') && empty($message_limit_cache))
		{
			$request = $db->query('', '
				SELECT
					id_group, max_messages
				FROM {db_prefix}membergroups',
				array(
				)
			);
			while ($row = $db->fetch_assoc($request))
				$message_limit_cache[$row['id_group']] = $row['max_messages'];
			$db->free_result($request);
		}

		$request = $db->query('', '
			SELECT
				member_name, real_name, id_member, email_address, lngfile,
				pm_email_notify, personal_messages,' . (allowedTo('moderate_forum') ? ' 0' : '
				(receive_from = {int:admins_only}' . (empty($modSettings['enable_buddylist']) ? '' : ' OR
				(receive_from = {int:buddies_only} AND FIND_IN_SET({string:from_id}, buddy_list) = 0) OR
				(receive_from = {int:not_on_ignore_list} AND FIND_IN_SET({string:from_id}, pm_ignore_list) != 0)') . ')') . ' AS ignored,
				FIND_IN_SET({string:from_id}, buddy_list) != 0 AS is_buddy, is_activated,
				additional_groups, id_group, id_post_group
			FROM {db_prefix}members
			WHERE id_member IN ({array_int:recipients})
			ORDER BY lngfile
			LIMIT {int:count_recipients}',
			array(
				'not_on_ignore_list' => 1,
				'buddies_only' => 2,
				'admins_only' => 3,
				'recipients' => $all_to,
				'count_recipients' => count($all_to),
				'from_id' => $from['id'],
			)
		);
		$notifications = array();
		while ($row = $db->fetch_assoc($request))
		{
			// Don't do anything for members to be deleted!
			if (isset($deletes[$row['id_member']]))
				continue;

			// We need to know this members groups.
			$groups = explode(',', $row['additional_groups']);
			$groups[] = $row['id_group'];
			$groups[] = $row['id_post_group'];

			$message_limit = -1;

			// For each group see whether they've gone over their limit - assuming they're not an admin.
			if (!in_array(1, $groups))
			{
				foreach ($groups as $id)
				{
					if (isset($message_limit_cache[$id]) && $message_limit != 0 && $message_limit < $message_limit_cache[$id])
						$message_limit = $message_limit_cache[$id];
				}

				if ($message_limit > 0 && $message_limit <= $row['personal_messages'])
				{
					$log['failed'][$row['id_member']] = sprintf($txt['pm_error_data_limit_reached'], $row['real_name']);
					unset($all_to[array_search($row['id_member'], $all_to)]);
					continue;
				}

				// Do they have any of the allowed groups?
				if (!$this->groupsCanRead($groups))
				{
					$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
					unset($all_to[array_search($row['id_member'], $all_to)]);
					continue;
				}
			}

			// Note that PostgreSQL can return a lowercase t/f for FIND_IN_SET
			if (!empty($row['ignored']) && $row['ignored'] != 'f' && $row['id_member'] != $from['id'])
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_ignored_by_user'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}

			// If the receiving account is banned (>=10) or pending deletion (4), refuse to send the PM.
			if ($row['is_activated'] >= 10 || ($row['is_activated'] == 4 && !$this->_member->is_admin))
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}

			// Send a notification, if enabled - taking the buddy list into account.
			if (!empty($row['email_address']) && ($row['pm_email_notify'] == 1 || ($row['pm_email_notify'] > 1 && (!empty($modSettings['enable_buddylist']) && $row['is_buddy']))) && $row['is_activated'] == 1)
				$notifications[empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']][] = $row['email_address'];

			$log['sent'][$row['id_member']] = sprintf(isset($txt['pm_successfully_sent']) ? $txt['pm_successfully_sent'] : '', $row['real_name']);
		}
		$db->free_result($request);

		// Only 'send' the message if there are any recipients left.
		if (empty($all_to))
			return $log;

		// Track the pm count for our stats
		if (!empty($modSettings['trackStats']))
			trackStats(array('pm' => '+'));

		// Insert the message itself and then grab the last insert id.
		$db->insert('',
			'{db_prefix}personal_messages',
			array(
				'id_pm_head' => 'int', 'id_member_from' => 'int', 'deleted_by_sender' => 'int',
				'from_name' => 'string-255', 'msgtime' => 'int', 'subject' => 'string-255', 'body' => 'string-65534',
			),
			array(
				$pm_head, $from['id'], ($store_outbox ? 0 : 1),
				$from['username'], time(), $htmlsubject, $htmlmessage,
			),
			array('id_pm')
		);
		$id_pm = $db->insert_id('{db_prefix}personal_messages', 'id_pm');

		// Add the recipients.
		if (!empty($id_pm))
		{
			// If this is new we need to set it part of it's own conversation.
			if (empty($pm_head))
				$db->query('', '
					UPDATE {db_prefix}personal_messages
					SET id_pm_head = {int:id_pm_head}
					WHERE id_pm = {int:id_pm_head}',
					array(
						'id_pm_head' => $id_pm,
					)
				);

			// Some people think manually deleting personal_messages is fun... it's not. We protect against it though :)
			$db->query('', '
				DELETE FROM {db_prefix}pm_recipients
				WHERE id_pm = {int:id_pm}',
				array(
					'id_pm' => $id_pm,
				)
			);

			$insertRows = array();
			$to_list = array();
			foreach ($all_to as $to)
			{
				$insertRows[] = array($id_pm, $to, in_array($to, $recipients['bcc']) ? 1 : 0, isset($deletes[$to]) ? 1 : 0, 1);
				if (!in_array($to, $recipients['bcc']))
					$to_list[] = $to;
			}

			$db->insert('insert',
				'{db_prefix}pm_recipients',
				array(
					'id_pm' => 'int', 'id_member' => 'int', 'bcc' => 'int', 'deleted' => 'int', 'is_new' => 'int'
				),
				$insertRows,
				array('id_pm', 'id_member')
			);
		}

		$maillist = !empty($modSettings['maillist_enabled']) && !empty($modSettings['pbe_pm_enabled']);

		// If they have post by email enabled, override disallow_sendBody
		if (!$maillist && !empty($modSettings['disallow_sendBody']))
		{
			$message = '';
			censorText($subject);
		}
		else
		{
			require_once(SUBSDIR . '/Emailpost.subs.php');
			pbe_prepare_text($message, $subject);
		}

		$to_names = array();
		if (count($to_list) > 1)
		{
			require_once(SUBSDIR . '/Members.subs.php');
			$result = getBasicMemberData($to_list);
			foreach ($result as $row)
				$to_names[] = un_htmlspecialchars($row['real_name']);
		}

		$replacements = array(
			'SUBJECT' => $subject,
			'MESSAGE' => $message,
			'SENDER' => un_htmlspecialchars($from['name']),
			'READLINK' => $scripturl . '?action=pm;pmsg=' . $id_pm . '#msg' . $id_pm,
			'REPLYLINK' => $scripturl . '?action=pm;sa=send;f=inbox;pmsg=' . $id_pm . ';quote;u=' . $from['id'],
			'TOLIST' => implode(', ', $to_names),
		);

		// Select the right template
		$email_template = ($maillist && empty($modSettings['disallow_sendBody']) ? 'pbe_' : '') . 'new_pm' . (empty($modSettings['disallow_sendBody']) ? '_body' : '') . (!empty($to_names) ? '_tolist' : '');

		foreach ($notifications as $lang => $notification_list)
		{
			// Using maillist functionality
			if ($maillist)
			{
				$sender_details = query_sender_wrapper($from['id']);
				$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);

				// Add in the signature
				$replacements['SIGNATURE'] = $sender_details['signature'];

				// And off it goes, looking a bit more personal
				$mail = loadEmailTemplate($email_template, $replacements, $lang);
				$reference = !empty($pm_head) ? $pm_head : null;
				sendmail($notification_list, $mail['subject'], $mail['body'], $from['name'], 'p' . $id_pm, false, 2, null, true, $from_wrapper, $reference);
			}
			else
			{
				// Off the notification email goes!
				$mail = loadEmailTemplate($email_template, $replacements, $lang);
				sendmail($notification_list, $mail['subject'], $mail['body'], null, 'p' . $id_pm, false, 2, null, true);
			}
		}

		// Integrated After PMs
		call_integration_hook('integrate_personal_message_after', array(&$id_pm, &$log, &$recipients, &$from, &$subject, &$message));

		// Back to what we were on before!
		loadLanguage('index+PersonalMessage');

		// Add one to their unread and read message counts.
		foreach ($all_to as $k => $id)
		{
			if (isset($deletes[$id]))
				unset($all_to[$k]);
		}

		if (!empty($all_to))
		{
			require_once(SUBSDIR . '/Members.subs.php');
			updateMemberData($all_to, array('personal_messages' => '+', 'unread_messages' => '+', 'new_pm' => 1));
		}

		return $log;
	}

	protected function groupsCanRead($groups)
	{
		if ($this->_disallowed_groups === null)
		{
			// Load the groups that are allowed to read PMs.
			// @todo move into a separate function on $permission?
			$this->_allowed_groups = array();
			$this->_disallowed_groups = array();
			$request = $db->query('', '
				SELECT
					id_group, add_deny
				FROM {db_prefix}permissions
				WHERE permission = {string:read_permission}',
				array(
					'read_permission' => 'pm_read',
				)
			);

			while ($row = $db->fetch_assoc($request))
			{
				if (empty($row['add_deny']))
					$this->_disallowed_groups[] = $row['id_group'];
				else
					$this->_allowed_groups[] = $row['id_group'];
			}

			if (empty($modSettings['permission_enable_deny']))
				$this->_disallowed_groups = array();
		}

		return count(array_intersect($this->_allowed_groups, $groups)) != 0 && count(array_intersect($this->_disallowed_groups, $groups)) == 0;
	}

	/**
	 * Used to set a replied status for a given PM.
	 *
	 * @param int $replied_to
	 */
	public function setRepliedStatus($replied_to)
	{
		$this->_db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET is_read = is_read | 2
			WHERE id_pm = {int:replied_to}
				AND id_member = {int:current_member}',
			array(
				'current_member' => $this->_member->id,
				'replied_to' => $replied_to,
			)
		);
	}

	/**
	 * Simple function to validate that a PM was sent to the current user
	 *
	 */
	public function isReceived()
	{
		$request = $this->_db->query('', '
			SELECT
				id_pm
			FROM {db_prefix}pm_recipients
			WHERE id_pm = {int:id_pm}
				AND id_member = {int:current_member}
			LIMIT 1',
			array(
				'current_member' => $this->_member->id,
				'id_pm' => $this->_pm_id,
			)
		);
		$isReceived = $this->_db->num_rows($request) != 0;
		$this->_db->free_result($request);

		return $isReceived;
	}

	/**
	 * Loads a pm by ID for use as a quoted pm in a new message
	 *
	 * @param boolean $isReceived
	 */
	public function loadQuote($isReceived)
	{
		// Get the quoted message (and make sure you're allowed to see this quote!).
		$request = $this->_db->query('', '
			SELECT
				pm.id_pm, CASE WHEN pm.id_pm_head = {int:id_pm_head_empty} THEN pm.id_pm ELSE pm.id_pm_head END AS pm_head,
				pm.body, pm.subject, pm.msgtime,
				mem.member_name, IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, pm.from_name) AS real_name
			FROM {db_prefix}personal_messages AS pm' . (!$isReceived ? '' : '
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = {int:id_pm})') . '
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
			WHERE pm.id_pm = {int:id_pm}' . (!$isReceived ? '
				AND pm.id_member_from = {int:current_member}' : '
				AND pmr.id_member = {int:current_member}') . '
			LIMIT 1',
			array(
				'current_member' => $this->_member->id,
				'id_pm_head_empty' => 0,
				'id_pm' => $this->_pm_id,
			)
		);
		$row_quoted = $this->_db->fetch_assoc($request);
		$this->_db->free_result($request);

		return empty($row_quoted) ? false : $row_quoted;
	}

	/**
	 * For a given PM ID, loads all "other" recipients, (excludes the current member)
	 *
	 * - Will optionally count the number of bcc recipients and return that count
	 *
	 * @param boolean $bcc_count
	 */
	public function getRecipients($bcc_count = false)
	{
		global $scripturl, $txt;

		if (empty($this->_pm_id))
			return array();

		$request = $this->_db->query('', '
			SELECT
				mem.id_member, mem.real_name, pmr.bcc
			FROM {db_prefix}pm_recipients AS pmr
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = pmr.id_member)
			WHERE pmr.id_pm = {int:id_pm}
				AND pmr.id_member != {int:current_member}' . ($bcc_count === true ? '' : '
				AND pmr.bcc = {int:not_bcc}'),
			array(
				'current_member' => $this->_member->id,
				'id_pm' => $this->_pm_id,
				'not_bcc' => 0,
			)
		);
		$recipients = array();
		$hidden_recipients = 0;
		while ($row = $this->_db->fetch_assoc($request))
		{
			// If it's hidden we still don't reveal their names
			if ($bcc_count && $row['bcc'])
				$hidden_recipients++;

			$recipients[] = array(
				'id' => $row['id_member'],
				'name' => htmlspecialchars($row['real_name'], ENT_COMPAT, 'UTF-8'),
				'link' => '[url=' . $scripturl . '?action=profile;u=' . $row['id_member'] . ']' . $row['real_name'] . '[/url]',
			);
		}

		// If bcc count was requested, we return the number of bcc members, but not the names
		if ($bcc_count)
			$recipients[] = array(
				'id' => 'bcc',
				'name' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients),
				'link' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients)
			);

		$this->_db->free_result($request);

		return $recipients;
	}

	/**
	 * Simply loads a personal message by ID
	 *
	 * - Supplied ID must have been sent to the user id requesting it and it must not have been deleted
	 *
	 */
	public function get()
	{
		// First, pull out the message contents, and verify it actually went to them!
		$request = $this->_db->query('', '
			SELECT
				pm.subject, pm.body, pm.msgtime, pm.id_member_from, IFNULL(m.real_name, pm.from_name) AS sender_name
			FROM {db_prefix}personal_messages AS pm
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
				LEFT JOIN {db_prefix}members AS m ON (m.id_member = pm.id_member_from)
			WHERE pm.id_pm = {int:id_pm}
				AND pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}
			LIMIT 1',
			array(
				'current_member' => $this->_member->id,
				'id_pm' => $this->_pm_id,
				'not_deleted' => 0,
			)
		);
		// Can only be a hacker here!
		if ($this->_db->num_rows($request) == 0)
			Errors::instance()->fatal_lang_error('no_access', false);
		$pm_details = $this->_db->fetch_row($request);
		$this->_db->free_result($request);

		return $pm_details;
	}
}