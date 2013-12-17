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
 * @version 1.0 Beta
 *
 * This file takes care of actions on topics:
 * lock/unlock a topic, sticky/unsticky it
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Topics Controller
 */
class Topic_Controller extends Action_Controller
{
	/**
	 * Entry point for this class (by default).
	 *
	 * @see Action_Controller::action_index()
	 */
	public function action_index()
	{
		// Call the right method, if it ain't done yet.
		// this is done by the dispatcher, so lets leave it alone...
		// we don't want to assume what it means if the user doesn't
		// send us a ?sa=, do we? (lock topics out of nowhere?)
		// Unless... we can printpage()
	}

	/**
	 * Locks a topic... either by way of a moderator or the topic starter.
	 * What this does:
	 *  - locks a topic, toggles between locked/unlocked/admin locked.
	 *  - only admins can unlock topics locked by other admins.
	 *  - requires the lock_own or lock_any permission.
	 *  - logs the action to the moderator log.
	 *  - returns to the topic after it is done.
	 *  - it is accessed via ?action=topic;sa=lock.
	*/
	public function action_lock()
	{
		global $topic, $user_info, $board;

		// Just quit if there's no topic to lock.
		if (empty($topic))
			fatal_lang_error('not_a_topic', false);

		checkSession('get');

		// Load up the helpers
		require_once(SUBSDIR . '/Notification.subs.php');
		require_once(SUBSDIR . '/Topic.subs.php');

		// Find out who started the topic and its lock status
		list ($starter, $locked) = topicStatus($topic);

		// Can you lock topics here, mister?
		$user_lock = !allowedTo('lock_any');

		if ($user_lock && $starter == $user_info['id'])
			isAllowedTo('lock_own');
		else
			isAllowedTo('lock_any');

		// Locking with high privileges.
		if ($locked == '0' && !$user_lock)
			$locked = '1';
		// Locking with low privileges.
		elseif ($locked == '0')
			$locked = '2';
		// Unlocking - make sure you don't unlock what you can't.
		elseif ($locked == '2' || ($locked == '1' && !$user_lock))
			$locked = '0';
		// You cannot unlock this!
		else
			fatal_lang_error('locked_by_admin', 'user');

		// Lock the topic!
		setTopicAttribute($topic, array('locked' => $locked));

		// If they are allowed a "moderator" permission, log it in the moderator log.
		if (!$user_lock)
			logAction($locked ? 'lock' : 'unlock', array('topic' => $topic, 'board' => $board));

		// Notify people that this topic has been locked?
		sendNotifications($topic, empty($locked) ? 'unlock' : 'lock');

		// Back to the topic!
		redirectexit('topic=' . $topic . '.' . $_REQUEST['start']);
	}

	/**
	 * Sticky a topic.
	 * Can't be done by topic starters - that would be annoying!
	 * What this does:
	 *  - stickies a topic - toggles between sticky and normal.
	 *  - requires the make_sticky permission.
	 *  - adds an entry to the moderator log.
	 *  - when done, sends the user back to the topic.
	 *  - accessed via ?action=topic;sa=sticky.
	 */
	public function action_sticky()
	{
		global $modSettings, $topic, $board;

		// Make sure the user can sticky it, and they are stickying *something*.
		isAllowedTo('make_sticky');

		// You shouldn't be able to (un)sticky a topic if the setting is disabled.
		if (empty($modSettings['enableStickyTopics']))
			fatal_lang_error('cannot_make_sticky', false);

		// You can't sticky a board or something!
		if (empty($topic))
			fatal_lang_error('not_a_topic', false);

		checkSession('get');

		// We need this for the sendNotifications() function.
		require_once(SUBSDIR . '/Notification.subs.php');

		// And Topic subs for topic attributes.
		require_once(SUBSDIR . '/Topic.subs.php');

		// Is this topic already stickied, or no?
		$is_sticky = topicAttribute($topic, 'sticky');

		// Toggle the sticky value.
		setTopicAttribute($topic, array('sticky' => (empty($is_sticky) ? 1 : 0)));

		// Log this sticky action - always a moderator thing.
		logAction(empty($is_sticky) ? 'sticky' : 'unsticky', array('topic' => $topic, 'board' => $board));

		// Notify people that this topic has been stickied?
		if (empty($is_sticky))
			sendNotifications($topic, 'sticky');

		// Take them back to the now stickied topic.
		redirectexit('topic=' . $topic . '.' . $_REQUEST['start']);
	}

