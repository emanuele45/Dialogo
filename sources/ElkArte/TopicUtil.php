<?php

/**
 * This file contains functions for dealing with topics presentation.
 * Middle-level functions, those that "converts" raw queries into data
 * usable in the template or elsewhere.
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * This file contains code covered by:
 * copyright: 2011 Simple Machines (http://www.simplemachines.org)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte;

use BBC\ParserWrapper;

/**
 * Class TopicUtil
 *
 * Methods for dealing with topics presentation.
 * Converts queries results into data usable in the templates.
 */
class TopicUtil
{
	/**
	 * This function takes an array of data coming from the database and related
	 * to a list of topics and returns data useful in the template.
	 *
	 * @param mixed[] $topics_info - data coming from a query, for example
	 *                generated by getUnreadTopics or messageIndexTopics
	 * @param bool $topicseen - if use the temp table or not
	 * @param int|null $preview_length - length of the preview
	 * @return mixed[] - array of data related to topics
	 */
	public static function prepareContext($topics_info, $topicseen = false, $preview_length = null)
	{
		global $modSettings, $options, $txt, $settings;

		$topics = array();
		$preview_length = (int) $preview_length;
		if (empty($preview_length))
		{
			$preview_length = 128;
		}

		$messages_per_page = empty($modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : $modSettings['defaultMaxMessages'];
		$topicseen = $topicseen ? 'topicseen' : '';

		$icon_sources = new MessageTopicIcons(!empty($modSettings['messageIconChecks_enable']), $settings['theme_dir']);

		$parser = ParserWrapper::instance();

		foreach ($topics_info as $row)
		{
			// Is there a body to preview? (If not the preview is disabled.)
			if (isset($row['first_body']))
			{
				// Limit them to $preview_length characters - do this FIRST because it's a lot of wasted censoring otherwise.
				$row['first_body'] = strtr($parser->parseMessage($row['first_body'], $row['first_smileys']), array('<br />' => "\n", '&nbsp;' => ' '));
				$row['first_body'] = Util::htmlspecialchars(Util::shorten_html($row['first_body'], $preview_length));

				// No reply then they are the same, no need to process it again
				if ($row['num_replies'] == 0)
				{
					$row['last_body'] = $row['first_body'];
				}
				else
				{
					$row['last_body'] = strtr($parser->parseMessage($row['last_body'], $row['last_smileys']), array('<br />' => "\n", '&nbsp;' => ' '));
					$row['last_body'] = Util::htmlspecialchars(Util::shorten_html($row['last_body'], $preview_length));
				}

				// Censor the subject and message preview.
				$row['first_subject'] = censor($row['first_subject']);
				$row['first_body'] = censor($row['first_body']);

				// Don't censor them twice!
				if ($row['id_first_msg'] == $row['id_last_msg'])
				{
					$row['last_subject'] = $row['first_subject'];
					$row['last_body'] = $row['first_body'];
				}
				else
				{
					$row['last_subject'] = censor($row['last_subject']);
					$row['last_body'] = censor($row['last_body']);
				}
			}
			else
			{
				$row['first_body'] = '';
				$row['last_body'] = '';
				$row['first_subject'] = censor($row['first_subject']);

				if ($row['id_first_msg'] == $row['id_last_msg'])
				{
					$row['last_subject'] = $row['first_subject'];
				}
				else
				{
					$row['last_subject'] = censor($row['last_subject']);
				}
			}

			// Decide how many pages the topic should have.
			$topic_length = $row['num_replies'] + 1;
			if ($topic_length > $messages_per_page)
			{
				// We can't pass start by reference.
				$start = -1;
				$show_all = !empty($modSettings['enableAllMessages']) && $topic_length < $modSettings['enableAllMessages'];
				$pages = constructPageIndex(getUrl('topic', ['topic' => $row['id_topic'], 'start' => '%1$d', $topicseen, 'subject' => $row['first_subject']]), $start, $topic_length, $messages_per_page, true, array('prev_next' => false, 'all' => $show_all));
			}
			else
			{
				$pages = '';
			}

			$row['new_from'] = $row['new_from'] ?? 0;
			$first_poster_href = getUrl('profile', ['action' => 'profile', 'u' => $row['first_id_member'], 'name' => $row['first_display_name']]);
			$first_topic_href = getUrl('topic', ['topic' => $row['id_topic'], 'start' => '0', $topicseen, 'subject' => $row['first_subject']]);
			$last_poster_href = getUrl('profile', ['action' => 'profile', 'u' => $row['last_id_member'], 'name' => $row['last_display_name']]);

			if (User::$info->is_guest)
			{
				$topic_href = getUrl('topic', ['topic' => $row['id_topic'], 'start' => ((int) (($row['num_replies']) / $messages_per_page)) * $messages_per_page, 'subject' => $row['first_subject'], $topicseen]) . '#msg' . $row['id_last_msg'];
			}
			else
			{
				$topic_href = getUrl('topic', ['topic' => $row['id_topic'], 'start' => $row['num_replies'] == 0 ? '.0' : ('.msg' . $row['id_last_msg']), 'subject' => $row['first_subject'], $topicseen]) . '#new';
			}
			$href = getUrl('topic', ['topic' => $row['id_topic'], 'start' => $row['num_replies'] == 0 ? '0' : ('msg' . $row['new_from']), 'subject' => $row['first_subject'], $topicseen]) . $row['num_replies'] == 0 ? '' : '#new';

			// And build the array.
			$topics[$row['id_topic']] = array(
				'id' => $row['id_topic'],
				'first_post' => array(
					'id' => $row['id_first_msg'],
					'member' => array(
						'username' => $row['first_member_name'],
						'name' => $row['first_display_name'],
						'id' => $row['first_id_member'],
						'href' => !empty($row['first_id_member']) ? $first_poster_href : '',
						'link' => !empty($row['first_id_member']) ? '<a href="' . $first_poster_href . '" title="' . $txt['profile_of'] . ' ' . $row['first_display_name'] . '" class="preview">' . $row['first_display_name'] . '</a>' : $row['first_display_name']
					),
					'time' => standardTime($row['first_poster_time']),
					'html_time' => htmlTime($row['first_poster_time']),
					'timestamp' => forum_time(true, $row['first_poster_time']),
					'subject' => $row['first_subject'],
					'preview' => isset($row['first_body']) ? trim($row['first_body']) : '',
					'icon' => $icon_sources->getIconName($row['first_icon']),
					'icon_url' => $icon_sources->getIconURL($row['first_icon']),
					'href' => $first_topic_href,
					'link' => '<a href="' . $first_topic_href . '">' . $row['first_subject'] . '</a>'
				),
				'last_post' => array(
					'id' => $row['id_last_msg'],
					'member' => array(
						'username' => $row['last_member_name'],
						'name' => $row['last_display_name'],
						'id' => $row['last_id_member'],
						'href' => !empty($row['last_id_member']) ? $last_poster_href : '',
						'link' => !empty($row['last_id_member']) ? '<a href="' . $last_poster_href . '">' . $row['last_display_name'] . '</a>' : $row['last_display_name']
					),
					'time' => standardTime($row['last_poster_time']),
					'html_time' => htmlTime($row['last_poster_time']),
					'timestamp' => forum_time(true, $row['last_poster_time']),
					'subject' => $row['last_subject'],
					'preview' => isset($row['last_body']) ? trim($row['last_body']) : '',
					'icon' =>  $icon_sources->getIconName($row['last_icon']),
					'icon_url' => $icon_sources->getIconURL($row['last_icon']),
					'href' => $topic_href,
					'link' => '<a href="' . $topic_href . '" ' . ($row['num_replies'] == 0 ? '' : 'rel="nofollow"') . '>' . $row['last_subject'] . '</a>',
				),
				'default_preview' => trim($row[!empty($modSettings['message_index_preview']) && $modSettings['message_index_preview'] == 2 ? 'last_body' : 'first_body']),
				'is_sticky' => !empty($row['is_sticky']),
				'is_locked' => !empty($row['locked']),
				'is_poll' => !empty($modSettings['pollMode']) && $row['id_poll'] > 0,
				'is_hot' => !empty($modSettings['useLikesNotViews']) ? $row['num_likes'] >= $modSettings['hotTopicPosts'] : $row['num_replies'] >= $modSettings['hotTopicPosts'],
				'is_very_hot' => !empty($modSettings['useLikesNotViews']) ? $row['num_likes'] >= $modSettings['hotTopicVeryPosts'] : $row['num_replies'] >= $modSettings['hotTopicVeryPosts'],
				'is_posted_in' => false,
				'icon' => $icon_sources->getIconName($row['first_icon']),
				'icon_url' => $icon_sources->getIconURL($row['first_icon']),
				'subject' => $row['first_subject'],
				'new' => !empty($row['id_msg_modified']) && $row['new_from'] <= $row['id_msg_modified'],
				'new_from' => $row['new_from'],
				'newtime' => $row['new_from'],
				'new_href' => getUrl('topic', ['topic' => $row['id_topic'], 'start' => 'msg' . $row['new_from'], 'subject' => $row['first_subject'], $topicseen]) . '#new',
				'href' => $href,
				'link' => '<a href="' . $href . '" rel="nofollow">' . $row['first_subject'] . '</a>',
				'redir_href' => !empty($row['id_redirect_topic']) ? getUrl('topic', ['topic' => $row['id_topic'], 'start' => '0', 'subject' => $row['first_subject'], 'noredir']) : '',
				'pages' => $pages,
				'replies' => comma_format($row['num_replies']),
				'views' => comma_format($row['num_views']),
				'likes' => comma_format($row['num_likes']),
				'approved' => $row['approved'] ?? 1,
				'unapproved_posts' => !empty($row['unapproved_posts']) ? $row['unapproved_posts'] : 0,
				'classes' => array(),
			);

			if (!empty($row['id_board']))
			{
				$board_href = getUrl('board', ['board' => $row['id_board'], 'start' => '0', 'name' => $row['bname']]);
				$topics[$row['id_topic']]['board'] = array(
					'id' => $row['id_board'],
					'name' => $row['bname'],
					'href' => $board_href,
					'link' => '<a href="' . $board_href . '.0">' . $row['bname'] . '</a>'
				);
			}

			if (isset($row['avatar']) || !empty($row['id_attach']))
			{
				$topics[$row['id_topic']]['last_post']['member']['avatar'] = determineAvatar($row);
			}
			if (!empty($row['avatar_first']) || !empty($row['id_attach_first']))
			{
				$first_avatar = array(
					'avatar' => $row['avatar_first'],
					'id_attach' => $row['id_attach_first'],
					'attachment_type' => $row['attachment_type_first'],
					'filename' => $row['filename_first'],
					'email_address' => $row['email_address_first'],
				);
				$topics[$row['id_topic']]['first_post']['member']['avatar'] = determineAvatar($first_avatar);
			}

			determineTopicClass($topics[$row['id_topic']]);
		}

		return $topics;
	}
}
