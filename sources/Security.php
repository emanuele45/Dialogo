<?php

/**
 * This file has the very important job of ensuring forum security.
 * This task includes banning and permissions, namely.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Release Candidate 1
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Check if the user is who he/she says he is.
 *
 * What it does:
 * - This function makes sure the user is who they claim to be by requiring a
 * password to be typed in every hour.
 * - This check can be turned on and off by the securityDisable setting.
 * - Uses the adminLogin() function of subs/Auth.subs.php if they need to login,
 * which saves all request (POST and GET) data.
 *
 * @param string $type = admin
 * @deprecated since 1.1
 */
function validateSession($type = 'admin')
{
	return Elk::$app->security->validateSession($type);
}

/**
 * Require a user who is logged in. (not a guest.)
 *
 * What it does:
 * - Checks if the user is currently a guest, and if so asks them to login with a message telling them why.
 * - Message is what to tell them when asking them to login.
 *
 * @param string $message = ''
 * @param boolean $is_fatal = true
 * @deprecated since 1.1
 */
function is_not_guest($message = '', $is_fatal = true)
{
	return Elk::$app->security->is_not_guest($message, $is_fatal);
}

/**
 * Apply restrictions for banned users. For example, disallow access.
 *
 * What it does:
 * - If the user is banned, it dies with an error.
 * - Caches this information for optimization purposes.
 * - Forces a recheck if force_check is true.
 *
 * @param bool $forceCheck = false
 * @deprecated since 1.1
 */
function is_not_banned($forceCheck = false)
{
	return Elk::$app->security->is_not_banned($forceCheck);
}

/**
 * Fix permissions according to ban status.
 *
 * What it does:
 * - Applies any states of banning by removing permissions the user cannot have.
 * @package Bans
 */
function banPermissions()
{
	global $user_info, $modSettings, $context;

	// Somehow they got here, at least take away all permissions...
	if (isset($_SESSION['ban']['cannot_access']))
		$user_info['permissions'] = array();
	// Okay, well, you can watch, but don't touch a thing.
	elseif (isset($_SESSION['ban']['cannot_post']) || (!empty($modSettings['warning_mute']) && $modSettings['warning_mute'] <= $user_info['warning']))
	{
		$denied_permissions = array(
			'pm_send',
			'calendar_post', 'calendar_edit_own', 'calendar_edit_any',
			'poll_post',
			'poll_add_own', 'poll_add_any',
			'poll_edit_own', 'poll_edit_any',
			'poll_lock_own', 'poll_lock_any',
			'poll_remove_own', 'poll_remove_any',
			'manage_attachments', 'manage_smileys', 'manage_boards', 'admin_forum', 'manage_permissions',
			'moderate_forum', 'manage_membergroups', 'manage_bans', 'send_mail', 'edit_news',
			'profile_identity_any', 'profile_extra_any', 'profile_title_any',
			'post_new', 'post_reply_own', 'post_reply_any',
			'delete_own', 'delete_any', 'delete_replies',
			'make_sticky',
			'merge_any', 'split_any',
			'modify_own', 'modify_any', 'modify_replies',
			'move_any',
			'send_topic',
			'lock_own', 'lock_any',
			'remove_own', 'remove_any',
			'post_unapproved_topics', 'post_unapproved_replies_own', 'post_unapproved_replies_any',
		);
		Template_Layers::getInstance()->addAfter('admin_warning', 'body');

		call_integration_hook('integrate_post_ban_permissions', array(&$denied_permissions));
		$user_info['permissions'] = array_diff($user_info['permissions'], $denied_permissions);
	}
	// Are they absolutely under moderation?
	elseif (!empty($modSettings['warning_moderate']) && $modSettings['warning_moderate'] <= $user_info['warning'])
	{
		// Work out what permissions should change...
		$permission_change = array(
			'post_new' => 'post_unapproved_topics',
			'post_reply_own' => 'post_unapproved_replies_own',
			'post_reply_any' => 'post_unapproved_replies_any',
			'post_attachment' => 'post_unapproved_attachments',
		);
		call_integration_hook('integrate_warn_permissions', array(&$permission_change));
		foreach ($permission_change as $old => $new)
		{
			if (!in_array($old, $user_info['permissions']))
				unset($permission_change[$old]);
			else
				$user_info['permissions'][] = $new;
		}
		$user_info['permissions'] = array_diff($user_info['permissions'], array_keys($permission_change));
	}

	// @todo Find a better place to call this? Needs to be after permissions loaded!
	// Finally, some bits we cache in the session because it saves queries.
	if (isset($_SESSION['mc']) && $_SESSION['mc']['time'] > $modSettings['settings_updated'] && $_SESSION['mc']['id'] == $user_info['id'])
		$user_info['mod_cache'] = $_SESSION['mc'];
	else
	{
		require_once(SUBSDIR . '/Auth.subs.php');
		rebuildModCache();
	}

	// Now that we have the mod cache taken care of lets setup a cache for the number of mod reports still open
	if (isset($_SESSION['rc']) && $_SESSION['rc']['time'] > $modSettings['last_mod_report_action'] && $_SESSION['rc']['id'] == $user_info['id'])
		$context['open_mod_reports'] = $_SESSION['rc']['reports'];
	elseif ($_SESSION['mc']['bq'] != '0=1')
	{
		require_once(SUBSDIR . '/Moderation.subs.php');
		recountOpenReports();
	}
	else
		$context['open_mod_reports'] = 0;
}

