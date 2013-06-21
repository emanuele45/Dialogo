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
 */

if (!defined('ELKARTE'))
	die('No access...');

/**
 * This controller is the most important and probably most accessed of all.
 * It controls topic display, with all related.
 */
class Display_Controller
{
	protected $_layers = null;
	protected $_topicinfo = null;
	private $_start = null;
	private $_virtual_msg = null;
	protected $_common_permissions = null;
	protected $_anyown_permissions = null;
	protected $_can_show_all = null;
	protected $_messages = null;
	protected $_includeUnapproved = null;
	protected $_messages_request = null;
	protected $_posters = null;
	protected $_firstIndex = null;

	/**
	 * Default action handler for this controller
	 */
	public function action_index()
	{
		// what to do... display things!
		$this->action_display();
	}

	protected function _init()
	{
		global $context, $topic;

		// What are you gonna display if these are empty?!
		if (empty($topic))
			fatal_lang_error('no_board', false);

		// Load the template
		loadTemplate('Display');

		// And the topic functions
		require_once(SUBSDIR . '/Topic.subs.php');

		// Not only does a prefetch make things slower for the server, but it makes it impossible to know if they read it.
		if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
		{
			ob_end_clean();
			header('HTTP/1.1 403 Prefetch Forbidden');
			die;
		}

		// Let's do some work on what to search index.
		if (count($_GET) > 2)
		{
			foreach ($_GET as $k => $v)
			{
				if (!in_array($k, array('topic', 'board', 'start', session_name())))
					$context['robot_no_index'] = true;
			}
		}

		if (!empty($_REQUEST['start']) && (!is_numeric($_REQUEST['start']) || $_REQUEST['start'] % $context['messages_per_page'] != 0))
			$context['robot_no_index'] = true;

		$this->_layers = Template_Layers::getInstance();

		// Now set all the wonderful, wonderful permissions... like moderation ones...
		$this->_common_permissions = array(
			'can_approve' => 'approve_posts',
			'can_ban' => 'manage_bans',
			'can_sticky' => 'make_sticky',
			'can_merge' => 'merge_any',
			'can_split' => 'split_any',
			'calendar_post' => 'calendar_post',
			'can_mark_notify' => 'mark_any_notify',
			'can_send_topic' => 'send_topic',
			'can_send_pm' => 'pm_send',
			'can_send_email' => 'send_email_to_members',
			'can_report_moderator' => 'report_any',
			'can_moderate_forum' => 'moderate_forum',
			'can_issue_warning' => 'issue_warning',
			'can_restore_topic' => 'move_any',
			'can_restore_msg' => 'move_any',
		);

		// Permissions with _any/_own versions.  $context[YYY] => ZZZ_any/_own.
		$this->_anyown_permissions = array(
			'can_move' => 'move',
			'can_lock' => 'lock',
			'can_delete' => 'remove',
			'can_add_poll' => 'poll_add',
			'can_remove_poll' => 'poll_remove',
			'can_reply' => 'post_reply',
			'can_reply_unapproved' => 'post_unapproved_replies',
		);

		// Did we report a post to a moderator just now?
		if (isset($_GET['reportsent']))
			$this->_layers->add('report_sent');

		// Let's get nosey, who is viewing this topic?
		if (!empty($settings['display_who_viewing']))
		{
			require_once(SUBSDIR . '/Who.subs.php');
			formatViewers($topic, 'topic');
		}
	}

	protected function _where_we_are()
	{
		global $context, $topic, $user_info, $board_info, $modSettings, $board;

		// Find the previous or next topic.  Make a fuss if there are no more.
		if (isset($_REQUEST['prev_next']) && ($_REQUEST['prev_next'] == 'prev' || $_REQUEST['prev_next'] == 'next'))
		{
			// No use in calculating the next topic if there's only one.
			if ($board_info['num_topics'] > 1)
			{
				$this->_includeUnapproved = (!$modSettings['postmod_active'] || allowedTo('approve_posts'));
				$includeStickies = !empty($modSettings['enableStickyTopics']);
				$topic = $_REQUEST['prev_next'] === 'prev' ? previousTopic($topic, $board, $user_info['id'], $this->_includeUnapproved, $includeStickies) : nextTopic($topic, $board, $user_info['id'], $this->_includeUnapproved, $includeStickies);
				$context['current_topic'] = $topic;
			}

			// Go to the newest message on this topic.
			$this->_start = 'new';
		}

		// Add 1 to the number of views of this topic (except for robots).
		if (!$user_info['possibly_robot'] && (empty($_SESSION['last_read_topic']) || $_SESSION['last_read_topic'] != $topic))
		{
			increaseViewCounter($topic);
			$_SESSION['last_read_topic'] = $topic;
		}
	}

	private function _where_we_go()
	{
		global $user_info, $context, $options, $topic, $modSettings;

		if ($this->_start !== null)
			$this->_start = $_REQUEST['start'];

		// The start isn't a number; it's information about what to do, where to go.
		if (!is_numeric($this->_start))
		{
			// Redirect to the page and post with new messages, originally by Omar Bazavilvazo.
			if ($this->_start == 'new')
			{
				// Guests automatically go to the last post.
				if ($user_info['is_guest'])
				{
					$context['start_from'] = $context['total_visible_posts'] - 1;
					$this->_start = empty($options['view_newest_first']) ? $context['start_from'] : 0;
				}
				else
				{
					// Fall through to the next if statement.
					$this->_start = 'msg' . $this->_topicinfo['new_from'];
				}
			}

			// Start from a certain time index, not a message.
			if (substr($this->_start, 0, 4) == 'from')
			{
				$timestamp = (int) substr($this->_start, 4);
				if ($timestamp === 0)
					$this->_start = 0;
				else
				{
					// Find the number of messages posted before said time...
					$context['start_from'] = countNewPosts($topic, $this->_topicinfo, $timestamp);
					// Handle view_newest_first options, and get the correct start value.
					$this->_start = empty($options['view_newest_first']) ? $context['start_from'] : $context['total_visible_posts'] - $context['start_from'] - 1;
				}
			}
			// Link to a message...
			elseif (substr($this->_start, 0, 3) == 'msg')
			{
				$this->_virtual_msg = (int) substr($this->_start, 3);
				if (!$this->_topicinfo['unapproved_posts'] && $this->_virtual_msg >= $this->_topicinfo['id_last_msg'])
					$context['start_from'] = $context['total_visible_posts'] - 1;
				elseif (!$this->_topicinfo['unapproved_posts'] && f <= $this->_topicinfo['id_first_msg'])
					$context['start_from'] = 0;
				else
				{
					$only_approved = $modSettings['postmod_active'] && $this->_topicinfo['unapproved_posts'] && !allowedTo('approve_posts');
					$context['start_from'] = countMessagesBefore($topic, $this->_virtual_msg, false, $only_approved, !$user_info['is_guest']);
				}

				// We need to reverse the start as well in this case.
				$this->_start = empty($options['view_newest_first']) ? $context['start_from'] : $context['total_visible_posts'] - $context['start_from'] - 1;
			}
		}

		// If all is set, but not allowed... just unset it.
		$this->_can_show_all = !empty($modSettings['enableAllMessages']) && $context['total_visible_posts'] > $context['messages_per_page'] && $context['total_visible_posts'] < $modSettings['enableAllMessages'];
		$this->_show_all = isset($_REQUEST['all']) && $this->_can_show_all;
		if ($this->_show_all)
			$this->_start = -1;

		// Construct the page index, allowing for the .START method...
		$context['page_index'] = constructPageIndex($scripturl . '?topic=' . $topic . '.%1$d', $this->_start, $context['total_visible_posts'], $context['messages_per_page'], true, array('all' => $this->_can_show_all, 'all_selected' => $this->_show_all));
		$context['start'] = $this->_start;

		// If they are viewing all the posts, show all the posts, otherwise limit the number.
		if ($this->_can_show_all && isset($_REQUEST['all']))
		{
			// No limit! (actually, there is a limit, but...)
			$context['messages_per_page'] = -1;

			// Set start back to 0...
			$this->_start = 0;
		}
	}