	/**
	 * This function allows to move a topic, making sure to ask the moderator
	 * to give reason for topic move.
	 * It must be called with a topic specified. (that is, global $topic must
	 * be set... @todo fix this thing.)
	 * If the member is the topic starter requires the move_own permission,
	 * otherwise the move_any permission.
	 * Accessed via ?action=topic;sa=move.
	 *
	 * @uses the MoveTopic template, main sub-template.
	 */
	public function action_move()
	{
		global $txt, $topic, $user_info, $context, $language, $scripturl, $modSettings;

		if (empty($topic))
			fatal_lang_error('no_access', false);

		// Retrieve the basic topic information for whats being moved
		require_once(SUBSDIR . '/Topic.subs.php');
		$topic_info = getTopicInfo($topic, 'message');

		if (empty($topic_info))
			fatal_lang_error('topic_gone', false);

		$context['is_approved'] = $topic_info['approved'];
		$context['subject'] = $topic_info['subject'];

		// Can they see it - if not approved?
		if ($modSettings['postmod_active'] && !$context['is_approved'])
			isAllowedTo('approve_posts');

		// Are they allowed to actually move any topics or even their own?
		if (!allowedTo('move_any') && ($topic_info['id_member_started'] == $user_info['id'] && !allowedTo('move_own')))
			fatal_lang_error('cannot_move_any', false);

		loadTemplate('MoveTopic');

		// Get a list of boards this moderator can move to.
		require_once(SUBSDIR . '/Boards.subs.php');
		$context += getBoardList(array('use_permissions' => true, 'not_redirection' => true));

		// No boards?
		if (empty($context['categories']) || $context['num_boards'] == 1)
			fatal_lang_error('moveto_noboards', false);

		// Already used the function, let's set the selected board back to the last
		$last_moved_to = isset($_SESSION['move_to_topic']['move_to']) ? (int) $_SESSION['move_to_topic']['move_to'] : 0;
		if (!empty($last_moved_to))
		{
			foreach ($context['categories'] as $id => $values)
				if (isset($values['boards'][$last_moved_to]))
				{
					$context['categories'][$id]['boards'][$last_moved_to]['selected'] = true;
					break;
				}
		}
		$context['redirect_topic'] = isset($_SESSION['move_to_topic']['redirect_topic']) ? (int) $_SESSION['move_to_topic']['redirect_topic'] : 0;
		$context['redirect_expires'] = isset($_SESSION['move_to_topic']['redirect_expires']) ? (int) $_SESSION['move_to_topic']['redirect_expires'] : 0;

		$context['page_title'] = $txt['move_topic'];
		$context['sub_template'] = 'move_topic';

		$context['linktree'][] = array(
			'url' => $scripturl . '?topic=' . $topic . '.0',
			'name' => $context['subject'],
		);
		$context['linktree'][] = array(
			'url' => '#',
			'name' => $txt['move_topic'],
		);

		$context['back_to_topic'] = isset($_REQUEST['goback']);

		// Ugly !
		if ($user_info['language'] != $language)
		{
			loadLanguage('index', $language);
			$temp = $txt['movetopic_default'];
			loadLanguage('index');
			$txt['movetopic_default'] = $temp;
		}

		// We will need this
		moveTopicConcurrence();

		// Register this form and get a sequence number in $context.
		checkSubmitOnce('register');
	}