/**
 * Log a ban in the database.
 *
 * What it does:
 * - Log the current user in the ban logs.
 * - Increment the hit counters for the specified ban ID's (if any.)
 *
 * @package Bans
 * @param int[] $ban_ids = array()
 * @param string|null $email = null
 */
function log_ban($ban_ids = array(), $email = null)
{
	global $user_info;

	$db = database();

	// Don't log web accelerators, it's very confusing...
	if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
		return;

	$db->insert('',
		'{db_prefix}log_banned',
		array('id_member' => 'int', 'ip' => 'string-16', 'email' => 'string', 'log_time' => 'int'),
		array($user_info['id'], $user_info['ip'], ($email === null ? ($user_info['is_guest'] ? '' : $user_info['email']) : $email), time()),
		array('id_ban_log')
	);

	// One extra point for these bans.
	if (!empty($ban_ids))
		$db->query('', '
			UPDATE {db_prefix}ban_items
			SET hits = hits + 1
			WHERE id_ban IN ({array_int:ban_ids})',
			array(
				'ban_ids' => $ban_ids,
			)
		);
}

/**
 * Checks if a given email address might be banned.
 *
 * What it does:
 * - Check if a given email is banned.
 * - Performs an immediate ban if the turns turns out positive.
 *
 * @package Bans
 * @param string $email
 * @param string $restriction
 * @param string $error
 */