	protected function _prepareTopic()
	{
		global $context, $options, $user_info, $modSettings, $txt, $scripturl;
		global $topic, $board;

                // How much are we sticking on each page?
		$context['messages_per_page'] = empty($modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : $modSettings['defaultMaxMessages'];

		$topic_parameters = array(
			'member' => $user_info['id'],
			'topic' => $topic,
			'board' => $board,
		);
		$topic_selects = array();
		$topic_tables = array();
		call_integration_hook('integrate_display_topic', array(&$topic_selects, &$topic_tables, &$topic_parameters));

		// @todo Why isn't this cached?
		// @todo if we get id_board in this query and cache it, we can save a query on posting
		// Load the topic details
		$this->_topicinfo = getTopicInfo($topic_parameters, 'all', $topic_selects, $topic_tables);
		if (empty($this->_topicinfo))
			fatal_lang_error('not_a_topic', false);

		// Is this a moved topic that we are redirecting to?
		if (!empty($this->_topicinfo['id_redirect_topic']))
		{
			markTopicsRead(array($user_info['id'], $topic, $this->_topicinfo['id_last_msg'], 0), $this->_topicinfo['new_from'] !== 0);
			redirectexit('topic=' . $this->_topicinfo['id_redirect_topic'] . '.0');
		}

		$context['real_num_replies'] = $context['num_replies'] = $this->_topicinfo['num_replies'];
		$context['topic_first_message'] = $this->_topicinfo['id_first_msg'];
		$context['topic_last_message'] = $this->_topicinfo['id_last_msg'];
		$context['topic_disregarded'] = isset($this->_topicinfo['disregarded']) ? $this->_topicinfo['disregarded'] : 0;

		// Add up unapproved replies to get real number of replies...
		if ($modSettings['postmod_active'] && allowedTo('approve_posts'))
			$context['real_num_replies'] += $this->_topicinfo['unapproved_posts'] - ($this->_topicinfo['approved'] ? 0 : 1);

		// If this topic has unapproved posts, we need to work out how many posts the user can see, for page indexing.
		$this->_includeUnapproved = !$modSettings['postmod_active'] || allowedTo('approve_posts');
		if (!empty($this->_topicinfo['derived_from']))
		{
			require_once(SUBSDIR . '/FollowUps.subs.php');
			$context['topic_derived_from'] = topicStartedHere($topic, $this->_includeUnapproved);
		}

		if (!$this->_includeUnapproved && $this->_topicinfo['unapproved_posts'] && !$user_info['is_guest'])
		{
			$myUnapprovedPosts = unapprovedPosts($topic, $user_info['id']);

			$context['total_visible_posts'] = $context['num_replies'] + $myUnapprovedPosts + ($this->_topicinfo['approved'] ? 1 : 0);
		}
		elseif ($user_info['is_guest'])
			$context['total_visible_posts'] = $context['num_replies'] + ($this->_topicinfo['approved'] ? 1 : 0);
		else
			$context['total_visible_posts'] = $context['num_replies'] + $this->_topicinfo['unapproved_posts'] + ($this->_topicinfo['approved'] ? 1 : 0);

		// When was the last time this topic was replied to?  Should we warn them about it?
		require_once(SUBSDIR . '/Messages.subs.php');
		$mgsOptions = basicMessageInfo($this->_topicinfo['id_last_msg'], true);
		$context['oldTopicError'] = !empty($modSettings['oldTopicDays']) && $mgsOptions['poster_time'] + $modSettings['oldTopicDays'] * 86400 < time() && empty($this->_topicinfo['is_sticky']);

		// Information about the current topic...
		$context['is_locked'] = $this->_topicinfo['locked'];
		$context['is_sticky'] = $this->_topicinfo['is_sticky'];
		$context['is_very_hot'] = $this->_topicinfo['num_replies'] >= $modSettings['hotTopicVeryPosts'];
		$context['is_hot'] = $this->_topicinfo['num_replies'] >= $modSettings['hotTopicPosts'];
		$context['is_approved'] = $this->_topicinfo['approved'];

		// @todo Tricks? We don't want to show the poll icon in the topic class here, so pretend it's not one.
		$context['is_poll'] = false;
		determineTopicClass($context);

		$context['is_poll'] = $this->_topicinfo['id_poll'] > 0 && $modSettings['pollMode'] == '1' && allowedTo('poll_view');

		// Create a previous next string if the selected theme has it as a selected option.
		$context['previous_next'] = $modSettings['enablePreviousNext'] ? '<a href="' . $scripturl . '?topic=' . $topic . '.0;prev_next=prev#new">' . $txt['previous_next_back'] . '</a> - <a href="' . $scripturl . '?topic=' . $topic . '.0;prev_next=next#new">' . $txt['previous_next_forward'] . '</a>' : '';
		if (!empty($context['topic_derived_from']))
			$context['previous_next'] .= ' - <a href="' . $scripturl . '?msg=' . $context['topic_derived_from']['derived_from'] . '">' . sprintf($txt['topic_derived_from'], '<em>' . shorten_text($context['topic_derived_from']['subject'], !empty($modSettings['subject_length']) ? $modSettings['subject_length'] : 24)) . '</em></a>';

		// Is this topic sticky, or can it even be?
		$this->_topicinfo['is_sticky'] = empty($modSettings['enableStickyTopics']) ? '0' : $this->_topicinfo['is_sticky'];

		// Censor the title...
		censorText($this->_topicinfo['subject']);
		$context['page_title'] = $this->_topicinfo['subject'];

		// Default this topic to not marked for notifications... of course...
		$context['is_marked_notify'] = false;

		// Did this user start the topic or not?
		$context['user']['started'] = $user_info['id'] == $this->_topicinfo['id_member_started'] && !$user_info['is_guest'];
		$context['topic_starter_id'] = $this->_topicinfo['id_member_started'];

		// Set the topic's information for the template.
		$context['subject'] = $this->_topicinfo['subject'];
		$context['num_views'] = $this->_topicinfo['num_views'];
		$context['num_views_text'] = $context['num_views'] == 1 ? $txt['read_one_time'] : sprintf($txt['read_many_times'], $context['num_views']);
		$context['mark_unread_time'] = !empty($this->_virtual_msg) ? $this->_virtual_msg : $this->_topicinfo['new_from'];

		// Set a canonical URL for this page.
		$context['canonical_url'] = $scripturl . '?topic=' . $topic . '.' . $context['start'];

	}

	protected function _user_can()
	{
		global $context, $modSettings, $user_info, $board, $settings;

		if ($this->_common_permissions !== null)
			foreach ($this->_common_permissions as $contextual => $perm)
				$context[$contextual] = allowedTo($perm);

		if ($this->_anyown_permissions !== null)
			foreach ($this->_anyown_permissions as $contextual => $perm)
				$context[$contextual] = allowedTo($perm . '_any') || ($context['user']['started'] && allowedTo($perm . '_own'));

		// Cleanup all the permissions with extra stuff...
		$context['can_mark_notify'] &= !$context['user']['is_guest'];
		$context['can_sticky'] &= !empty($modSettings['enableStickyTopics']);
		$context['calendar_post'] &= !empty($modSettings['cal_enabled']);
		$context['can_add_poll'] &= $modSettings['pollMode'] == '1' && $this->_topicinfo['id_poll'] <= 0;
		$context['can_remove_poll'] &= $modSettings['pollMode'] == '1' && $this->_topicinfo['id_poll'] > 0;
		$context['can_reply'] &= empty($this->_topicinfo['locked']) || allowedTo('moderate_board');
		$context['can_reply_unapproved'] &= $modSettings['postmod_active'] && (empty($this->_topicinfo['locked']) || allowedTo('moderate_board'));
		$context['can_issue_warning'] &= in_array('w', $context['admin_features']) && !empty($modSettings['warning_enable']);

		// Handle approval flags...
		$context['can_reply_approved'] = $context['can_reply'];
		$context['can_reply'] |= $context['can_reply_unapproved'];
		$context['can_quote'] = $context['can_reply'] && (empty($modSettings['disabledBBC']) || !in_array('quote', explode(',', $modSettings['disabledBBC'])));
		$context['can_mark_unread'] = !$user_info['is_guest'] && $settings['show_mark_read'];
		$context['can_disregard'] = !$user_info['is_guest'] && $modSettings['enable_disregard'];
		$context['can_send_topic'] = (!$modSettings['postmod_active'] || $this->_topicinfo['approved']) && allowedTo('send_topic');
		$context['can_print'] = empty($modSettings['disable_print_topic']);

		// Start this off for quick moderation - it will be or'd for each post.
		$context['can_remove_post'] = allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']);

		// Can restore topic?  That's if the topic is in the recycle board and has a previous restore state.
		$context['can_restore_topic'] &= !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $board && !empty($this->_topicinfo['id_previous_board']);
		$context['can_restore_msg'] &= !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $board && !empty($this->_topicinfo['id_previous_topic']);

		$context['can_follow_up'] = boardsallowedto('post_new') !== array();
	}

	protected function _prepareEditor()
	{
		global $context, $modSettings, $user_info, $txt, $options, $language;
		global $board;

		// Check if spellchecking is both enabled and actually working. (for quick reply.)
		$context['show_spellchecking'] = !empty($modSettings['enableSpellChecking']) && function_exists('pspell_new');

		// Do we need to show the visual verification image?
		$context['require_verification'] = !$user_info['is_mod'] && !$user_info['is_admin'] && !empty($modSettings['posts_require_captcha']) && ($user_info['posts'] < $modSettings['posts_require_captcha'] || ($user_info['is_guest'] && $modSettings['posts_require_captcha'] == -1));
		if ($context['require_verification'])
		{
			require_once(SUBSDIR . '/Editor.subs.php');
			$verificationOptions = array(
				'id' => 'post',
			);
			$context['require_verification'] = create_control_verification($verificationOptions);
			$context['visual_verification_id'] = $verificationOptions['id'];
		}

		// Load up the "double post" sequencing magic.
		if (!empty($options['display_quick_reply']))
		{
			checkSubmitOnce('register');
			$context['name'] = isset($_SESSION['guest_name']) ? $_SESSION['guest_name'] : '';
			$context['email'] = isset($_SESSION['guest_email']) ? $_SESSION['guest_email'] : '';
			if (!empty($options['use_editor_quick_reply']) && $context['can_reply'])
			{
				// Needed for the editor and message icons.
				require_once(SUBSDIR . '/Editor.subs.php');

				// Now create the editor.
				$editorOptions = array(
					'id' => 'message',
					'value' => '',
					'labels' => array(
						'post_button' => $txt['post'],
					),
					// add height and width for the editor
					'height' => '250px',
					'width' => '100%',
					// We do XML preview here.
					'preview_type' => 0,
				);
				create_control_richedit($editorOptions);

				// Store the ID.
				$context['post_box_name'] = $editorOptions['id'];

				$context['attached'] = '';
				$context['make_poll'] = isset($_REQUEST['poll']);

				// Message icons - customized icons are off?
				$context['icons'] = getMessageIcons($board);

				if (!empty($context['icons']))
					$context['icons'][count($context['icons']) - 1]['is_last'] = true;
			}
		}

		// For quick reply we need a response prefix in the default forum language.
		if (!isset($context['response_prefix']) && !($context['response_prefix'] = cache_get_data('response_prefix', 600)))
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

	}

	/**
	 * The central part of the board - topic display.
	 * This function loads the posts in a topic up so they can be displayed.
	 * It uses the main sub template of the Display template.
	 * It requires a topic, and can go to the previous or next topic from it.
	 * It jumps to the correct post depending on a number/time/IS_MSG passed.
	 * It depends on the messages_per_page, defaultMaxMessages and enableAllMessages settings.
	 * It is accessed by ?topic=id_topic.START.
	 */
	public function action_display()
	{
		global $context, $settings;
		global $options, $topic;

		$this->_init();

		$this->_where_we_are();

		$this->_prepareTopic();

		$this->_where_we_go();

		$this->_prepareEditor();

		$this->_loadModerators();

		// If we want to show event information in the topic, prepare the data.
		$this->_prepareEvent();

		// Create the poll info if it exists.
		$this->_preparePoll();

		$this->_findMessages();
		$this->_findAttachments();

		// If there _are_ messages here... (probably an error otherwise :!)
		if (!empty($this->_messages))
		{
			$msg_parameters = array(
				'message_list' => $this->_messages,
				'new_from' => $this->_topicinfo['new_from'],
			);
			$msg_selects = array();
			$msg_tables = array();
			call_integration_hook('integrate_query_message', array(&$msg_selects, &$msg_tables, &$msg_parameters));

			// What?  It's not like it *couldn't* be only guests in this topic...
			if (!empty($this->_posters))
				loadMemberData($this->_posters);

			$this->_messages_request = loadMessageDetails($msg_selects, $msg_tables, $msg_parameters, $options);

			// Go to the last message if the given time is beyond the time of the last message.
			if (isset($context['start_from']) && $context['start_from'] >= $this->_topicinfo['num_replies'])
				$context['start_from'] = $this->_topicinfo['num_replies'];

			// Since the anchor information is needed on the top of the page we load these variables beforehand.
			$context['first_message'] = isset($this->_messages[$this->_firstIndex]) ? $this->_messages[$this->_firstIndex] : $this->_messages[0];
			if (empty($options['view_newest_first']))
				$context['first_new_message'] = isset($context['start_from']) && $this->_start == $context['start_from'];
			else
				$context['first_new_message'] = isset($context['start_from']) && $this->_start == $this->_topicinfo['num_replies'] - $context['start_from'];
		}
		else
		{
			$this->_messages_request = false;
			$context['first_message'] = 0;
			$context['first_new_message'] = false;
		}

		// Set the callback.  (do you REALIZE how much memory all the messages would take?!?)
		// This will be called from the template.
		$context['get_message'] = array($this, 'prepareDisplayContext_callback');

		$this->_user_can();

		$this->_setUpButtons();
	}

	protected function _prepareEvent()
	{
		global $modSettings, $user_info, $context, $scripturl;
		global $topic;

		if (allowedTo('calendar_view') && !empty($modSettings['cal_showInTopic']) && !empty($modSettings['cal_enabled']))
		{
			// We need events details and all that jazz
			require_once(SUBSDIR . '/Calendar.subs.php');

			// First, try create a better time format, ignoring the "time" elements.
			if (preg_match('~%[AaBbCcDdeGghjmuYy](?:[^%]*%[AaBbCcDdeGghjmuYy])*~', $user_info['time_format'], $matches) == 0 || empty($matches[0]))
				$date_string = $user_info['time_format'];
			else
				$date_string = $matches[0];

			// Get event information for this topic.
			$events = eventInfoForTopic($topic);

			$context['linked_calendar_events'] = array();
			foreach ($events as $event)
			{
				// Prepare the dates for being formatted.
				$start_date = sscanf($event['start_date'], '%04d-%02d-%02d');
				$start_date = mktime(12, 0, 0, $start_date[1], $start_date[2], $start_date[0]);
				$end_date = sscanf($event['end_date'], '%04d-%02d-%02d');
				$end_date = mktime(12, 0, 0, $end_date[1], $end_date[2], $end_date[0]);

				$context['linked_calendar_events'][] = array(
					'id' => $event['id_event'],
					'title' => $event['title'],
					'can_edit' => allowedTo('calendar_edit_any') || ($event['id_member'] == $user_info['id'] && allowedTo('calendar_edit_own')),
					'modify_href' => $scripturl . '?action=post;msg=' . $this->_topicinfo['id_first_msg'] . ';topic=' . $topic . '.0;calendar;eventid=' . $event['id_event'] . ';' . $context['session_var'] . '=' . $context['session_id'],
					'can_export' => allowedTo('calendar_edit_any') || ($event['id_member'] == $user_info['id'] && allowedTo('calendar_edit_own')),
					'export_href' => $scripturl . '?action=calendar;sa=ical;eventid=' . $event['id_event'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'start_date' => standardTime($start_date, $date_string, 'none'),
					'start_timestamp' => $start_date,
				'end_date' => standardTime($end_date, $date_string, 'none'),
					'end_timestamp' => $end_date,
					'is_last' => false
				);
			}

			if (!empty($context['linked_calendar_events']))
			{
				$context['linked_calendar_events'][count($context['linked_calendar_events']) - 1]['is_last'] = true;
				$this->_layers->add('display_calendar');
			}
		}
	}

	protected function _preparePoll()
	{
		global $scripturl, $txt, $context, $settings, $user_info;

		// Create the poll info if it exists.
		if ($context['is_poll'])
		{
			$this->_layers->add('display_poll');

			// Get information on the poll
			require_once(SUBSDIR . '/Poll.subs.php');
			$pollinfo = pollInfo($this->_topicinfo['id_poll']);

			// Get the poll options
			$pollOptions = pollOptionsForMember($this->_topicinfo['id_poll'], $user_info['id']);

			// Compute total votes.
			$realtotal = 0;
			$pollinfo['has_voted'] = false;
			foreach ($pollOptions as $choice)
			{
				$realtotal += $choice['votes'];
				$pollinfo['has_voted'] |= $choice['voted_this'] != -1;
			}

			// If this is a guest we need to do our best to work out if they have voted, and what they voted for.
			if ($user_info['is_guest'] && $pollinfo['guest_vote'] && allowedTo('poll_vote'))
			{
				if (!empty($_COOKIE['guest_poll_vote']) && preg_match('~^[0-9,;]+$~', $_COOKIE['guest_poll_vote']) && strpos($_COOKIE['guest_poll_vote'], ';' . $this->_topicinfo['id_poll'] . ',') !== false)
				{
					// ;id,timestamp,[vote,vote...]; etc
					$guestinfo = explode(';', $_COOKIE['guest_poll_vote']);

					// Find the poll we're after.
					foreach ($guestinfo as $i => $guestvoted)
					{
						$guestvoted = explode(',', $guestvoted);
						if ($guestvoted[0] == $this->_topicinfo['id_poll'])
							break;
					}

					// Has the poll been reset since guest voted?
					if ($pollinfo['reset_poll'] > $guestvoted[1])
					{
						// Remove the poll info from the cookie to allow guest to vote again
						unset($guestinfo[$i]);
						if (!empty($guestinfo))
							$_COOKIE['guest_poll_vote'] = ';' . implode(';', $guestinfo);
						else
							unset($_COOKIE['guest_poll_vote']);
					}
					else
					{
						// What did they vote for?
						unset($guestvoted[0], $guestvoted[1]);
						foreach ($pollOptions as $choice => $details)
						{
							$pollOptions[$choice]['voted_this'] = in_array($choice, $guestvoted) ? 1 : -1;
							$pollinfo['has_voted'] |= $pollOptions[$choice]['voted_this'] != -1;
						}
						unset($choice, $details, $guestvoted);
					}
					unset($guestinfo, $guestvoted, $i);
				}
			}

			// Set up the basic poll information.
			$context['poll'] = array(
				'id' => $this->_topicinfo['id_poll'],
				'image' => 'normal_' . (empty($pollinfo['voting_locked']) ? 'poll' : 'locked_poll'),
				'question' => parse_bbc($pollinfo['question']),
				'total_votes' => $pollinfo['total'],
				'change_vote' => !empty($pollinfo['change_vote']),
				'is_locked' => !empty($pollinfo['voting_locked']),
				'options' => array(),
				'lock' => allowedTo('poll_lock_any') || ($context['user']['started'] && allowedTo('poll_lock_own')),
				'edit' => allowedTo('poll_edit_any') || ($context['user']['started'] && allowedTo('poll_edit_own')),
				'allowed_warning' => $pollinfo['max_votes'] > 1 ? sprintf($txt['poll_options6'], min(count($pollOptions), $pollinfo['max_votes'])) : '',
				'is_expired' => !empty($pollinfo['expire_time']) && $pollinfo['expire_time'] < time(),
				'expire_time' => !empty($pollinfo['expire_time']) ? standardTime($pollinfo['expire_time']) : 0,
				'has_voted' => !empty($pollinfo['has_voted']),
				'starter' => array(
					'id' => $pollinfo['id_member'],
					'name' => $pollinfo['poster_name'],
					'href' => $pollinfo['id_member'] == 0 ? '' : $scripturl . '?action=profile;u=' . $pollinfo['id_member'],
					'link' => $pollinfo['id_member'] == 0 ? $pollinfo['poster_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $pollinfo['id_member'] . '">' . $pollinfo['poster_name'] . '</a>'
				)
			);

			// Make the lock and edit permissions defined above more directly accessible.
			$context['allow_lock_poll'] = $context['poll']['lock'];
			$context['allow_edit_poll'] = $context['poll']['edit'];

			// You're allowed to vote if:
			// 1. the poll did not expire, and
			// 2. you're either not a guest OR guest voting is enabled... and
			// 3. you're not trying to view the results, and
			// 4. the poll is not locked, and
			// 5. you have the proper permissions, and
			// 6. you haven't already voted before.
			$context['allow_vote'] = !$context['poll']['is_expired'] && (!$user_info['is_guest'] || ($pollinfo['guest_vote'] && allowedTo('poll_vote'))) && empty($pollinfo['voting_locked']) && allowedTo('poll_vote') && !$context['poll']['has_voted'];

			// You're allowed to view the results if:
			// 1. you're just a super-nice-guy, or
			// 2. anyone can see them (hide_results == 0), or
			// 3. you can see them after you voted (hide_results == 1), or
			// 4. you've waited long enough for the poll to expire. (whether hide_results is 1 or 2.)
			$context['allow_poll_view'] = allowedTo('moderate_board') || $pollinfo['hide_results'] == 0 || ($pollinfo['hide_results'] == 1 && $context['poll']['has_voted']) || $context['poll']['is_expired'];
			$context['poll']['show_results'] = $context['allow_poll_view'] && (isset($_REQUEST['viewresults']) || isset($_REQUEST['viewResults']));

			// You're allowed to change your vote if:
			// 1. the poll did not expire, and
			// 2. you're not a guest... and
			// 3. the poll is not locked, and
			// 4. you have the proper permissions, and
			// 5. you have already voted, and
			// 6. the poll creator has said you can!
			$context['allow_change_vote'] = !$context['poll']['is_expired'] && !$user_info['is_guest'] && empty($pollinfo['voting_locked']) && allowedTo('poll_vote') && $context['poll']['has_voted'] && $context['poll']['change_vote'];

			// You're allowed to return to voting options if:
			// 1. you are (still) allowed to vote.
			// 2. you are currently seeing the results.
			$context['allow_return_vote'] = $context['allow_vote'] && $context['poll']['show_results'];

			// Calculate the percentages and bar lengths...
			$divisor = $realtotal == 0 ? 1 : $realtotal;

			// Determine if a decimal point is needed in order for the options to add to 100%.
			$precision = $realtotal == 100 ? 0 : 1;

			// Now look through each option, and...
			foreach ($pollOptions as $i => $option)
			{
				// First calculate the percentage, and then the width of the bar...
				$bar = round(($option['votes'] * 100) / $divisor, $precision);
				$barWide = $bar == 0 ? 1 : floor(($bar * 8) / 3);

				// Now add it to the poll's contextual theme data.
				$context['poll']['options'][$i] = array(
					'id' => 'options-' . $i,
					'percent' => $bar,
					'votes' => $option['votes'],
					'voted_this' => $option['voted_this'] != -1,
					'bar' => '<span style="white-space: nowrap;"><img src="' . $settings['images_url'] . '/poll_' . ($context['right_to_left'] ? 'right' : 'left') . '.png" alt="" /><img src="' . $settings['images_url'] . '/poll_middle.png" style="width:' . $barWide . 'px; height:12px" alt="-" /><img src="' . $settings['images_url'] . '/poll_' . ($context['right_to_left'] ? 'left' : 'right') . '.png" alt="" /></span>',
					// Note: IE < 8 requires us to set a width on the container, too.
					'bar_ndt' => $bar > 0 ? '<div class="bar" style="width: ' . ($bar * 3.5 + 4) . 'px;"><div style="width: ' . $bar * 3.5 . 'px;"></div></div>' : '',
					'bar_width' => $barWide,
					'option' => parse_bbc($option['label']),
					'vote_button' => '<input type="' . ($pollinfo['max_votes'] > 1 ? 'checkbox' : 'radio') . '" name="options[]" id="options-' . $i . '" value="' . $i . '" class="input_' . ($pollinfo['max_votes'] > 1 ? 'check' : 'radio') . '" />'
				);
			}

			// Build the poll moderation button array.
			$context['poll_buttons'] = array(
				'vote' => array('test' => 'allow_return_vote', 'text' => 'poll_return_vote', 'image' => 'poll_options.png', 'lang' => true, 'url' => $scripturl . '?topic=' . $context['current_topic'] . '.' . $context['start']),
				'results' => array('test' => 'allow_poll_view', 'text' => 'poll_results', 'image' => 'poll_results.png', 'lang' => true, 'url' => $scripturl . '?topic=' . $context['current_topic'] . '.' . $context['start'] . ';viewresults'),
				'change_vote' => array('test' => 'allow_change_vote', 'text' => 'poll_change_vote', 'image' => 'poll_change_vote.png', 'lang' => true, 'url' => $scripturl . '?action=vote;topic=' . $context['current_topic'] . '.' . $context['start'] . ';poll=' . $context['poll']['id'] . ';' . $context['session_var'] . '=' . $context['session_id']),
				'lock' => array('test' => 'allow_lock_poll', 'text' => (!$context['poll']['is_locked'] ? 'poll_lock' : 'poll_unlock'), 'image' => 'poll_lock.png', 'lang' => true, 'url' => $scripturl . '?action=lockvoting;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
				'edit' => array('test' => 'allow_edit_poll', 'text' => 'poll_edit', 'image' => 'poll_edit.png', 'lang' => true, 'url' => $scripturl . '?action=editpoll;topic=' . $context['current_topic'] . '.' . $context['start']),
				'remove_poll' => array('test' => 'can_remove_poll', 'text' => 'poll_remove', 'image' => 'admin_remove_poll.png', 'lang' => true, 'custom' => 'onclick="return confirm(\'' . $txt['poll_remove_warn'] . '\');"', 'url' => $scripturl . '?action=removepoll;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
			);

			// Allow mods to add additional buttons here
			call_integration_hook('integrate_poll_buttons');
		}
	}

	private function _findMessages()
	{
		global $context, $options, $user_info, $modSettings;
		global $topic, $board;

		// Calculate the fastest way to get the messages!
		$ascending = empty($options['view_newest_first']);
		$start = $this->_start;
		$limit = $context['messages_per_page'];
		$this->_firstIndex = 0;
		if ($start >= $context['total_visible_posts'] / 2 && $context['messages_per_page'] != -1)
		{
			$ascending = !$ascending;
			$limit = $context['total_visible_posts'] <= $start + $limit ? $context['total_visible_posts'] - $start : $limit;
			$start = $context['total_visible_posts'] <= $start + $limit ? 0 : $context['total_visible_posts'] - $start - $limit;
			$this->_firstIndex = $limit - 1;
		}

		// Taking care of member specific settings
		$limit_settings = array(
			'messages_per_page' => $context['messages_per_page'],
			'start' => $start,
			'offset' => $limit,
 		);

		// Get each post and poster in this topic.
		$topic_details = getTopicsPostsAndPoster($topic, $limit_settings, $ascending);
		$this->_messages = $topic_details['messages'];
		$this->_posters = array_unique($topic_details['all_posters']);
		unset($topic_details);

		call_integration_hook('integrate_display_message_list', array(&$this->_messages, &$this->_posters));

		// Guests can't mark topics read or for notifications, just can't sorry.
		if (!$user_info['is_guest'] && !empty($this->_messages))
		{
			$mark_at_msg = max($this->_messages);
			if ($mark_at_msg >= $this->_topicinfo['id_last_msg'])
				$mark_at_msg = $modSettings['maxMsgID'];
			if ($mark_at_msg >= $this->_topicinfo['new_from'])
				markTopicsRead(array($user_info['id'], $topic, $mark_at_msg, $this->_topicinfo['disregarded']), $this->_topicinfo['new_from'] !== 0);

			updateReadNotificationsFor($topic, $board);

			// Have we recently cached the number of new topics in this board, and it's still a lot?
			if (isset($_REQUEST['topicseen']) && isset($_SESSION['topicseen_cache'][$board]) && $_SESSION['topicseen_cache'][$board] > 5)
				$_SESSION['topicseen_cache'][$board]--;
			// Mark board as seen if this is the only new topic.
			elseif (isset($_REQUEST['topicseen']))
			{
				// Use the mark read tables... and the last visit to figure out if this should be read or not.
				$numNewTopics = getUnreadCountSince($board, empty($_SESSION['id_msg_last_visit']) ? 0 : $_SESSION['id_msg_last_visit']);

				// If there're no real new topics in this board, mark the board as seen.
				if (empty($numNewTopics))
					$_REQUEST['boardseen'] = true;
				else
					$_SESSION['topicseen_cache'][$board] = $numNewTopics;
			}
			// Probably one less topic - maybe not, but even if we decrease this too fast it will only make us look more often.
			elseif (isset($_SESSION['topicseen_cache'][$board]))
				$_SESSION['topicseen_cache'][$board]--;

			// Mark board as seen if we came using last post link from BoardIndex. (or other places...)
			if (isset($_REQUEST['boardseen']))
			{
				require_once(SUBSDIR . '/Boards.subs.php');
				markBoardsRead($board, false, false);
			}
		}
	}

	private function _findAttachments()
	{
		global $attachments, $modSettings;

		$attachments = array();
		if (empty($this->_messages))
			return;

		require_once(SUBSDIR . '/Attachments.subs.php');

		// Fetch attachments.
		$this->_includeUnapproved = !$modSettings['postmod_active'] || allowedTo('approve_posts');
		if (!empty($modSettings['attachmentEnable']) && allowedTo('view_attachments'))
			$attachments = getAttachments($this->_messages, $this->_includeUnapproved, 'filter_accessible_attachment');
	}

	protected function _loadModerators()
	{
		global $context, $board_info, $scripturl, $txt;

		// Build a list of this board's moderators.
		$context['moderators'] = &$board_info['moderators'];
		$context['link_moderators'] = array();

		if (!empty($board_info['moderators']))
		{
			// Add a link for each moderator...
			foreach ($board_info['moderators'] as $mod)
				$context['link_moderators'][] = '<a href="' . $scripturl . '?action=profile;u=' . $mod['id'] . '" title="' . $txt['board_moderator'] . '">' . $mod['name'] . '</a>';

			// And show it after the board's name.
			$context['linktree'][count($context['linktree']) - 2]['extra_after'] = '<span class="board_moderators"> (' . (count($context['link_moderators']) == 1 ? $txt['moderator'] : $txt['moderators']) . ': ' . implode(', ', $context['link_moderators']) . ')</span>';
		}

	}

	protected function _setUpButtons()
	{
		global $context, $txt, $scripturl, $options, $board_info, $modSettings;
		global $topic, $board;

		// This is information about which page is current, and which page we're on - in case you don't like the constructed page index. (again, wireles..)
		$context['page_info'] = array(
			'current_page' => $this->_start / $context['messages_per_page'] + 1,
			'num_pages' => floor(($context['total_visible_posts'] - 1) / $context['messages_per_page']) + 1,
		);

		// Build the link tree.
		$context['linktree'][] = array(
			'url' => $scripturl . '?topic=' . $topic . '.0',
			'name' => $this->_topicinfo['subject'],
		);

		$context['jump_to'] = array(
			'label' => addslashes(un_htmlspecialchars($txt['jump_to'])),
			'board_name' => htmlspecialchars(strtr(strip_tags($board_info['name']), array('&amp;' => '&'))),
			'child_level' => $board_info['child_level'],
		);

		// Load in the likes for this group of messages
		if (!empty($modSettings['likes_enabled']) && !empty($this->_messages))
		{
			require_once(SUBSDIR . '/Likes.subs.php');
			$context['likes'] = loadLikes($this->_messages, true);
		}

		// @todo introduce an admin option
		require_once(SUBSDIR . '/FollowUps.subs.php');
		$context['follow_ups'] = followupTopics($this->_messages, $this->_includeUnapproved);

		// Are we showing signatures - or disabled fields?
		$context['signature_enabled'] = substr($modSettings['signature_settings'], 0, 1) == 1;
		$context['disabled_fields'] = isset($modSettings['disabled_profile_fields']) ? array_flip(explode(',', $modSettings['disabled_profile_fields'])) : array();

		// Figure out all the link to the next/prev/first/last/etc. for wireless mainly.
		$context['links'] = array(
			'first' => $this->_start >= $context['messages_per_page'] ? $scripturl . '?topic=' . $topic . '.0' : '',
			'prev' => $this->_start >= $context['messages_per_page'] ? $scripturl . '?topic=' . $topic . '.' . ($this->_start - $context['messages_per_page']) : '',
			'next' => $this->_start + $context['messages_per_page'] < $context['total_visible_posts'] ? $scripturl . '?topic=' . $topic. '.' . ($this->_start + $context['messages_per_page']) : '',
			'last' => $this->_start + $context['messages_per_page'] < $context['total_visible_posts'] ? $scripturl . '?topic=' . $topic. '.' . (floor($context['total_visible_posts'] / $context['messages_per_page']) * $context['messages_per_page']) : '',
			'up' => $scripturl . '?board=' . $board . '.0'
		);

		addJavascriptVar('notification_topic_notice', $context['is_marked_notify'] ? $txt['notification_disable_topic'] : $txt['notification_enable_topic'], true);

		// Build the normal button array.
		$context['normal_buttons'] = array(
			'reply' => array('test' => 'can_reply', 'text' => 'reply', 'image' => 'reply.png', 'lang' => true, 'url' => $scripturl . '?action=post;topic=' . $context['current_topic'] . '.' . $context['start'] . ';last_msg=' . $context['topic_last_message'], 'active' => true),
			'add_poll' => array('test' => 'can_add_poll', 'text' => 'add_poll', 'image' => 'add_poll.png', 'lang' => true, 'url' => $scripturl . '?action=editpoll;add;topic=' . $context['current_topic'] . '.' . $context['start']),
			'notify' => array( 'test' => 'can_mark_notify', 'text' => $context['is_marked_notify'] ? 'unnotify' : 'notify', 'image' => ($context['is_marked_notify'] ? 'un' : '') . 'notify.png', 'lang' => true, 'custom' => 'onclick="return notifyButton(this);"', 'url' => $scripturl . '?action=notify;sa=' . ($context['is_marked_notify'] ? 'off' : 'on') . ';topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
			'mark_unread' => array('test' => 'can_mark_unread', 'text' => 'mark_unread', 'image' => 'markunread.png', 'lang' => true, 'url' => $scripturl . '?action=markasread;sa=topic;t=' . $context['mark_unread_time'] . ';topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
			'disregard' => array('test' => 'can_disregard', 'text' => ($context['topic_disregarded'] ? 'un' : '') . 'disregard', 'image' => ($context['topic_disregarded'] ? 'un' : '') . 'disregard.png', 'lang' => true, 'custom' => 'onclick="return disregardButton(this);"', 'url' => $scripturl . '?action=disregardtopic;topic=' . $context['current_topic'] . '.' . $context['start'] . ';sa=' . ($context['topic_disregarded'] ? 'off' : 'on') . ';' . $context['session_var'] . '=' . $context['session_id']),
			'send' => array('test' => 'can_send_topic', 'text' => 'send_topic', 'image' => 'sendtopic.png', 'lang' => true, 'url' => $scripturl . '?action=emailuser;sa=sendtopic;topic=' . $context['current_topic'] . '.0'),
			'print' => array('test' => 'can_print', 'text' => 'print', 'image' => 'print.png', 'lang' => true, 'custom' => 'rel="nofollow"', 'class' => 'new_win', 'url' => $scripturl . '?action=topic;sa=printpage;topic=' . $context['current_topic'] . '.0'),
		);

		// Build the mod button array
		$context['mod_buttons'] = array(
			'move' => array('test' => 'can_move', 'text' => 'move_topic', 'image' => 'admin_move.png', 'lang' => true, 'url' => $scripturl . '?action=movetopic;current_board=' . $context['current_board'] . ';topic=' . $context['current_topic'] . '.0'),
			'delete' => array('test' => 'can_delete', 'text' => 'remove_topic', 'image' => 'admin_rem.png', 'lang' => true, 'custom' => 'onclick="return confirm(\'' . $txt['are_sure_remove_topic'] . '\');"', 'url' => $scripturl . '?action=removetopic2;topic=' . $context['current_topic'] . '.0;' . $context['session_var'] . '=' . $context['session_id']),
			'lock' => array('test' => 'can_lock', 'text' => empty($context['is_locked']) ? 'set_lock' : 'set_unlock', 'image' => 'admin_lock.png', 'lang' => true, 'url' => $scripturl . '?action=topic;sa=lock;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
			'sticky' => array('test' => 'can_sticky', 'text' => empty($context['is_sticky']) ? 'set_sticky' : 'set_nonsticky', 'image' => 'admin_sticky.png', 'lang' => true, 'url' => $scripturl . '?action=topic;sa=sticky;topic=' . $context['current_topic'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
			'merge' => array('test' => 'can_merge', 'text' => 'merge', 'image' => 'merge.png', 'lang' => true, 'url' => $scripturl . '?action=mergetopics;board=' . $context['current_board'] . '.0;from=' . $context['current_topic']),
			'calendar' => array('test' => 'calendar_post', 'text' => 'calendar_link', 'image' => 'linktocal.png', 'lang' => true, 'url' => $scripturl . '?action=post;calendar;msg=' . $context['topic_first_message'] . ';topic=' . $context['current_topic'] . '.0'),
		);

		// Restore topic. eh?  No monkey business.
		if ($context['can_restore_topic'])
			$context['mod_buttons'][] = array('text' => 'restore_topic', 'image' => '', 'lang' => true, 'url' => $scripturl . '?action=restoretopic;topics=' . $context['current_topic'] . ';' . $context['session_var'] . '=' . $context['session_id']);

		// Check if the draft functions are enabled and that they have permission to use them (for quick reply.)
		$context['drafts_save'] = !empty($modSettings['drafts_enabled']) && !empty($modSettings['drafts_post_enabled']) && allowedTo('post_draft') && $context['can_reply'];
		$context['drafts_autosave'] = !empty($context['drafts_save']) && !empty($modSettings['drafts_autosave_enabled']) && allowedTo('post_autosave_draft');
		if (!empty($context['drafts_save']))
			loadLanguage('Drafts');
		if (!empty($context['drafts_autosave']))
			loadJavascriptFile('drafts.js');

		// Load up the Quick ModifyTopic and Quick Reply scripts
		loadJavascriptFile('topic.js');

		if ($context['can_reply'] && !empty($options['display_quick_reply']))
			$this->_layers->add('quickreply');
		$this->_layers->add('pages_and_buttons');

		// Allow adding new buttons easily.
		call_integration_hook('integrate_display_buttons');
		call_integration_hook('integrate_mod_buttons');
	}

	/**
	 * In-topic quick moderation.
	 * Accessed by ?action=quickmod2
	 */
	public function action_quickmod2()
	{
		global $topic, $board, $user_info, $context;

		// Check the session = get or post.
		checkSession('request');

		require_once(SUBSDIR . '/Messages.subs.php');

		if (empty($_REQUEST['msgs']))
			redirectexit('topic=' . $topic . '.' . $_REQUEST['start']);

		$messages = array();
		foreach ($_REQUEST['msgs'] as $dummy)
			$messages[] = (int) $dummy;

		// We are restoring messages. We handle this in another place.
		if (isset($_REQUEST['restore_selected']))
			redirectexit('action=restoretopic;msgs=' . implode(',', $messages) . ';' . $context['session_var'] . '=' . $context['session_id']);

		if (isset($_REQUEST['split_selection']))
		{
			$mgsOptions = basicMessageInfo(min($messages), true);

			$_SESSION['split_selection'][$topic] = $messages;
			redirectexit('action=splittopics;sa=selectTopics;topic=' . $topic . '.0;subname_enc=' .urlencode($mgsOptions['subject']) . ';' . $context['session_var'] . '=' . $context['session_id']);
		}

		require_once(SUBSDIR . '/Topic.subs.php');
		$topic_info = getTopicInfo($topic);

		// Allowed to delete any message?
		if (allowedTo('delete_any'))
			$allowed_all = true;
		// Allowed to delete replies to their messages?
		elseif (allowedTo('delete_replies'))
			$allowed_all = $topic_info['id_member_started'] == $user_info['id'];
		else
			$allowed_all = false;

		// Make sure they're allowed to delete their own messages, if not any.
		if (!$allowed_all)
			isAllowedTo('delete_own');

		// Allowed to remove which messages?
		$messages = determineRemovableMessages($topic, $messages, $allowed_all);

		// Get the first message in the topic - because you can't delete that!
		$first_message = $topic_info['id_first_msg'];
		$last_message = $topic_info['id_last_msg'];

		// Delete all the messages we know they can delete. ($messages)
		foreach ($messages as $message => $info)
		{
			// Just skip the first message - if it's not the last.
			if ($message == $first_message && $message != $last_message)
				continue;
			// If the first message is going then don't bother going back to the topic as we're effectively deleting it.
			elseif ($message == $first_message)
				$topicGone = true;

			removeMessage($message);

			// Log this moderation action ;).
			if (allowedTo('delete_any') && (!allowedTo('delete_own') || $info[1] != $user_info['id']))
				logAction('delete', array('topic' => $topic, 'subject' => $info[0], 'member' => $info[1], 'board' => $board));
		}

		redirectexit(!empty($topicGone) ? 'board=' . $board : 'topic=' . $topic . '.' . $_REQUEST['start']);
	}

	/**
	 * Callback for the message display.
	 * It actually gets and prepares the message context.
	 * This method will start over from the beginning if reset is set to true, which is
	 * useful for showing an index before or after the posts.
	 *
	 * @param bool $reset default false.
	 */
	public function prepareDisplayContext_callback($reset = false)
	{
		global $settings, $txt, $modSettings, $scripturl, $options, $user_info;
		global $memberContext, $context, $topic;
		static $counter = null;

		// If the query returned false, bail.
		if ($this->_messages_request == false)
			return false;

		// Remember which message this is.  (ie. reply #83)
		if ($counter === null || $reset)
			$counter = empty($options['view_newest_first']) ? $context['start'] : $context['total_visible_posts'] - $context['start'];

		// Start from the beginning...
		// @todo this isn't ever called? does it even work, since it returns the query result?
		if ($reset)
			return currentContext($this->_messages_request, $reset);

		// Attempt to get the next message.
		$message = currentContext($this->_messages_request);
		if (!$message)
			return false;

		// $context['icon_sources'] says where each icon should come from - here we set up the ones which will always exist!
		if (empty($context['icon_sources']))
		{
			$stable_icons = array('xx', 'thumbup', 'thumbdown', 'exclamation', 'question', 'lamp', 'smiley', 'angry', 'cheesy', 'grin', 'sad', 'wink', 'poll', 'moved', 'recycled', 'wireless', 'clip');
			$context['icon_sources'] = array();
			foreach ($stable_icons as $icon)
				$context['icon_sources'][$icon] = 'images_url';
		}

		// Message Icon Management... check the images exist.
		if (empty($modSettings['messageIconChecks_disable']))
		{
			// If the current icon isn't known, then we need to do something...
			if (!isset($context['icon_sources'][$message['icon']]))
				$context['icon_sources'][$message['icon']] = file_exists($settings['theme_dir'] . '/images/post/' . $message['icon'] . '.png') ? 'images_url' : 'default_images_url';
		}
		elseif (!isset($context['icon_sources'][$message['icon']]))
			$context['icon_sources'][$message['icon']] = 'images_url';

		// If you're a lazy bum, you probably didn't give a subject...
		$message['subject'] = $message['subject'] != '' ? $message['subject'] : $txt['no_subject'];

		// Are you allowed to remove at least a single reply?
		$context['can_remove_post'] |= allowedTo('delete_own') && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time()) && $message['id_member'] == $user_info['id'];

		// Have you liked this post, can you
		$message['likes'] = !empty($context['likes'][$message['id_msg']]['member']) && isset($context['likes'][$message['id_msg']]['member'][$user_info['id']]);
		$message['use_likes'] = allowedTo('like_posts') && $message['id_member'] !== $user_info['id'];
		$message['like_count'] = !empty($context['likes'][$message['id_msg']]['count']) ? $context['likes'][$message['id_msg']]['count'] : 0;

		// If it couldn't load, or the user was a guest.... someday may be done with a guest table.
		if (!loadMemberContext($message['id_member'], true))
		{
			// Notice this information isn't used anywhere else....
			$memberContext[$message['id_member']]['name'] = $message['poster_name'];
			$memberContext[$message['id_member']]['id'] = 0;
			$memberContext[$message['id_member']]['group'] = $txt['guest_title'];
			$memberContext[$message['id_member']]['link'] = $message['poster_name'];
			$memberContext[$message['id_member']]['email'] = $message['poster_email'];
			$memberContext[$message['id_member']]['show_email'] = showEmailAddress(true, 0);
			$memberContext[$message['id_member']]['is_guest'] = true;
		}
		else
		{
			$memberContext[$message['id_member']]['can_view_profile'] = allowedTo('profile_view_any') || ($message['id_member'] == $user_info['id'] && allowedTo('profile_view_own'));
			$memberContext[$message['id_member']]['is_topic_starter'] = $message['id_member'] == $context['topic_starter_id'];
			$memberContext[$message['id_member']]['can_see_warning'] = !isset($context['disabled_fields']['warning_status']) && $memberContext[$message['id_member']]['warning_status'] && ($context['user']['can_mod'] || (!$user_info['is_guest'] && !empty($modSettings['warning_show']) && ($modSettings['warning_show'] > 1 || $message['id_member'] == $user_info['id'])));
		}

		$memberContext[$message['id_member']]['ip'] = $message['poster_ip'];
		$memberContext[$message['id_member']]['show_profile_buttons'] = $settings['show_profile_buttons'] && (!empty($memberContext[$message['id_member']]['can_view_profile']) || (!empty($memberContext[$message['id_member']]['website']['url']) && !isset($context['disabled_fields']['website'])) || (in_array($memberContext[$message['id_member']]['show_email'], array('yes', 'yes_permission_override', 'no_through_forum'))) || $context['can_send_pm']);

		// Do the censor thang.
		censorText($message['body']);
		censorText($message['subject']);

		// Run BBC interpreter on the message.
		$message['body'] = parse_bbc($message['body'], $message['smileys_enabled'], $message['id_msg']);

		// Compose the memory eat- I mean message array.
		require_once(SUBSDIR . '/Attachments.subs.php');
		$output = array(
			'attachment' => loadAttachmentContext($message['id_msg']),
			'alternate' => $counter % 2,
			'id' => $message['id_msg'],
			'href' => $scripturl . '?topic=' . $topic . '.msg' . $message['id_msg'] . '#msg' . $message['id_msg'],
			'link' => '<a href="' . $scripturl . '?topic=' . $topic . '.msg' . $message['id_msg'] . '#msg' . $message['id_msg'] . '" rel="nofollow">' . $message['subject'] . '</a>',
			'member' => &$memberContext[$message['id_member']],
			'icon' => $message['icon'],
			'icon_url' => $settings[$context['icon_sources'][$message['icon']]] . '/post/' . $message['icon'] . '.png',
			'subject' => $message['subject'],
			'time' => relativeTime($message['poster_time']),
			'timestamp' => forum_time(true, $message['poster_time']),
			'counter' => $counter,
			'modified' => array(
				'time' => relativeTime($message['modified_time']),
				'timestamp' => forum_time(true, $message['modified_time']),
				'name' => $message['modified_name']
			),
			'body' => $message['body'],
			'new' => empty($message['is_read']),
			'approved' => $message['approved'],
			'first_new' => isset($context['start_from']) && $context['start_from'] == $counter,
			'is_ignored' => !empty($modSettings['enable_buddylist']) && !empty($options['posts_apply_ignore_list']) && in_array($message['id_member'], $context['user']['ignoreusers']),
			'can_approve' => !$message['approved'] && $context['can_approve'],
			'can_unapprove' => !empty($modSettings['postmod_active']) && $context['can_approve'] && $message['approved'],
			'can_modify' => (!$context['is_locked'] || allowedTo('moderate_board')) && (allowedTo('modify_any') || (allowedTo('modify_replies') && $context['user']['started']) || (allowedTo('modify_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || !$message['approved'] || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time()))),
			'can_remove' => allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']) || (allowedTo('delete_own') && $message['id_member'] == $user_info['id'] && (empty($modSettings['edit_disable_time']) || $message['poster_time'] + $modSettings['edit_disable_time'] * 60 > time())),
			'can_see_ip' => allowedTo('moderate_forum') || ($message['id_member'] == $user_info['id'] && !empty($user_info['id'])),
			'can_like' => $message['use_likes'] && !$message['likes'],
			'can_unlike' => $message['use_likes'] && $message['likes'],
			'like_counter' =>$message['like_count'],
		);

		// Is this user the message author?
		$output['is_message_author'] = $message['id_member'] == $user_info['id'];
		if (!empty($output['modified']['name']))
			$output['modified']['last_edit_text'] = sprintf($txt['last_edit_by'], $output['modified']['time'], $output['modified']['name']);

		call_integration_hook('integrate_prepare_display_context', array(&$output, &$message));

		if (empty($options['view_newest_first']))
			$counter++;
		else
			$counter--;

		return $output;
	}
}
