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

if (!defined('ELK'))
	die('No access...');

/**
 * Load the PM limits for each group or for a specified group
 *
 * @package PersonalMessage
 * @param int|false $id_group (optional) the id of a membergroup
 */
function loadPMLimits($id_group = false)
{
	$db = database();

	$request = $db->query('', '
		SELECT
			id_group, group_name, max_messages
		FROM {db_prefix}membergroups' . ($id_group ? '
		WHERE id_group = {int:id_group}' : '
		ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name'),
		array(
			'id_group' => $id_group,
			'newbie_group' => 4,
		)
	);
	$groups = array();
	while ($row = $db->fetch_assoc($request))
	{
		if ($row['id_group'] != 1)
			$groups[$row['id_group']] = $row;
	}
	$db->free_result($request);

	return $groups;
}