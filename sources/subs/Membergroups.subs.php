<?php

/**
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
 * @version 1.0 Alpha
 *
 * This file contains functions regarding manipulation of and information about membergroups.
 *
 */

if (!defined('ELKARTE'))
	die('No access...');

/**
 * Delete one of more membergroups.
 * Requires the manage_membergroups permission.
 * Returns true on success or false on failure.
 * Has protection against deletion of protected membergroups.
 * Deletes the permissions linked to the membergroup.
 * Takes members out of the deleted membergroups.
 * @param array $groups
 * @return boolean
 */
function deleteMembergroups($groups)
{
	global $smcFunc, $modSettings;

	// Make sure it's an array.
	if (!is_array($groups))
		$groups = array((int) $groups);
	else
	{
		$groups = array_unique($groups);

		// Make sure all groups are integer.
		foreach ($groups as $key => $value)
			$groups[$key] = (int) $value;
	}

	// Some groups are protected (guests, administrators, moderators, newbies).
	$protected_groups = array(-1, 0, 1, 3, 4);

	// There maybe some others as well.
	if (!allowedTo('admin_forum'))
	{
		$request = $smcFunc['db_query']('', '
			SELECT id_group
			FROM {db_prefix}membergroups
			WHERE group_type = {int:is_protected}',
			array(
				'is_protected' => 1,
			)
		);
		while ($row = $smcFunc['db_fetch_assoc']($request))
			$protected_groups[] = $row['id_group'];
		$smcFunc['db_free_result']($request);
	}

	// Make sure they don't delete protected groups!
	$groups = array_diff($groups, array_unique($protected_groups));
	if (empty($groups))
		return false;

	// Log the deletion.
	$groups_to_log = membergroupsById($groups, 0);
	foreach ($groups_to_log as $key => $row)
		logAction('delete_group', array('group' => $row['group_name']), 'admin');

	call_integration_hook('integrate_delete_membergroups', array($groups));

	// Remove the membergroups themselves.
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}membergroups
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Remove the permissions of the membergroups.
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}permissions
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}board_permissions
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}group_moderators
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Delete any outstanding requests.
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}log_group_requests
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
		)
	);

	// Update the primary groups of members.
	$smcFunc['db_query']('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_group}
		WHERE id_group IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
			'regular_group' => 0,
		)
	);

	// Update any inherited groups (Lose inheritance).
	$smcFunc['db_query']('', '
		UPDATE {db_prefix}membergroups
		SET id_parent = {int:uninherited}
		WHERE id_parent IN ({array_int:group_list})',
		array(
			'group_list' => $groups,
			'uninherited' => -2,
		)
	);

	// Update the additional groups of members.
	$request = $smcFunc['db_query']('', '
		SELECT id_member, additional_groups
		FROM {db_prefix}members
		WHERE FIND_IN_SET({raw:additional_groups_explode}, additional_groups) != 0',
		array(
			'additional_groups_explode' => implode(', additional_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	);
	$updates = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$updates[$row['additional_groups']][] = $row['id_member'];
	$smcFunc['db_free_result']($request);

	foreach ($updates as $additional_groups => $memberArray)
		updateMemberData($memberArray, array('additional_groups' => implode(',', array_diff(explode(',', $additional_groups), $groups))));

	// No boards can provide access to these membergroups anymore.
	$request = $smcFunc['db_query']('', '
		SELECT id_board, member_groups
		FROM {db_prefix}boards
		WHERE FIND_IN_SET({raw:member_groups_explode}, member_groups) != 0',
		array(
			'member_groups_explode' => implode(', member_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	);
	$updates = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$updates[$row['member_groups']][] = $row['id_board'];
	$smcFunc['db_free_result']($request);

	foreach ($updates as $member_groups => $boardArray)
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}boards
			SET member_groups = {string:member_groups}
			WHERE id_board IN ({array_int:board_lists})',
			array(
				'board_lists' => $boardArray,
				'member_groups' => implode(',', array_diff(explode(',', $member_groups), $groups)),
			)
		);

	// Recalculate the post groups, as they likely changed.
	updateStats('postgroups');

	// Make a note of the fact that the cache may be wrong.
	$settings_update = array('settings_updated' => time());
	// Have we deleted the spider group?
	if (isset($modSettings['spider_group']) && in_array($modSettings['spider_group'], $groups))
		$settings_update['spider_group'] = 0;

	updateSettings($settings_update);

	// It was a success.
	return true;
}

/**
 * Remove one or more members from one or more membergroups.
 * Requires the manage_membergroups permission.
 * Function includes a protection against removing from implicit groups.
 * Non-admins are not able to remove members from the admin group.
 * @param array $members
 * @param array $groups = null if groups is null, the specified members are stripped from all their membergroups.
 * @param bool $permissionCheckDone = false
 * @return boolean
 */
function removeMembersFromGroups($members, $groups = null, $permissionCheckDone = false)
{
	global $smcFunc, $modSettings;

	// You're getting nowhere without this permission, unless of course you are the group's moderator.
	if (!$permissionCheckDone)
		isAllowedTo('manage_membergroups');

	// Assume something will happen.
	updateSettings(array('settings_updated' => time()));

	// Cleaning the input.
	if (!is_array($members))
		$members = array((int) $members);
	else
	{
		$members = array_unique($members);

		// Cast the members to integer.
		foreach ($members as $key => $value)
			$members[$key] = (int) $value;
	}

	// Before we get started, let's check we won't leave the admin group empty!
	if ($groups === null || $groups == 1 || (is_array($groups) && in_array(1, $groups)))
	{
		$admins = array();
		listMembergroupMembers_Href($admins, 1);

		// Remove any admins if there are too many.
		$non_changing_admins = array_diff(array_keys($admins), $members);

		if (empty($non_changing_admins))
			$members = array_diff($members, array_keys($admins));
	}

	// Just in case.
	if (empty($members))
		return false;
	elseif ($groups === null)
	{
		// Wanna remove all groups from these members? That's easy.
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET
				id_group = {int:regular_member},
				additional_groups = {string:blank_string}
			WHERE id_member IN ({array_int:member_list})' . (allowedTo('admin_forum') ? '' : '
				AND id_group != {int:admin_group}
				AND FIND_IN_SET({int:admin_group}, additional_groups) = 0'),
			array(
				'member_list' => $members,
				'regular_member' => 0,
				'admin_group' => 1,
				'blank_string' => '',
			)
		);

		updateStats('postgroups', $members);

		// Log what just happened.
		foreach ($members as $member)
			logAction('removed_all_groups', array('member' => $member), 'admin');

		return true;
	}
	elseif (!is_array($groups))
		$groups = array((int) $groups);
	else
	{
		$groups = array_unique($groups);

		// Make sure all groups are integer.
		foreach ($groups as $key => $value)
			$groups[$key] = (int) $value;
	}

	// Fetch a list of groups members cannot be assigned to explicitely, and the group names of the ones we want.
	$implicitGroups = array(-1, 0, 3);
	$group_names = array();
	$group_details = membergroupsById($groups, 0, true);
	foreach ($group_details as $key => $row)
	{
		if ($row['min_posts'] != -1)
			$implicitGroups[] = $row['id_group'];
		else
			$group_names[$row['id_group']] = $row['group_name'];
	}

	// Now get rid of those groups.
	$groups = array_diff($groups, $implicitGroups);

	// Don't forget the protected groups.
	if (!allowedTo('admin_forum'))
	{
		$request = $smcFunc['db_query']('', '
			SELECT id_group
			FROM {db_prefix}membergroups
			WHERE group_type = {int:is_protected}',
			array(
				'is_protected' => 1,
			)
		);
		$protected_groups = array(1);
		while ($row = $smcFunc['db_fetch_assoc']($request))
			$protected_groups[] = $row['id_group'];
		$smcFunc['db_free_result']($request);

		// If you're not an admin yourself, you can't touch protected groups!
		$groups = array_diff($groups, array_unique($protected_groups));
	}

	// Only continue if there are still groups and members left.
	if (empty($groups) || empty($members))
		return false;

	// First, reset those who have this as their primary group - this is the easy one.
	$log_inserts = array();
	$request = $smcFunc['db_query']('', '
		SELECT id_member, id_group
		FROM {db_prefix}members AS members
		WHERE id_group IN ({array_int:group_list})
			AND id_member IN ({array_int:member_list})',
		array(
			'group_list' => $groups,
			'member_list' => $members,
		)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$log_inserts[] = array('group' => $group_names[$row['id_group']], 'member' => $row['id_member']);
	$smcFunc['db_free_result']($request);

	$smcFunc['db_query']('', '
		UPDATE {db_prefix}members
		SET id_group = {int:regular_member}
		WHERE id_group IN ({array_int:group_list})
			AND id_member IN ({array_int:member_list})',
		array(
			'group_list' => $groups,
			'member_list' => $members,
			'regular_member' => 0,
		)
	);

	// Those who have it as part of their additional group must be updated the long way... sadly.
	$request = $smcFunc['db_query']('', '
		SELECT id_member, additional_groups
		FROM {db_prefix}members
		WHERE (FIND_IN_SET({raw:additional_groups_implode}, additional_groups) != 0)
			AND id_member IN ({array_int:member_list})
		LIMIT ' . count($members),
		array(
			'member_list' => $members,
			'additional_groups_implode' => implode(', additional_groups) != 0 OR FIND_IN_SET(', $groups),
		)
	);
	$updates = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		// What log entries must we make for this one, eh?
		foreach (explode(',', $row['additional_groups']) as $group)
			if (in_array($group, $groups))
				$log_inserts[] = array('group' => $group_names[$group], 'member' => $row['id_member']);

		$updates[$row['additional_groups']][] = $row['id_member'];
	}
	$smcFunc['db_free_result']($request);

	foreach ($updates as $additional_groups => $memberArray)
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET additional_groups = {string:additional_groups}
			WHERE id_member IN ({array_int:member_list})',
			array(
				'member_list' => $memberArray,
				'additional_groups' => implode(',', array_diff(explode(',', $additional_groups), $groups)),
			)
		);

	// Their post groups may have changed now...
	updateStats('postgroups', $members);

	// Do the log.
	if (!empty($log_inserts) && !empty($modSettings['modlog_enabled']))
	{
		require_once(SOURCEDIR . '/Logging.php');
		foreach ($log_inserts as $extra)
			logAction('removed_from_group', $extra, 'admin');
	}

	// Mission successful.
	return true;
}

/**
 * Add one or more members to a membergroup
 *
 * Requires the manage_membergroups permission.
 * Function has protection against adding members to implicit groups.
 * Non-admins are not able to add members to the admin group.
 *
 * @param string|array $members
 * @param int $group
 * @param string $type = 'auto' specifies whether the group is added as primary or as additional group.
 * Supported types:
 * 	- only_primary      - Assigns a membergroup as primary membergroup, but only
 * 						  if a member has not yet a primary membergroup assigned,
 * 						  unless the member is already part of the membergroup.
 * 	- only_additional   - Assigns a membergroup to the additional membergroups,
 * 						  unless the member is already part of the membergroup.
 * 	- force_primary     - Assigns a membergroup as primary membergroup no matter
 * 						  what the previous primary membergroup was.
 * 	- auto              - Assigns a membergroup to the primary group if it's still
 * 						  available. If not, assign it to the additional group.
 * @param bool $permissionCheckDone
 * @return boolean success or failure
 */
function addMembersToGroup($members, $group, $type = 'auto', $permissionCheckDone = false)
{
	global $smcFunc, $modSettings;

	// Show your licence, but only if it hasn't been done yet.
	if (!$permissionCheckDone)
		isAllowedTo('manage_membergroups');

	// Make sure we don't keep old stuff cached.
	updateSettings(array('settings_updated' => time()));

	if (!is_array($members))
		$members = array((int) $members);
	else
	{
		$members = array_unique($members);

		// Make sure all members are integer.
		foreach ($members as $key => $value)
			$members[$key] = (int) $value;
	}
	$group = (int) $group;

	// Some groups just don't like explicitly having members.
	$implicitGroups = array(-1, 0, 3);
	$group_names = array();
	$group_details = membergroupsById($group, 1, true);
	if ($group_details['min_posts'] != -1)
		$implicitGroups[] = $group_details['id_group'];
	else
		$group_names[$group_details['id_group']] = $group_details['group_name'];

	// Sorry, you can't join an implicit group.
	if (in_array($group, $implicitGroups) || empty($members))
		return false;

	// Only admins can add admins...
	if (!allowedTo('admin_forum') && $group == 1)
		return false;
	// ... and assign protected groups!
	elseif (!allowedTo('admin_forum'))
	{
		$is_protected = membergroupsById($group);

		// Is it protected?
		if ($is_protected['group_type'] == 1)
			return false;
	}

	// Do the actual updates.
	if ($type == 'only_additional')
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET additional_groups = CASE WHEN additional_groups = {string:blank_string} THEN {string:id_group_string} ELSE CONCAT(additional_groups, {string:id_group_string_extend}) END
			WHERE id_member IN ({array_int:member_list})
				AND id_group != {int:id_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0',
			array(
				'member_list' => $members,
				'id_group' => $group,
				'id_group_string' => (string) $group,
				'id_group_string_extend' => ',' . $group,
				'blank_string' => '',
			)
		);
	elseif ($type == 'only_primary' || $type == 'force_primary')
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET id_group = {int:id_group}
			WHERE id_member IN ({array_int:member_list})' . ($type == 'force_primary' ? '' : '
				AND id_group = {int:regular_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0'),
			array(
				'member_list' => $members,
				'id_group' => $group,
				'regular_group' => 0,
			)
		);
	elseif ($type == 'auto')
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET
				id_group = CASE WHEN id_group = {int:regular_group} THEN {int:id_group} ELSE id_group END,
				additional_groups = CASE WHEN id_group = {int:id_group} THEN additional_groups
					WHEN additional_groups = {string:blank_string} THEN {string:id_group_string}
					ELSE CONCAT(additional_groups, {string:id_group_string_extend}) END
			WHERE id_member IN ({array_int:member_list})
				AND id_group != {int:id_group}
				AND FIND_IN_SET({int:id_group}, additional_groups) = 0',
			array(
				'member_list' => $members,
				'regular_group' => 0,
				'id_group' => $group,
				'blank_string' => '',
				'id_group_string' => (string) $group,
				'id_group_string_extend' => ',' . $group,
			)
		);
	// Ack!!?  What happened?
	else
		trigger_error('addMembersToGroup(): Unknown type \'' . $type . '\'', E_USER_WARNING);

	call_integration_hook('integrate_add_members_to_group', array($members, $group_details, &$group_names));

	// Update their postgroup statistics.
	updateStats('postgroups', $members);

	require_once(SOURCEDIR . '/Logging.php');
	foreach ($members as $member)
		logAction('added_to_group', array('group' => $group_names[$group], 'member' => $member), 'admin');

	return true;
}

