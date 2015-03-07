<?php

/**
 * Functions to support the profile options controller
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
 * @version 1.0.1
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Gets the member id's of added buddies
 *
 * - Will mention that a buddy has been added if that is enabled
 *
 * @param string[] $buddies
 * @param boolean $adding true when adding new buddies
 * @return int[]
 */
function getBuddiesID($buddies, $adding = true)
{
	global $modSettings;

	$db = database();

	// Find the id_member of the buddy(s).
	$request = $db->query('', '
		SELECT id_member
		FROM {db_prefix}members
		WHERE member_name IN ({array_string:buddies}) OR real_name IN ({array_string:buddies})
		LIMIT {int:count_new_buddies}',
		array(
			'buddies' => $buddies,
			'count_new_buddies' => count($buddies),
		)
	);

	// If we are mentioning buddies, then let them know who's their buddy.
	if ($adding && !empty($modSettings['mentions_enabled']) && !empty($modSettings['mentions_buddy']))
	{
		$mentions = new Mentions_Controller(new Event_Manager());
		$mentions->pre_dispatch();
	}

	// Add the new member(s) to the buddies array.
	$buddiesArray = array();
	while ($row = $db->fetch_assoc($request))
	{
		$buddiesArray[] = (int) $row['id_member'];

		// Let them know they have been added as a buddy
		if (isset($mentions))
		{
			// Set a mentions for our buddy.
			$mentions->setData(array(
				'id_member' => $row['id_member'],
				'type' => 'buddy',
				'id_msg' => 0,
			));
			$mentions->action_add();
		}
	}
	$db->free_result($request);

	return $buddiesArray;
}

/**
 * Load group details for all groups that a member can join
 *
 * @param int[] $groups
 * @param int $memID
 */
function loadMembergroupsJoin($groups, $memID)
{
	global $context;

	$db = database();

	// Get all the membergroups they can join.
	$request = $db->query('', '
		SELECT mg.id_group, mg.group_name, mg.description, mg.group_type, mg.online_color, mg.hidden,
			IFNULL(lgr.id_member, 0) AS pending
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}log_group_requests AS lgr ON (lgr.id_member = {int:selected_member} AND lgr.id_group = mg.id_group)
		WHERE (mg.id_group IN ({array_int:group_list})
			OR mg.group_type > {int:nonjoin_group_id})
			AND mg.min_posts = {int:min_posts}
			AND mg.id_group != {int:moderator_group}
		ORDER BY group_name',
		array(
			'group_list' => $groups,
			'selected_member' => $memID,
			'nonjoin_group_id' => 1,
			'min_posts' => -1,
			'moderator_group' => 3,
		)
	);
	// This beast will be our group holder.
	$groups = array(
		'member' => array(),
		'available' => array()
	);
	while ($row = $db->fetch_assoc($request))
	{
		// Can they edit their primary group?
		if (($row['id_group'] == $context['primary_group'] && $row['group_type'] > 1) || ($row['hidden'] != 2 && $context['primary_group'] == 0 && in_array($row['id_group'], $groups)))
			$context['can_edit_primary'] = true;

		// If they can't manage (protected) groups, and it's not publically joinable or already assigned, they can't see it.
		if (((!$context['can_manage_protected'] && $row['group_type'] == 1) || (!$context['can_manage_membergroups'] && $row['group_type'] == 0)) && $row['id_group'] != $context['primary_group'])
			continue;

		$groups[in_array($row['id_group'], $groups) ? 'member' : 'available'][$row['id_group']] = array(
			'id' => $row['id_group'],
			'name' => $row['group_name'],
			'desc' => $row['description'],
			'color' => $row['online_color'],
			'type' => $row['group_type'],
			'pending' => $row['pending'],
			'is_primary' => $row['id_group'] == $context['primary_group'],
			'can_be_primary' => $row['hidden'] != 2,
			// Anything more than this needs to be done through account settings for security.
			'can_leave' => $row['id_group'] != 1 && $row['group_type'] > 1 ? true : false,
		);
	}
	$db->free_result($request);

	return $groups;
}

/**
 * Checks if a given group ID is protected by admin only permissions
 *
 * @param int $group_id
 * @return int
 */
function checkMembergroupChange($group_id)
{
	$db = database();

	// Chck if non admin users are trying to promote themselves to admin.
	$request = $db->query('', '
		SELECT COUNT(permission)
		FROM {db_prefix}permissions
		WHERE id_group = {int:selected_group}
			AND permission = {string:admin_forum}
			AND add_deny = {int:not_denied}',
		array(
			'selected_group' => $group_id,
			'admin_forum' => 'admin_forum',
			'not_denied' => 1,
		)
	);
	list ($disallow) = $db->fetch_row($request);
	$db->free_result($request);

	return $disallow;
}

/**
 * Validates and logs a request by a member to join a group
 *
 * @param int $group_id
 * @param int $memID
 */
function logMembergroupRequest($group_id, $memID)
{
	$db = database();

	$request = $db->query('', '
		SELECT id_member
		FROM {db_prefix}log_group_requests
		WHERE id_member = {int:selected_member}
			AND id_group = {int:selected_group}',
		array(
			'selected_member' => $memID,
			'selected_group' => $group_id,
		)
	);
	$num = $db->num_rows($request);
	$db->free_result($request);

	// Log the request.
	if ($num != 0)
	{
		$db->insert('',
			'{db_prefix}log_group_requests',
			array(
				'id_member' => 'int', 'id_group' => 'int', 'time_applied' => 'int', 'reason' => 'string-65534',
			),
			array(
				$memID, $group_id, time(), $_POST['reason'],
			),
			array('id_request')
		);
	}

	return ($num != 0);
}