function isBannedEmail($email, $restriction, $error)
{
	global $txt;

	$db = database();

	// Can't ban an empty email
	if (empty($email) || trim($email) == '')
		return;

	// Let's start with the bans based on your IP/hostname/memberID...
	$ban_ids = isset($_SESSION['ban'][$restriction]) ? $_SESSION['ban'][$restriction]['ids'] : array();
	$ban_reason = isset($_SESSION['ban'][$restriction]) ? $_SESSION['ban'][$restriction]['reason'] : '';

	// ...and add to that the email address you're trying to register.
	$request = $db->query('', '
		SELECT bi.id_ban, bg.' . $restriction . ', bg.cannot_access, bg.reason
		FROM {db_prefix}ban_items AS bi
			INNER JOIN {db_prefix}ban_groups AS bg ON (bg.id_ban_group = bi.id_ban_group)
		WHERE {string:email} LIKE bi.email_address
			AND (bg.' . $restriction . ' = {int:cannot_access} OR bg.cannot_access = {int:cannot_access})
			AND (bg.expire_time IS NULL OR bg.expire_time >= {int:now})',
		array(
			'email' => $email,
			'cannot_access' => 1,
			'now' => time(),
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		if (!empty($row['cannot_access']))
		{
			$_SESSION['ban']['cannot_access']['ids'][] = $row['id_ban'];
			$_SESSION['ban']['cannot_access']['reason'] = $row['reason'];
		}
		if (!empty($row[$restriction]))
		{
			$ban_ids[] = $row['id_ban'];
			$ban_reason = $row['reason'];
		}
	}
	$db->free_result($request);

	// You're in biiig trouble.  Banned for the rest of this session!
	if (isset($_SESSION['ban']['cannot_access']))
	{
		log_ban($_SESSION['ban']['cannot_access']['ids']);
		$_SESSION['ban']['last_checked'] = time();

		fatal_error(sprintf($txt['your_ban'], $txt['guest_title']) . $_SESSION['ban']['cannot_access']['reason'], false);
	}

	if (!empty($ban_ids))
	{
		// Log this ban for future reference.
		log_ban($ban_ids, $email);
		fatal_error($error . $ban_reason, false);
	}
}

/**
 * Make sure the user's correct session was passed, and they came from here.
 *
 * What it does:
 * - Checks the current session, verifying that the person is who he or she should be.
 * - Also checks the referrer to make sure they didn't get sent here.
 * - Depends on the disableCheckUA setting, which is usually missing.
 * - Will check GET, POST, or REQUEST depending on the passed type.
 * - Also optionally checks the referring action if passed. (note that the referring action must be by GET.)
 *
 * @param string $type = 'post' (post, get, request)
 * @param string $from_action = ''
 * @param bool $is_fatal = true
 * @return string the error message if is_fatal is false.
 * @deprecated since 1.1
 */
function checkSession($type = 'post', $from_action = '', $is_fatal = true)
{
	return Elk::$app->security->checkSession($type, $from_action, $is_fatal);
}

/**
 * Check if a specific confirm parameter was given.
 *
 * @param string $action
 * @deprecated since 1.1
 */
function checkConfirm($action)
{
	return Elk::$app->security->checkConfirm($action);
}

/**
 * Lets give you a token of our appreciation.
 *
 * @param string $action
 * @param string $type = 'post'
 * @return string[] array of token var and token
 * @deprecated since 1.1
 */
function createToken($action, $type = 'post')
{
	return Elk::$app->security->createToken($action, $type);
}

/**
 * Only patrons with valid tokens can ride this ride.
 *
 * @param string $action
 * @param string $type = 'post' (get, request, or post)
 * @param bool $reset = true
 * @param bool $fatal if true a fatal_lang_error is issued for invalid tokens, otherwise false is returned
 * @return boolean except for $action == 'login' where the token is returned
 * @deprecated since 1.1
 */
function validateToken($action, $type = 'post', $reset = true, $fatal = true)
{
	return Elk::$app->security->validateToken($action, $type, $reset, $fatal);
}

/**
 * Removes old unused tokens from session
 *
 * What it does:
 * - defaults to 3 hours before a token is considered expired
 * - if $complete = true will remove all tokens
 *
 * @param bool $complete = false
 * @param string $suffix = false
 * @deprecated since 1.1
 */
function cleanTokens($complete = false, $suffix = '')
{
	return Elk::$app->security->cleanTokens($complete, $suffix);
}

/**
 * Check whether a form has been submitted twice.
 *
 * What it does:
 * - Registers a sequence number for a form.
 * - Checks whether a submitted sequence number is registered in the current session.
 * - Depending on the value of is_fatal shows an error or returns true or false.
 * - Frees a sequence number from the stack after it's been checked.
 * - Frees a sequence number without checking if action == 'free'.
 *
 * @param string $action
 * @param bool $is_fatal = true
 * @deprecated since 1.1
 */
function checkSubmitOnce($action, $is_fatal = true)
{
	return Elk::$app->security->checkSubmitOnce($action, $is_fatal);
}

/**
 * This function checks whether the user is allowed to do permission. (ie. post_new.)
 *
 * What it does:
 * - If boards parameter is specified, checks those boards instead of the current one (if applicable).
 * - Always returns true if the user is an administrator.
 *
 * @param string[]|string $permission permission
 * @param int[]|int|null $boards array of board IDs, a single id or null
 * @return boolean if the user can do the permission
 * @deprecated since 1.1
 */
function allowedTo($permission, $boards = null)
{
	return Elk::$app->security->allowedTo($permission, $boards);
}

/**
 * This function returns fatal error if the user doesn't have the respective permission.
 *
 * What it does:
 * - Uses allowedTo() to check if the user is allowed to do permission.
 * - Checks the passed boards or current board for the permission.
 * - If they are not, it loads the Errors language file and shows an error using $txt['cannot_' . $permission].
 * - If they are a guest and cannot do it, this calls is_not_guest().
 *
 * @param string[]|string $permission array of or single string, of persmission to check
 * @param int[]|null $boards = null
 * @deprecated since 1.1
 */
function isAllowedTo($permission, $boards = null)
{
	return Elk::$app->security->isAllowedTo($permission, $boards);
}

/**
 * Return the boards a user has a certain (board) permission on. (array(0) if all.)
 *
 * What it does:
 * - returns a list of boards on which the user is allowed to do the specified permission.
 * - returns an array with only a 0 in it if the user has permission to do this on every board.
 * - returns an empty array if he or she cannot do this on any board.
 * - If check_access is true will also make sure the group has proper access to that board.
 *
 * @param string[]|string $permissions array of permission names to check access against
 * @param bool $check_access = true
 * @param bool $simple = true
 * @deprecated since 1.1
 */
function boardsAllowedTo($permissions, $check_access = true, $simple = true)
{
	return Elk::$app->security->boardsAllowedTo($permissions, $check_access, $simple);
}

/**
 * Returns whether an email address should be shown and how.
 *
 * Possible outcomes are:
 * - 'yes': show the full email address
 * - 'yes_permission_override': show the full email address, either you
 * are a moderator or it's your own email address.
 * - 'no_through_forum': don't show the email address, but do allow
 * things to be mailed using the built-in forum mailer.
 * - 'no': keep the email address hidden.
 *
 * @param bool $userProfile_hideEmail
 * @param int $userProfile_id
 * @return string (yes, yes_permission_override, no_through_forum, no)
 */
function showEmailAddress($userProfile_hideEmail, $userProfile_id)
{
	global $user_info;

	// Should this user's email address be shown?
	// If you're guest: no.
	// If the user is post-banned: no.
	// If it's your own profile and you've not set your address hidden: yes_permission_override.
	// If you're a moderator with sufficient permissions: yes_permission_override.
	// If the user has set their profile to do not email me: no.
	// Otherwise: no_through_forum. (don't show it but allow emailing the member)

	if ($user_info['is_guest'] || isset($_SESSION['ban']['cannot_post']))
		return 'no';
	elseif ((!$user_info['is_guest'] && $user_info['id'] == $userProfile_id && !$userProfile_hideEmail))
		return 'yes_permission_override';
	elseif (allowedTo('moderate_forum'))
		return 'yes_permission_override';
	elseif ($userProfile_hideEmail)
		return 'no';
	else
		return 'no_through_forum';
}

/**
 * This function attempts to protect from spammed messages and the like.
 *
 * - The time taken depends on error_type - generally uses the modSetting.
 *
 * @param string $error_type used also as a $txt index. (not an actual string.)
 * @param boolean $fatal is the spam check a fatal error on failure
 * @deprecated since 1.1
 */
function spamProtection($error_type, $fatal = true)
{
	return Elk::$app->security->spamProtection($error_type, $fatal);
}

/**
 * A generic function to create a pair of index.php and .htaccess files in a directory
 *
 * @param string $path the (absolute) directory path
 * @param boolean $attachments if the directory is an attachments directory or not
 * @return string|boolean on success error string if anything fails
 */
function secureDirectory($path, $attachments = false)
{
	if (empty($path))
		return 'empty_path';

	if (!is_writable($path))
		return 'path_not_writable';

	$directoryname = basename($path);

	$errors = array();
	$close = empty($attachments) ? '
</Files>' : '
	Allow from localhost
</Files>

RemoveHandler .php .php3 .phtml .cgi .fcgi .pl .fpl .shtml';

	if (file_exists($path . '/.htaccess'))
		$errors[] = 'htaccess_exists';
	else
	{
		$fh = @fopen($path . '/.htaccess', 'w');
		if ($fh)
		{
			fwrite($fh, '<Files *>
	Order Deny,Allow
	Deny from all' . $close);
			fclose($fh);
		}
		$errors[] = 'htaccess_cannot_create_file';
	}

	if (file_exists($path . '/index.php'))
		$errors[] = 'index-php_exists';
	else
	{
		$fh = @fopen($path . '/index.php', 'w');
		if ($fh)
		{
			fwrite($fh, '<?php

/**
 * This file is here solely to protect your ' . $directoryname . ' directory.
 */

// Look for Settings.php....
if (file_exists(dirname(dirname(__FILE__)) . \'/Settings.php\'))
{
	// Found it!
	require(dirname(dirname(__FILE__)) . \'/Settings.php\');
	header(\'Location: \' . $boardurl);
}
// Can\'t find it... just forget it.
else
	exit;');
			fclose($fh);
		}
		$errors[] = 'index-php_cannot_create_file';
	}

	if (!empty($errors))
		return $errors;
	else
		return true;
}

/**
 * Helper function that puts together a ban query for a given ip
 *
 * - Builds the query for ipv6, ipv4 or 255.255.255.255 depending on whats supplied
 *
 * @param string $fullip An IP address either IPv6 or not
 * @return string A SQL condition
 */
function constructBanQueryIP($fullip)
{
	// First attempt a IPv6 address.
	if (isValidIPv6($fullip))
	{
		$ip_parts = convertIPv6toInts($fullip);

		$ban_query = '((' . $ip_parts[0] . ' BETWEEN bi.ip_low1 AND bi.ip_high1)
			AND (' . $ip_parts[1] . ' BETWEEN bi.ip_low2 AND bi.ip_high2)
			AND (' . $ip_parts[2] . ' BETWEEN bi.ip_low3 AND bi.ip_high3)
			AND (' . $ip_parts[3] . ' BETWEEN bi.ip_low4 AND bi.ip_high4)
			AND (' . $ip_parts[4] . ' BETWEEN bi.ip_low5 AND bi.ip_high5)
			AND (' . $ip_parts[5] . ' BETWEEN bi.ip_low6 AND bi.ip_high6)
			AND (' . $ip_parts[6] . ' BETWEEN bi.ip_low7 AND bi.ip_high7)
			AND (' . $ip_parts[7] . ' BETWEEN bi.ip_low8 AND bi.ip_high8))';
	}
	// Check if we have a valid IPv4 address.
	elseif (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $fullip, $ip_parts) == 1)
		$ban_query = '((' . $ip_parts[1] . ' BETWEEN bi.ip_low1 AND bi.ip_high1)
			AND (' . $ip_parts[2] . ' BETWEEN bi.ip_low2 AND bi.ip_high2)
			AND (' . $ip_parts[3] . ' BETWEEN bi.ip_low3 AND bi.ip_high3)
			AND (' . $ip_parts[4] . ' BETWEEN bi.ip_low4 AND bi.ip_high4))';
	// We use '255.255.255.255' for 'unknown' since it's not valid anyway.
	else
		$ban_query = '(bi.ip_low1 = 255 AND bi.ip_high1 = 255
			AND bi.ip_low2 = 255 AND bi.ip_high2 = 255
			AND bi.ip_low3 = 255 AND bi.ip_high3 = 255
			AND bi.ip_low4 = 255 AND bi.ip_high4 = 255)';

	return $ban_query;
}

/**
 * Decide if we are going to enable bad behavior scanning for this user
 *
 * What it does:
 * - Admins and Moderators get a free pass
 * - Optionally existing users with post counts over a limit are bypassed
 * - Others get a humane frisking
 */
function loadBadBehavior()
{
	global $modSettings, $user_info, $bb2_results;

	// Bad Behavior Enabled?
	if (!empty($modSettings['badbehavior_enabled']))
	{
		require_once(EXTDIR . '/bad-behavior/badbehavior-plugin.php');
		$bb_run = true;

		// We may want to give some folks a hallway pass
		if (!$user_info['is_guest'])
		{
			if (!empty($user_info['is_mod']) || !empty($user_info['is_admin']))
				$bb_run = false;
			elseif (!empty($modSettings['badbehavior_postcount_wl']) && $modSettings['badbehavior_postcount_wl'] < 0)
				$bb_run = false;
			elseif (!empty($modSettings['badbehavior_postcount_wl']) && $modSettings['badbehavior_postcount_wl'] > 0 && ($user_info['posts'] > $modSettings['badbehavior_postcount_wl']))
				$bb_run = false;
		}

		// Put on the sanitary gloves, its time for a patdown !
		if ($bb_run === true)
		{
			$bb2_results = bb2_start(bb2_read_settings());
			addInlineJavascript(bb2_insert_head());
		}
	}
}

/**
 * This protects against brute force attacks on a member's password.
 *
 * - Importantly, even if the password was right we DON'T TELL THEM!
 *
 * @param int $id_member
 * @param string|false $password_flood_value = false or string joined on |'s
 * @param boolean $was_correct = false
 * @deprecated since 1.1
 */
function validatePasswordFlood($id_member, $password_flood_value = false, $was_correct = false)
{
	return Elk::$app->security->validatePasswordFlood($id_member, $password_flood_value, $was_correct);
}

/**
 * This sets the X-Frame-Options header.
 *
 * @param string|null $override the frame option, defaults to deny.
 */
function frameOptionsHeader($override = null)
{
	global $modSettings;

	$option = 'SAMEORIGIN';

	if (is_null($override) && !empty($modSettings['frame_security']))
		$option = $modSettings['frame_security'];
	elseif (in_array($override, array('SAMEORIGIN', 'DENY')))
		$option = $override;

	// Don't bother setting the header if we have disabled it.
	if ($option == 'DISABLE')
		return;

	// Finally set it.
	header('X-Frame-Options: ' . $option);
}

/**
 * This adds additional security headers that may prevent browsers from doing something they should not
 *
 * - X-XSS-Protection header - This header enables the Cross-site scripting (XSS) filter
 * built into most recent web browsers. It's usually enabled by default, so the role of this
 * header is to re-enable the filter for this particular website if it was disabled by the user.
 * - X-Content-Type-Options header - It prevents the browser from doing MIME-type sniffing,
 * only IE and Chrome are honouring this header. This reduces exposure to drive-by download attacks
 * and sites serving user uploaded content that could be treated as executable or dynamic HTML files.
 *
 * @param boolean|null $override
 */
function securityOptionsHeader($override = null)
{
	if ($override !== true)
	{
		header('X-XSS-Protection: 1');
		header('X-Content-Type-Options: nosniff');
	}
}

/**
 * Stop browsers doing prefetching to prefetch pages.
 */
function stop_prefetching()
{
	if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
	{
		@ob_end_clean();
		header('HTTP/1.1 403 Forbidden');
		die;
	}
}