/**
 * Gets the members of a supplied membergroup
 * Returns them as a link for display
 *
 * @param array &$members
 * @param int $membergroup
 * @param int $limit = null
 * @return boolean
 */
function listMembergroupMembers_Href(&$members, $membergroup, $limit = null)
{
	global $scripturl, $txt, $smcFunc;

	$request = $smcFunc['db_query']('', '
		SELECT id_member, real_name
		FROM {db_prefix}members
		WHERE id_group = {int:id_group} OR FIND_IN_SET({int:id_group}, additional_groups) != 0' . ($limit === null ? '' : '
		LIMIT ' . ($limit + 1)),
		array(
			'id_group' => $membergroup,
		)
	);
	$members = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$members[$row['id_member']] = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>';
	$smcFunc['db_free_result']($request);

	// If there are more than $limit members, add a 'more' link.
	if ($limit !== null && count($members) > $limit)
	{
		array_pop($members);
		return true;
	}
	else
		return false;
}

/**
 * Retrieve a list of (visible) membergroups used by the cache.
 *
 * @global type $scripturl
 * @global type $smcFunc
 * @return type
 */
function cache_getMembergroupList()
{
	global $scripturl, $smcFunc;

	$request = $smcFunc['db_query']('', '
		SELECT id_group, group_name, online_color
		FROM {db_prefix}membergroups
		WHERE min_posts = {int:min_posts}
			AND hidden = {int:not_hidden}
			AND id_group != {int:mod_group}
			AND online_color != {string:blank_string}
		ORDER BY group_name',
		array(
			'min_posts' => -1,
			'not_hidden' => 0,
			'mod_group' => 3,
			'blank_string' => '',
		)
	);
	$groupCache = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$groupCache[] = '<a href="' . $scripturl . '?action=groups;sa=members;group=' . $row['id_group'] . '" ' . ($row['online_color'] ? 'style="color: ' . $row['online_color'] . '"' : '') . '>' . $row['group_name'] . '</a>';
	$smcFunc['db_free_result']($request);

	return array(
		'data' => $groupCache,
		'expires' => time() + 3600,
		'refresh_eval' => 'return $GLOBALS[\'modSettings\'][\'settings_updated\'] > ' . time() . ';',
	);
}