	/**
	 * Execute the move of a topic.
	 * It is called on the submit of action_movetopic.
	 * This function logs that topics have been moved in the moderation log.
	 * If the member is the topic starter requires the move_own permission,
	 * otherwise requires the move_any permission.
	 * Upon successful completion redirects to message index.
	 * Accessed via ?action=topic;sa=move2.
	 *
	 * @uses subs/Post.subs.php.
	 */
	public function action_move2()
	{
		global $txt, $board, $topic, $scripturl, $modSettings, $context;
		global $board, $language, $user_info;

		if (empty($topic))
			fatal_lang_error('no_access', false);

		// You can't choose to have a redirection topic and use an empty reason.
		if (isset($_POST['postRedirect']) && (!isset($_POST['reason']) || trim($_POST['reason']) == ''))
			fatal_lang_error('movetopic_no_reason', false);

		// You have to tell us were you are moving to
		if (!isset($_POST['toboard']))
			fatal_lang_error('movetopic_no_board', false);

		// We will need this
		require_once(SUBSDIR . '/Topic.subs.php');
		moveTopicConcurrence();

		// Make sure this form hasn't been submitted before.
		checkSubmitOnce('check');

		// Get the basic details on this topic
		$topic_info = getTopicInfo($topic);
		$context['is_approved'] = $topic_info['approved'];

		// Can they see it?
		if (!$context['is_approved'])
			isAllowedTo('approve_posts');

		// Can they move topics on this board?
		if (!allowedTo('move_any'))
		{
			if ($topic_info['id_member_started'] == $user_info['id'])
			{
				isAllowedTo('move_own');
				$boards = array_merge(boardsAllowedTo('move_own'), boardsAllowedTo('move_any'));
			}
			else
				isAllowedTo('move_any');
		}
		else
			$boards = boardsAllowedTo('move_any');

		// If this topic isn't approved don't let them move it if they can't approve it!
		if ($modSettings['postmod_active'] && !$context['is_approved'] && !allowedTo('approve_posts'))
		{
			// Only allow them to move it to other boards they can't approve it in.
			$can_approve = !empty($user_info['mod_cache']['ap']) ? $user_info['mod_cache']['ap'] : boardsAllowedTo('approve_posts');
			$boards = array_intersect($boards, $can_approve);
		}

		checkSession();
		require_once(SUBSDIR . '/Post.subs.php');
		require_once(SUBSDIR . '/Boards.subs.php');

		// The destination board must be numeric.
		$toboard = (int) $_POST['toboard'];

		// Make sure they can see the board they are trying to move to (and get whether posts count in the target board).
		$board_info = boardInfo($toboard, $topic);
		if (empty($board_info))
			fatal_lang_error('no_board');

		// Remember this for later.
		$_SESSION['move_to_topic'] = array(
			'move_to' => $toboard
		);

		// Rename the topic...
		if (isset($_POST['reset_subject'], $_POST['custom_subject']) && $_POST['custom_subject'] != '')
		{
			$custom_subject = strtr(Util::htmltrim(Util::htmlspecialchars($_POST['custom_subject'])), array("\r" => '', "\n" => '', "\t" => ''));

			// Keep checking the length.
			if (Util::strlen($custom_subject) > 100)
				$custom_subject = Util::substr($custom_subject, 0, 100);

			// If it's still valid move onwards and upwards.
			if ($custom_subject != '')
			{
				$all_messages = isset($_POST['enforce_subject']);
				if ($all_messages)
				{
					// Get a response prefix, but in the forum's default language.
					if (!isset($context['response_prefix']) && !($context['response_prefix'] = cache_get_data('response_prefix')))
					{
						if ($language === $user_info['language'])
							$context['response_prefix'] = $txt['response_prefix'];
						else
						{
							loadLanguage('index', $language, false);
							$context['response_prefix'] = $txt['response_prefix'];
							loadLanguage('index');
						}
						cache_put_data('response_prefix', $context['response_prefix'], 600);
					}

					topicSubject($topic_info, $custom_subject, $context['response_prefix'], $all_messages);
				}
				else
					topicSubject($topic_info, $custom_subject);

				// Fix the subject cache.
				updateStats('subject', $topic, $custom_subject);
			}
		}

		// Create a link to this in the old board.
		// @todo Does this make sense if the topic was unapproved before? I'd just about say so.
		if (isset($_POST['postRedirect']))
		{
			// Should be in the boardwide language.
			if ($user_info['language'] != $language)
				loadLanguage('index', $language);

			$reason = Util::htmlspecialchars($_POST['reason'], ENT_QUOTES);
			preparsecode($reason);

			// Add a URL onto the message.
			$reason = strtr($reason, array(
				$txt['movetopic_auto_board'] => '[url=' . $scripturl . '?board=' . $toboard . '.0]' . $board_info['name'] . '[/url]',
				$txt['movetopic_auto_topic'] => '[iurl]' . $scripturl . '?topic=' . $topic . '.0[/iurl]'
			));

			// auto remove this MOVED redirection topic in the future?
			$redirect_expires = !empty($_POST['redirect_expires']) ? ((int) ($_POST['redirect_expires'] * 60) + time()) : 0;

			// redirect to the MOVED topic from topic list?
			$redirect_topic = isset($_POST['redirect_topic']) ? $topic : 0;

			// And remember the last expiry period too.
			$_SESSION['move_to_topic']['redirect_topic'] = $redirect_topic;
			$_SESSION['move_to_topic']['redirect_expires'] = (int) $_POST['redirect_expires'];

			$msgOptions = array(
				'subject' => $txt['moved'] . ': ' . $board_info['subject'],
				'body' => $reason,
				'icon' => 'moved',
				'smileys_enabled' => 1,
			);

			$topicOptions = array(
				'board' => $board,
				'lock_mode' => 1,
				'mark_as_read' => true,
				'redirect_expires' => $redirect_expires,
				'redirect_topic' => $redirect_topic,
			);

			$posterOptions = array(
				'id' => $user_info['id'],
				'update_post_count' => empty($board_info['count_posts']),
			);
			createPost($msgOptions, $topicOptions, $posterOptions);
		}

		$board_from = boardInfo($board);

		if ($board_from['count_posts'] != $board_info['count_posts'])
		{
			$posters = postersCount($topic);

			foreach ($posters as $id_member => $posts)
			{
				// The board we're moving from counted posts, but not to.
				if (empty($board_from['count_posts']))
					updateMemberData($id_member, array('posts' => 'posts - ' . $posts));
				// The reverse: from didn't, to did.
				else
					updateMemberData($id_member, array('posts' => 'posts + ' . $posts));
			}
		}

		// Do the move (includes statistics update needed for the redirect topic).
		moveTopics($topic, $toboard);

		// Log that they moved this topic.
		if (!allowedTo('move_own') || $topic_info['id_member_started'] != $user_info['id'])
			logAction('move', array('topic' => $topic, 'board_from' => $board, 'board_to' => $toboard));

		// Notify people that this topic has been moved?
		require_once(SUBSDIR . '/Notification.subs.php');
		sendNotifications($topic, 'move');

		// Why not go back to the original board in case they want to keep moving?
		if (!isset($_REQUEST['goback']))
			redirectexit('board=' . $board . '.0');
		else
			redirectexit('topic=' . $topic . '.0');
	}