/**
 * Helper function to generate a list of membergroups for display.
 *
 * @param int $start
 * @param int $items_per_page
 * @param string $sort
 * @param string $membergroup_type
 * @param int $user_id
 * @param bool $include_hidden
 * @param bool $include_all
 */
function list_getMembergroups($start, $items_per_page, $sort, $membergroup_type, $user_id, $include_hidden, $include_all = false)
{
	global $scripturl, $smcFunc;

	$groups = array();

	$request = $smcFunc['db_query']('', '
		SELECT mg.id_group, mg.group_name, mg.min_posts, mg.description, mg.group_type, mg.online_color, mg.hidden,
			mg.icons, IFNULL(gm.id_member, 0) AS can_moderate, 0 AS num_members
		FROM {db_prefix}membergroups AS mg
			LEFT JOIN {db_prefix}group_moderators AS gm ON (gm.id_group = mg.id_group AND gm.id_member = {int:current_member})
		WHERE mg.min_posts {raw:min_posts}' . ($include_all ? '' : '
			AND mg.id_group != {int:mod_group}
			AND mg.group_type != {int:is_protected}') . '
		ORDER BY {raw:sort}',
		array(
			'current_member' => $user_id,
			'min_posts' => ($membergroup_type === 'post_count' ? '!= ' : '= ') . -1,
			'mod_group' => 3,
			'is_protected' => 1,
			'sort' => $sort,
		)
	);

	// Start collecting the data.
	$groups = array();
	$group_ids = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		// We only list the groups they can see.
		if ($row['hidden'] && !$row['can_moderate'] && !$include_hidden)
			continue;

		$row['icons'] = explode('#', $row['icons']);

		$groups[$row['id_group']] = array(
			'id_group' => $row['id_group'],
			'group_name' => $row['group_name'],
			'min_posts' => $row['min_posts'],
			'desc' => $row['description'],
			'online_color' => $row['online_color'],
			'type' => $row['group_type'],
			'num_members' => $row['num_members'],
			'moderators' => array(),
			'icons' => $row['icons'],
		);

		$include_hidden |= $row['can_moderate'];
		$group_ids[] = $row['id_group'];
	}
	$smcFunc['db_free_result']($request);

	// If we found any membergroups, get the amount of members in them.
	if (!empty($group_ids))
	{
		if ($membergroup_type === 'post_count')
			$groups_count = membersInGroups($group_ids);
		else
			$groups_count = membersInGroups(array(), $group_ids, $include_hidden);

		// @todo not sure why += wouldn't = be enough?
		foreach ($groups_count as $group_id => $num_members)
			$groups[$group_id]['num_members'] += $num_members;

		$query = $smcFunc['db_query']('', '
			SELECT mods.id_group, mods.id_member, mem.member_name, mem.real_name
			FROM {db_prefix}group_moderators AS mods
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
			WHERE mods.id_group IN ({array_int:group_list})',
			array(
				'group_list' => $group_ids,
			)
		);
		while ($row = $smcFunc['db_fetch_assoc']($query))
			$groups[$row['id_group']]['moderators'][] = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>';
		$smcFunc['db_free_result']($query);
	}

	// Apply manual sorting if the 'number of members' column is selected.
	if (substr($sort, 0, 1) == '1' || strpos($sort, ', 1') !== false)
	{
		$sort_ascending = strpos($sort, 'DESC') === false;

		foreach ($groups as $group)
			$sort_array[] = $group['id_group'] != 3 ? (int) $group['num_members'] : -1;

		array_multisort($sort_array, $sort_ascending ? SORT_ASC : SORT_DESC, SORT_REGULAR, $groups);
	}

	return $groups;
}

/**
 * Count the number of members in specific groups
 *
 * @param array $postGroups an array of post-based groups id
 * @param array $normalGroups an array of normal groups id
 * @param bool $include_hidden if include hidden groups in the count (default false)
 * @param bool $include_moderators if include board moderators too (default false)
 */
function membersInGroups($postGroups, $normalGroups = array(), $include_hidden = false, $include_moderators = false)
{
	global $smcFunc;

	$groups = array();

	// If we have post groups, let's count the number of members...
	if (!empty($postGroups))
	{
		$query = $smcFunc['db_query']('', '
			SELECT id_post_group AS id_group, COUNT(*) AS member_count
			FROM {db_prefix}members
			WHERE id_post_group IN ({array_int:post_group_list})
			GROUP BY id_post_group',
			array(
				'post_group_list' => $postGroups,
			)
		);
		while ($row = $smcFunc['db_fetch_assoc']($query))
			$groups[$row['id_group']] = $row['member_count'];
		$smcFunc['db_free_result']($query);
	}

	if (!empty($normalGroups))
	{
		// Find people who are members of this group...
		$query = $smcFunc['db_query']('', '
			SELECT id_group, COUNT(*) AS member_count
			FROM {db_prefix}members
			WHERE id_group IN ({array_int:normal_group_list})
			GROUP BY id_group',
			array(
				'normal_group_list' => $normalGroups,
			)
		);
		while ($row = $smcFunc['db_fetch_assoc']($query))
			$groups[$row['id_group']] = $row['member_count'];
		$smcFunc['db_free_result']($query);

		// Only do additional groups if we can moderate...
		if ($include_hidden)
		{
			// Also do those who have it as an additional membergroup - this ones more yucky...
			$query = $smcFunc['db_query']('', '
				SELECT mg.id_group, COUNT(*) AS member_count
				FROM {db_prefix}membergroups AS mg
					INNER JOIN {db_prefix}members AS mem ON (mem.additional_groups != {string:blank_string}
						AND mem.id_group != mg.id_group
						AND FIND_IN_SET(mg.id_group, mem.additional_groups) != 0)
				WHERE mg.id_group IN ({array_int:normal_group_list})
				GROUP BY mg.id_group',
				array(
					'normal_group_list' => $normalGroups,
					'blank_string' => '',
				)
			);
			while ($row = $smcFunc['db_fetch_assoc']($query))
			{
				if (isset($groups[$row['id_group']]))
					$groups[$row['id_group']] += $row['member_count'];
				else
					$groups[$row['id_group']] = $row['member_count'];
			}
			$smcFunc['db_free_result']($query);
		}
	}

	if ($include_moderators)
	{
		// Any moderators?
		$request = $smcFunc['db_query']('', '
			SELECT COUNT(DISTINCT id_member) AS num_distinct_mods
			FROM {db_prefix}moderators
			LIMIT 1',
			array(
			)
		);
		list ($groups[3]) = $smcFunc['db_fetch_row']($request);
		$smcFunc['db_free_result']($request);
	}

	return $groups;
}