	/**
	 * Format a topic to be printer friendly.
	 * Must be called with a topic specified.
	 * Accessed via ?action=topic;sa=printpage.
	 *
	 * @uses Printpage template, main sub-template.
	 * @uses print_above/print_below later without the main layer.
	 */
	public function action_printpage()
	{
		global $topic, $txt, $scripturl, $context, $user_info;
		global $board_info, $modSettings, $settings;

		// Redirect to the boardindex if no valid topic id is provided.
		if (empty($topic))
			redirectexit();

		if (!empty($modSettings['disable_print_topic']))
		{
			unset($_REQUEST['action']);
			$context['theme_loaded'] = false;
			fatal_lang_error('feature_disabled', false);
		}

		require_once(SUBSDIR . '/Topic.subs.php');

		// Get the topic starter information.
		$topicinfo = getTopicInfo($topic, 'starter');

		$context['user']['started'] = $user_info['id'] == $topicinfo['id_member'] && !$user_info['is_guest'];

		// Whatever happens don't index this.
		$context['robot_no_index'] = true;
		$is_poll = $topicinfo['id_poll'] > 0 && $modSettings['pollMode'] == '1' && allowedTo('poll_view');

		// Redirect to the boardindex if no valid topic id is provided.
		if (empty($topicinfo))
			redirectexit();

		// @todo this code is almost the same as the one in Display.controller.php
		if ($is_poll)
		{
			loadLanguage('Post');
			require_once(SUBSDIR . '/Poll.subs.php');

			loadPollContext($topicinfo['id_poll']);
		}

		// Lets "output" all that info.
		loadTemplate('Printpage');
		$template_layers = Template_Layers::getInstance();
		$template_layers->removeAll();
		$template_layers->add('print');
		$context['sub_template'] = 'print_page';
		$context['board_name'] = $board_info['name'];
		$context['category_name'] = $board_info['cat']['name'];
		$context['poster_name'] = $topicinfo['poster_name'];
		$context['post_time'] = relativeTime($topicinfo['poster_time'], false);
		$context['parent_boards'] = array();
		foreach ($board_info['parent_boards'] as $parent)
			$context['parent_boards'][] = $parent['name'];

		// Split the topics up so we can print them.
		$context['posts'] = topicMessages($topic);
		$posts_id = array_keys($context['posts']);

		if (!isset($context['topic_subject']))
			$context['topic_subject'] = $context['posts'][min($posts_id)]['subject'];

		// Fetch attachments so we can print them if asked, enabled and allowed
		if (isset($_REQUEST['images']) && !empty($modSettings['attachmentEnable']) && allowedTo('view_attachments'))
		{
			require_once(SUBSDIR . '/Topic.subs.php');
			$context['printattach'] = messagesAttachments(array_keys($context['posts']));
		}

		// Set a canonical URL for this page.
		$context['canonical_url'] = $scripturl . '?topic=' . $topic . '.0';
	}
}