/**
 * Returns details of membergroups based on the id
 *
 * @param array/int $group_id the ID of the groups
 * @param integer $limit the number of results returned (default 1, if null/false/0 returns all)
 * @param array/string $detailed returns more fields default false, 
 *  - false returns: id_group, group_name, group_type, 
 *  - true adds to above: description, min_posts, online_color, max_messages, icons, hidden, id_parent
 * @param bool $assignable determine if the group is assignable or not and return that information
 * @param bool $protected include protected groups
 */
function membergroupsById($group_id, $limit = 1, $detailed = false, $assignable = false, $protected = false)
{
	global $smcFunc;

	if (!isset($group_id))
		return false;

	$group_ids = is_array($group_id) ? $group_id : array($group_id);

	$groups = array();
	$group_ids = array_map('intval', $group_ids);

	$request = $smcFunc['db_query']('', '
		SELECT id_group, group_name, group_type' . (!$detailed ? '' : ',
			description, min_posts, online_color, max_messages, icons, hidden, id_parent') . (!$assignable ? '' : ',
			CASE WHEN min_posts = {int:min_posts} THEN 1 ELSE 0 END AS assignable,
			CASE WHEN min_posts != {int:min_posts} THEN 1 ELSE 0 END AS is_post_group') . '
		FROM {db_prefix}membergroups
		WHERE id_group IN ({array_int:group_ids})' . ($protected ? '' : '
			AND group_type != {int:is_protected}') . (empty($limit) ? '' : '
		LIMIT {int:limit}'),
		array(
			'min_posts' => -1,
			'group_ids' => $group_ids,
			'limit' => $limit,
			'is_protected' => 1,
		)
	);

	if ($smcFunc['db_num_rows']($request) == 0)
		return $groups;

	while ($row = $smcFunc['db_fetch_assoc']($request))
		$groups[$row['id_group']] = $row;
	$smcFunc['db_free_result']($request);

	if (is_array($group_id))
		return $groups;
	else
		return $groups[$group_id];
}

/**
 * Gets basich membergroup data
 * type needs to be 'standard' or 'extended' 
 * - 'standard' lists all self created groups
 * - 'extended' lists all groups including the system groups such as admin or global moderator.
 *
 * @param string $type
 * @return type
 */
function getBasicMembergroupData($type = 'standard')
{
	global $smcFunc, $txt, $modSettings;

	$groups = array();
	
	switch ($type)
	{
		case 'standard':
			$request = $smcFunc['db_query']('', '
				SELECT id_group, group_name
				FROM {db_prefix}membergroups
				WHERE (id_group > {int:moderator_group} OR id_group = {int:global_mod_group})' . (empty($modSettings['permission_enable_postgroups']) ? '
					AND min_posts = {int:min_posts}' : '') . (allowedTo('admin_forum') ? '' : '
					AND group_type != {int:is_protected}') . '
				ORDER BY min_posts, id_group != {int:global_mod_group}, group_name',
				array(
					'moderator_group' => 3,
					'global_mod_group' => 2,
					'min_posts' => -1,
					'is_protected' => 1,
				)
			);
			break;

		case 'all':
			$request = $smcFunc['db_query']('', '
				SELECT id_group, group_name
				FROM {db_prefix}membergroups',
				array(
				)
			);
			$groups[] = array(
				'id' => 0,
				'name' => $txt['maintain_members_ungrouped']
			);
			break;
		default:
			trigger_error('getBasicMembergroupData(): Invalid group type \'' . $type . '\'', E_USER_NOTICE);
	}

	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$groups[] = array(
			'id' => $row['id_group'],
			'name' => $row['group_name']
		);
	}
	$smcFunc['db_free_result']($request);

	return $groups;
}

/**
 * Retrieves postgroups and membergroups from the membergroups table
 * except the moderator and the newbie group
 *
 * @todo: merge with getBasicMembergroupData();
 * @return array
 */
function retrieveMembergroups()
{
	global $smcFunc, $txt;

	$groups = array(
		'membergroups' => array(),
		'postgroups' => array(),
	);

	// Retrieving the membergroups and postgroups.
	$groups['membergroups'] = array(
		array(
			'id' => 0,
			'name' => $txt['membergroups_members'],
			'can_be_additional' => false
		)
	);

	$request = $smcFunc['db_query']('', '
		SELECT id_group, group_name, min_posts
		FROM {db_prefix}membergroups
		WHERE id_group != {int:moderator_group}
		ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name',
		array(
			'moderator_group' => 3,
			'newbie_group' => 4,
		)
	);
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		if ($row['min_posts'] == -1)
			$groups['membergroups'][] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name'],
				'can_be_additional' => true
			);
		else
			$groups['postgroups'][] = array(
				'id' => $row['id_group'],
				'name' => $row['group_name']
			);
		}
	$smcFunc['db_free_result']($request);

	return $groups;
}

/**
 * 
 * @todo: merge withe getBasicMembergroupData !!!
 */
function getMembergroups()
{
	global $smcFunc;

	$groups = array();

	$request = $smcFunc['db_query']('', '
		SELECT id_group, group_name
		FROM {db_prefix}membergroups
		WHERE id_group != {int:moderator_group}
			AND min_posts = {int:min_posts}',
		array(
			'moderator_group' => 3,
			'min_posts' => -1,
		)
	);

	while ($row = $smcFunc['db_fetch_assoc']($request))
		$groups[$row['id_group']] = $row['group_name'];
	$smcFunc['db_free_result']($request);

	return $groups;
}