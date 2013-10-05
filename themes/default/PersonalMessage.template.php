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
 */

/**
 * Loads the template of the poster area
 */
function template_PersonalMessage_init()
{
	loadTemplate('GenericMessages');
}

/**
 * This is the main sidebar for the personal messages section.
 * @todo - Started markup clean up in this template. More stuffz to check later.
 */
function template_pm_above()
{
	global $context, $txt;

	echo '
					<div id="personal_messages">';

	// Show the capacity bar, if available. @todo - This needs work.
	if (!empty($context['limit_bar']))
		echo '
						<h3 class="category_header">
							<span class="floatleft">', $txt['pm_capacity'], ':</span>
							<span class="floatleft capacity_bar">
								<span class="', $context['limit_bar']['percent'] > 85 ? 'full' : ($context['limit_bar']['percent'] > 40 ? 'filled' : 'empty'), '" style="width: ', $context['limit_bar']['percent'] / 10, 'em;"></span>
							</span>
							<span class="floatright', $context['limit_bar']['percent'] > 90 ? ' alert' : '', '">', $context['limit_bar']['text'], '</span>
						</h3>';

	// Message sent? Show a small indication.
	if (isset($context['pm_sent']))
		echo '
						<div class="infobox">
							', $txt['pm_sent'], '
						</div>';
}

/**
 * The end of the index bar, for personal messages page.
 */
function template_pm_below()
{
	echo '
					</div>';
}

/**
 * Messages folder.
 */
function template_folder()
{
	global $context, $scripturl, $options, $txt;

	// The every helpful javascript!
	echo '
				<script><!-- // --><![CDATA[
					var allLabels = {};
					var currentLabels = {};
					var txt_pm_msg_label_remove = "', $txt['pm_msg_label_remove'], '";
					var txt_pm_msg_label_apply = "', $txt['pm_msg_label_apply'], '";
				// ]]></script>';

	echo '
				<form action="', $scripturl, '?action=pm;sa=pmactions;', $context['display_mode'] == 2 ? 'conversation;' : '', 'f=', $context['folder'], ';start=', $context['start'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', '" method="post" accept-charset="UTF-8" name="pmFolder">';

	// If we are not in single display mode show the subjects on the top! @todo - Horrible markup here.
	if ($context['display_mode'] != 1)
	{
		template_subject_list();
		echo '
					<hr class="clear" />';
	}

	// Got some messages to display?
	if ($context['get_pmessage']('message', true))
	{

		// Show a few buttons if we are in conversation mode and outputting the first message.
		if ($context['display_mode'] == 2)
			template_pagesection('conversation_buttons', 'right', array('page_index' => false));
		else
			template_pagesection();

		echo '
					<div class="forumposts">';

		// Show the helpful titlebar - generally.
		if ($context['display_mode'] != 1)
			echo '
						<h2 class="category_header">
							', $txt[$context['display_mode'] == 0 ? 'messages' : 'conversation'], '
						</h2>';

		while ($message = $context['get_pmessage']('message'))
		{
			$window_class = $message['alternate'] === 0 ? 'windowbg' : 'windowbg2';

			echo '
						<div class="', $window_class, '">';

			// Showing the sidebar posting area?
			if (empty($options['hide_poster_area']))
				echo '
							<ul class="poster">', template_build_poster_div($message), '</ul>';

			echo '
							<div class="postarea', empty($options['hide_poster_area']) ? '' : '2', '">
								<div class="keyinfo">
									', (!empty($options['hide_poster_area']) ? '<ul class="poster poster2">' . template_build_poster_div($message) . '</ul>' : ''), '
									<span id="post_subject_', $message['id'], '" class="post_subject">', $message['subject'], '</span>
									<h5 id="info_', $message['id'], '">';

			// @todo - above needs fixing re document outlining (a11y stuffz).
			// Show who the message was sent to.
			echo '
										<strong> ', $txt['sent_to'], ':</strong> ';

			// People it was sent directly to....
			if (!empty($message['recipients']['to']))
				echo implode(', ', $message['recipients']['to']);
			// Otherwise, we're just going to say "some people"...
			elseif ($context['folder'] != 'sent')
				echo '(', $txt['pm_undisclosed_recipients'], ')';

			echo '
										<strong> ', $txt['on'], ':</strong> ', $message['time'];

			// If we're in the sent items folder, show who it was sent to besides the "To:" people.
			if (!empty($message['recipients']['bcc']))
				echo '
										<br /><strong> ', $txt['pm_bcc'], ':</strong> ', implode(', ', $message['recipients']['bcc']);

			if (!empty($message['is_replied_to']))
				echo '
										<br />', $txt['pm_is_replied_to'];

			echo '
									</h5>
								</div>';

			// Done with the information about the poster... on to the post itself.
			echo '
								<div class="inner" id="msg_', $message['id'], '"', '>', $message['body'], '</div>';

			// Show our quick buttons like quote and reply
			echo '
								<ul class="quickbuttons">';

			// Showing all then give a remove item checkbox
			if (empty($context['display_mode']))
				echo '
									<li class="listlevel1 quickmod_check"><input type="checkbox" name="pms[]" id="deletedisplay', $message['id'], '" value="', $message['id'], '" onclick="document.getElementById(\'deletelisting', $message['id'], '\').checked = this.checked;" class="input_check" /></li>';

			// Remove is always an option
			echo '
									<li class="listlevel1"><a href="', $scripturl, '?action=pm;sa=pmactions;pm_actions%5B', $message['id'], '%5D=delete;f=', $context['folder'], ';start=', $context['start'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';', $context['session_var'], '=', $context['session_id'], '" onclick="return confirm(\'', addslashes($txt['remove_message']), '?\');" class="linklevel1 remove_button">', $txt['delete'], '</a></li>';

			// Show reply buttons if you have the permission to send PMs.
			if ($context['can_send_pm'])
			{
				// You can't really reply if the member is gone.
				if (!$message['member']['is_guest'])
				{
					// Is there than more than one recipient you can reply to?
					if ($message['number_recipients'] > 1)
						echo '
									<li class="listlevel1"><a href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';quote;u=all" class="linklevel1 reply_all_button">', $txt['reply_to_all'], '</a></li>';

					echo '
									<li class="listlevel1"><a href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';u=', $message['member']['id'], '" class="linklevel1 reply_button">', $txt['reply'], '</a></li>
									<li class="listlevel1"><a href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';quote', $context['folder'] == 'sent' ? '' : ';u=' . $message['member']['id'], '" class="linklevel1 quote_button">', $txt['quote'], '</a></li>';
				}
				// This is for "forwarding" - even if the member is gone.
				else
					echo '
									<li class="listlevel1"><a href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';quote" class="linklevel1 quote_button">', $txt['reply_quote'], '</a></li>';
			}

			echo '
								</ul>';

			// Add a selection box if we have labels enabled.
			if ($context['folder'] !== 'sent' && !empty($context['currently_using_labels']) && $context['display_mode'] != 2)
			{
				echo '
								<div class="labels floatright">';

				// Add the label drop down box. @todo: Why inline styles for select?
				if (!empty($context['currently_using_labels']))
				{
					echo '
									<select style="padding:3px 2px;margin-right:10px" name="pm_actions[', $message['id'], ']" onchange="if (this.options[this.selectedIndex].value) form.submit();">
										<option value="">', $txt['pm_msg_label_title'], ':</option>
										<option value="" disabled="disabled">---------------</option>';

					// Are there any labels which can be added to this?
					if (!$message['fully_labeled'])
					{
						echo '
										<option value="" disabled="disabled">', $txt['pm_msg_label_apply'], ':</option>';

						foreach ($context['labels'] as $label)
						{
							if (!isset($message['labels'][$label['id']]))
								echo '
										<option value="', $label['id'], '">&nbsp;', $label['name'], '</option>';
						}
					}

					// ... and are there any that can be removed?
					if (!empty($message['labels']) && (count($message['labels']) > 1 || !isset($message['labels'][-1])))
					{
						echo '
										<option value="" disabled="disabled">', $txt['pm_msg_label_remove'], ':</option>';

						foreach ($message['labels'] as $label)
							echo '
										<option value="', $label['id'], '">&nbsp;', $label['name'], '</option>';
					}

					echo '
									</select>
									<noscript>
										<input type="submit" value="', $txt['pm_apply'], '" class="button_submit" />
									</noscript>';
				}

				echo '
								</div>';
			}

			// Are there any custom profile fields for above the signature?
			// Show them if signatures are enabled and you want to see them.
			if (!empty($message['member']['custom_fields']) && empty($options['show_no_signatures']) && $context['signature_enabled'])
			{
				$shown = false;
				foreach ($message['member']['custom_fields'] as $custom)
				{
					if ($custom['placement'] != 2 || empty($custom['value']))
						continue;

					if (!$shown)
					{
						$shown = true;
						echo '
								<div class="custom_fields_above_signature">
									<ul>';
					}

					echo '
										<li>', $custom['value'], '</li>';
				}

				if ($shown)
					echo '
									</ul>
								</div>';
			}

			// Show the member's signature?
			if (!empty($message['member']['signature']) && empty($options['show_no_signatures']) && $context['signature_enabled'])
				echo '
								<div class="signature">', $message['member']['signature'], '</div>';

			echo '
							</div>
						</div>';
		}

		echo '
					</div>';

		if (empty($context['display_mode']))
			template_pagesection(false, false, array('extra' => '<input type="submit" name="del_selected" value="' . $txt['quickmod_delete_selected'] . '" style="font-weight: normal;" onclick="if (!confirm(\'' . $txt['delete_selected_confirm'] . '\')) return false;" class="right_submit" />'));
		// Show a few buttons if we are in conversation mode and outputting the first message.
		elseif ($context['display_mode'] == 2 && isset($context['conversation_buttons']))
			template_pagesection('conversation_buttons', 'right', array('page_index' => false));
	}

	// Individual messages = button list!
	if ($context['display_mode'] == 1)
	{
		echo '
					<br />';

		template_subject_list();
	}

	echo '
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
				</form>';
}

/**
 * Just list all the personal message subjects - to make templates easier.
 */
function template_subject_list()
{
	global $context, $settings, $txt, $scripturl;

	// @todo - Should really get rid of some of the inline CSS in this table.
	echo '
					<table class="table_grid">
						<thead>
							<tr class="table_head">
								<th style="width:4%">
									<a href="', $scripturl, '?action=pm;view;f=', $context['folder'], ';start=', $context['start'], ';sort=', $context['sort_by'], ($context['sort_direction'] == 'up' ? '' : ';desc'), ($context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : ''), '"><img src="', $settings['images_url'], '/im_switch.png" alt="', $txt['pm_change_view'], '" title="', $txt['pm_change_view'], '" width="16" height="16" /></a>
								</th>
								<th class="lefttext" style="width:22%">
									<a href="', $scripturl, '?action=pm;f=', $context['folder'], ';start=', $context['start'], ';sort=date', $context['sort_by'] == 'date' && $context['sort_direction'] == 'up' ? ';desc' : '', $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', '">', $txt['date'], $context['sort_by'] == 'date' ? ' <img class="sort" src="' . $settings['images_url'] . '/sort_' . $context['sort_direction'] . '.png" alt="" />' : '', '</a>
								</th>
								<th class="lefttext" style="width:46%">
									<a href="', $scripturl, '?action=pm;f=', $context['folder'], ';start=', $context['start'], ';sort=subject', $context['sort_by'] == 'subject' && $context['sort_direction'] == 'up' ? ';desc' : '', $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', '">', $txt['subject'], $context['sort_by'] == 'subject' ? ' <img class="sort" src="' . $settings['images_url'] . '/sort_' . $context['sort_direction'] . '.png" alt="" />' : '', '</a>
								</th>
								<th class="lefttext">
									<a href="', $scripturl, '?action=pm;f=', $context['folder'], ';start=', $context['start'], ';sort=name', $context['sort_by'] == 'name' && $context['sort_direction'] == 'up' ? ';desc' : '', $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', '">', ($context['from_or_to'] == 'from' ? $txt['from'] : $txt['to']), $context['sort_by'] == 'name' ? ' <img class="sort" src="' . $settings['images_url'] . '/sort_' . $context['sort_direction'] . '.png" alt="" />' : '', '</a>
								</th>
								<th style="width:4%">
									<input type="checkbox" onclick="invertAll(this, this.form);" class="input_check" />
								</th>
							</tr>
						</thead>
						<tbody>';

	if (!$context['show_delete'])
		echo '
							<tr class="standard_row">
								<td colspan="5">', $txt['pm_alert_none'], '</td>
							</tr>';
	$next_alternate = false;

	while ($message = $context['get_pmessage']('subject'))
	{
		echo '
							<tr class="', $next_alternate ? 'standard_row' : 'alternate_row', '">
								<td class="centertext" style="width:4%">
									<script><!-- // --><![CDATA[
										currentLabels[', $message['id'], '] = {';

		if (!empty($message['labels']))
		{
			$first = true;
			foreach ($message['labels'] as $label)
			{
				echo $first ? '' : ',', '
										"', $label['id'], '": "', $label['name'], '"';
				$first = false;
			}
		}

		echo '
										};
									// ]]></script>
									', $message['is_replied_to'] ? '<img src="' . $settings['images_url'] . '/icons/pm_replied.png" style="margin-right: 4px;" alt="' . $txt['pm_replied'] . '" />' : '<img src="' . $settings['images_url'] . '/icons/pm_read.png" style="margin-right: 4px;" alt="' . $txt['pm_read'] . '" />', '</td>
								<td>', $message['time'], '</td>
								<td>', ($context['display_mode'] != 0 && $context['current_pm'] == $message['id'] ? '<img src="' . $settings['images_url'] . '/selected.png" alt="*" />' : ''), '<a href="', ($context['display_mode'] == 0 || $context['current_pm'] == $message['id'] ? '' : ($scripturl . '?action=pm;pmid=' . $message['id'] . ';kstart;f=' . $context['folder'] . ';start=' . $context['start'] . ';sort=' . $context['sort_by'] . ($context['sort_direction'] == 'up' ? ';' : ';desc') . ($context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : ''))), '#msg_', $message['id'], '">', $message['subject'], $message['is_unread'] ? '&nbsp;<span class="new_posts">' . $txt['new'] . '</span>' : '', '</a></td>
								<td>', ($context['from_or_to'] == 'from' ? $message['member']['link'] : (empty($message['recipients']['to']) ? '' : implode(', ', $message['recipients']['to']))), '</td>
								<td class="centertext" style="width:4%">
									<input type="checkbox" name="pms[]" id="deletelisting', $message['id'], '" value="', $message['id'], '"', $message['is_selected'] ? ' checked="checked"' : '', ' onclick="if (document.getElementById(\'deletedisplay', $message['id'], '\')) document.getElementById(\'deletedisplay', $message['id'], '\').checked = this.checked;" class="input_check" />
								</td>
							</tr>';
		$next_alternate = !$next_alternate;
	}

	echo '
						</tbody>
					</table>';

	$extra = '
					<div class="floatright">';

	if ($context['show_delete'])
	{
		if (!empty($context['currently_using_labels']) && $context['folder'] != 'sent')
		{
			$extra .= '
						<select name="pm_action" onchange="if (this.options[this.selectedIndex].value) this.form.submit();" onfocus="loadLabelChoices();">
							<option value="">' . $txt['pm_sel_label_title'] . ':</option>
							<option value="" disabled="disabled">---------------</option>';

			$extra .= '
							<option value="" disabled="disabled">' . $txt['pm_msg_label_apply'] . ':</option>';

			foreach ($context['labels'] as $label)
			{
				if ($label['id'] != $context['current_label_id'])
					$extra .= '
							<option value="add_' . $label['id'] . '">&nbsp;' . $label['name'] . '</option>';
			}

			$extra .= '
							<option value="" disabled="disabled">' . $txt['pm_msg_label_remove'] . ':</option>';

			foreach ($context['labels'] as $label)
			{
				$extra .= '
							<option value="rem_' . $label['id'] . '">&nbsp;' . $label['name'] . '</option>';
			}

			$extra .= '
						</select>
						<noscript>
							<input type="submit" value="' . $txt['pm_apply'] . '" class="right_submit" />
						</noscript>';
		}

		$extra .= '
						<input type="submit" name="del_selected" value="' . $txt['quickmod_delete_selected'] . '" onclick="if (!confirm(\'' . $txt['delete_selected_confirm'] . '\')) return false;" class="right_submit" />';
	}

	$extra .= '
					</div>';

	template_pagesection(false, false, array('extra' => $extra));
}

/**
 * Page to search in PMs.
 */
function template_search()
{
	global $context, $scripturl, $txt;

	echo '
	<form action="', $scripturl, '?action=pm;sa=search2" method="post" accept-charset="UTF-8" name="searchform" id="searchform">
		<h2 class="category_header">', $txt['pm_search_title'], '</h2>';

	if (!empty($context['search_errors']))
	{
		echo '
		<div class="errorbox">
			', implode('<br />', $context['search_errors']['messages']), '
		</div>';
	}

	// Basic search
	if ($context['simple_search'] && (empty($context['minmax_preferences']['pmsearch']) || isset($_GET['basic'])))
	{
		echo '
		<fieldset id="simple_search">
			<div id="search_term_input">
				<label for="search"><strong>', $txt['pm_search_text'], ':</strong>
				<input type="text" name="search"', !empty($context['search_params']['search']) ? ' value="' . $context['search_params']['search'] . '"' : '', ' size="40" class="input_text" placeholder="', $txt['search'], '" required="required" autofocus="autofocus" /></label>
				<input type="submit" name="pm_search" value="', $txt['pm_search_go'], '" class="button_submit" />
			</div>
			<a class="linkbutton" href="', $scripturl, '?action=pm;sa=search;advanced" onclick="elk_setThemeOption(\'minmax_preferences\', \'1\', null, elk_session_id, elk_session_var, \';minmax_key=pmsearch\');this.href += \';search=\' + escape(document.forms.searchform.search.value);">', $txt['pm_search_advanced'], '</a>
			<input type="hidden" name="advanced" value="0" />
		</fieldset>';
	}
	// Advanced search!
	else
	{
		echo '
		<fieldset id="advanced_search">
			<dl class="settings" id="search_options">
				<dt>
					<label for="search"><strong>', $txt['pm_search_text'], ':</strong></label>
				</dt>
				<dd>
					<input type="text" name="search"', !empty($context['search_params']['search']) ? ' value="' . $context['search_params']['search'] . '"' : '', ' size="40" class="input_text" placeholder="', $txt['search'], '" required="required" autofocus="autofocus" />
					<select name="searchtype">
						<option value="1"', empty($context['search_params']['searchtype']) ? ' selected="selected"' : '', '>', $txt['pm_search_match_all'], '</option>
						<option value="2"', !empty($context['search_params']['searchtype']) ? ' selected="selected"' : '', '>', $txt['pm_search_match_any'], '</option>
					</select>
				</dd>
				<dt>
					<label for="userspec">', $txt['pm_search_user'], ':</label>
				</dt>
				<dd>
					<input type="text" name="userspec" value="', empty($context['search_params']['userspec']) ? '*' : $context['search_params']['userspec'], '" size="40" class="input_text" />
				</dd>
				<dt>
					<label for="sort">', $txt['pm_search_order'], ':</label>
				</dt>
				<dd>
					<select name="sort" id="sort">
						<option value="relevance|desc">', $txt['pm_search_orderby_relevant_first'], '</option>
						<option value="id_pm|desc">', $txt['pm_search_orderby_recent_first'], '</option>
						<option value="id_pm|asc">', $txt['pm_search_orderby_old_first'], '</option>
					</select>
				</dd>
				<dt class="options">',
					$txt['pm_search_options'], ':
				</dt>
				<dd class="options">
					<label for="show_complete">
						<input type="checkbox" name="show_complete" id="show_complete" value="1"', !empty($context['search_params']['show_complete']) ? ' checked="checked"' : '', ' class="input_check" /> ', $txt['pm_search_show_complete'], '
					</label><br />
					<label for="subject_only">
						<input type="checkbox" name="subject_only" id="subject_only" value="1"', !empty($context['search_params']['subject_only']) ? ' checked="checked"' : '', ' class="input_check" /> ', $txt['pm_search_subject_only'], '
					</label><br />
					<label for="sent_only">
						<input type="checkbox" name="sent_only" id="sent_only" value="1"', !empty($context['search_params']['sent_only']) ? ' checked="checked"' : '', ' class="input_check" /> ', $txt['pm_search_sent_only'], '
					</label>
				</dd>
				<dt class="between">',
					$txt['pm_search_post_age'], ':
				</dt>
				<dd>
					<label for="minage">', $txt['pm_search_between'], ' <input type="text" id="minage" name="minage" value="', empty($context['search_params']['minage']) ? '0' : $context['search_params']['minage'], '" size="5" maxlength="5" class="input_text" /></label>&nbsp;<label for="maxage">', $txt['pm_search_between_and'], '&nbsp;<input type="text" name="maxage" id="maxage" value="', empty($context['search_params']['maxage']) ? '9999' : $context['search_params']['maxage'], '" size="5" maxlength="5" class="input_text" /></label> ', $txt['pm_search_between_days'], '
				</dd>
				<dt>
				</dt>
				<dd>
					<a href="', $scripturl, '?action=pm;sa=search;basic" onclick="elk_setThemeOption(\'minmax_preferences\', \'0\', null, elk_session_id, elk_session_var, \';minmax_key=pmsearch\');this.href += \';search=\' + escape(document.forms.searchform.search.value);" class="linkbutton">', $txt['pm_search_simple'], '</a>
				</dd>
			</dl>
			<input type="hidden" name="advanced" value="1" />';

		if (!$context['currently_using_labels'])
			echo '
			<input type="submit" name="pm_search" value="', $txt['pm_search_go'], '" class="right_submit" />';

		echo '
		</fieldset>';

		// Do we have some labels setup? If so offer to search by them!
		if ($context['currently_using_labels'])
		{
			echo '
		<fieldset class="labels">
			<h3 class="category_header">
				<span id="category_toggle">&nbsp;
					<span id="advanced_panel_toggle" class="', empty($context['minmax_preferences']['pm']) ? 'collapse' : 'expand', '" style="display: none;" title="', $txt['hide'], '"></span>
				</span>
			</h3>
			<div id="advanced_panel_div"', empty($context['minmax_preferences']['pm']) ? '' : ' style="display: none;"', '>
				<ul id="searchLabelsExpand">';

			foreach ($context['search_labels'] as $label)
				echo '
					<li>
						<label for="searchlabel_', $label['id'], '"><input type="checkbox" id="searchlabel_', $label['id'], '" name="searchlabel[', $label['id'], ']" value="', $label['id'], '" ', $label['checked'] ? 'checked="checked"' : '', ' class="input_check" />
						', $label['name'], '</label>
					</li>';

			echo '
				</ul>
			</div>
			<div class="submitbuttons">
				<input type="checkbox" name="all" id="check_all" value="" ', $context['check_all'] ? 'checked="checked"' : '', ' onclick="invertAll(this, this.form, \'searchlabel\');" class="input_check" /><em> <label for="check_all">', $txt['check_all'], '</label></em>
				<input type="submit" name="pm_search" value="', $txt['pm_search_go'], '" class="right_submit" />
			</div>
		</fieldset>';

			// Some javascript for the advanced toggling
			echo '
		<script><!-- // --><![CDATA[
			createEventListener(window);
			window.addEventListener("load", initSearch, false);

			var oAdvancedPanelToggle = new elk_Toggle({
				bToggleEnabled: true,
				bCurrentlyCollapsed: ', empty($context['minmax_preferences']['pm']) ? 'false' : 'true', ',
				aSwappableContainers: [
					\'advanced_panel_div\'
				],
				aSwapClasses: [
					{
						sId: \'advanced_panel_toggle\',
						classExpanded: \'collapse\',
						titleExpanded: ', JavaScriptEscape($txt['hide']), ',
						classCollapsed: \'expand\',
						titleCollapsed: ', JavaScriptEscape($txt['show']), '
					}
				],
				aSwapLinks: [
					{
						sId: \'advanced_panel_link\',
						msgExpanded: ', JavaScriptEscape($txt['pm_search_choose_label']), ',
						msgCollapsed: ', JavaScriptEscape($txt['pm_search_choose_label']), '
					}
				],
				oThemeOptions: {
					bUseThemeSettings: ', $context['user']['is_guest'] ? 'false' : 'true', ',
					sOptionName: \'minmax_preferences\',
					sSessionId: elk_session_id,
					sSessionVar: elk_session_var,
					sAdditionalVars: \';minmax_key=pm\'
				},
			});
		// ]]></script>';
		}
	}

	echo '
	</form>';
}

/**
 * Template for the results of search in PMs.
 */
function template_search_results()
{
	global $context, $scripturl, $txt;

	echo '
		', template_pagesection(), '
		<div class="forumposts">
			<h2 class="category_header">
				', $txt['pm_search_results'], '
			</h2>';

	// complete results ?
	// @todo - Should really get rid of some of the inline CSS in this table.
	if (empty($context['search_params']['show_complete']) && !empty($context['personal_messages']))
		echo '
			<table class="table_grid">
				<thead>
					<tr class="table_head">
						<th class="lefttext" style="width:30%">', $txt['date'], '</th>
						<th class="lefttext" style="width:50%">', $txt['subject'], '</th>
						<th class="lefttext" style="width:20%">', $txt['from'], '</th>
					</tr>
				</thead>
				<tbody>';

	$alternate = true;

	// Print each message out...
	foreach ($context['personal_messages'] as $message)
	{
		// We showing it all?
		// @todo - Needs markup rewrite here.
		if (!empty($context['search_params']['show_complete']))
		{
			echo '
			<div class="windowbg', $alternate ? '2' : '', '">
				<div class="postarea2">
					<h5>
						<span class="floatright">', $txt['search_on'], ': ', $message['time'], '</span>
						<span class="floatleft">', $message['counter'], '&nbsp;&nbsp;<a href="', $message['href'], '">', $message['subject'], '</a></span>
					</h5>
					<h5 class="clear">', $txt['from'], ': ', $message['member']['link'], ', ', $txt['to'], ': ';

			// Show the recipients.
			// @todo This doesn't deal with the sent item searching quite right for bcc.
			if (!empty($message['recipients']['to']))
				echo implode(', ', $message['recipients']['to']);
			// Otherwise, we're just going to say "some people"...
			elseif ($context['folder'] != 'sent')
				echo '(', $txt['pm_undisclosed_recipients'], ')';

			echo '
					</h5>
					<div class="inner">
						', $message['body'], '
					</div>';

			if ($context['can_send_pm'])
			{
				echo '
					<ul class="quickbuttons">';

				// You can only reply if they are not a guest...
				if (!$message['member']['is_guest'])
					echo '
						<li class="listlevel1"><a class="linklevel1 reply_button" href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';u=', $message['member']['id'], '">', $txt['reply'], '</a></li>
						<li class="listlevel1"><a class="linklevel1 quote_button" href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';quote;u=', $context['folder'] == 'sent' ? '' : $message['member']['id'], '">', $txt['quote'], '</a></li>';
				// This is for "forwarding" - even if the member is gone.
				else
					echo '
						<li class="listlevel1"><a class="linklevel1 quote_button" href="', $scripturl, '?action=pm;sa=send;f=', $context['folder'], $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';pmsg=', $message['id'], ';quote">', $txt['quote'], '</a></li>';
				echo '
					</ul>';
			}

			echo '
				</div>
			</div>';
		}
		// Otherwise just a simple list!
		else
		{
			// @todo No context at all of the search?
			echo '
			<tr class="', $alternate ? 'windowbg' : 'windowbg2', '" style="vertical-align:top">
				<td>', $message['time'], '</td>
				<td>', $message['link'], '</td>
				<td>', $message['member']['link'], '</td>
			</tr>';
		}

		$alternate = !$alternate;
	}

	// Finish off the page...
	if (empty($context['search_params']['show_complete']) && !empty($context['personal_messages']))
		echo '
				</tbody>
			</table>';

	// No results?
	if (empty($context['personal_messages']))
		echo '
			<div class="windowbg">
				<p class="content centertext">', $txt['pm_search_none_found'], '</p>
			</div>';

	echo '
		</div>';

	template_pagesection();
}

function template_send()
{
	global $context, $settings, $scripturl, $modSettings, $txt;

	// Show which messages were sent successfully and which failed.
	if (!empty($context['send_log']))
	{
		echo '
			<div class="forumposts">
				<h3 class="category_header">', $txt['pm_send_report'], '</h3>
				<div class="windowbg">
					<div class="content">';

		if (!empty($context['send_log']['sent']))
			foreach ($context['send_log']['sent'] as $log_entry)
				echo '<span class="error">', $log_entry, '</span><br />';

		if (!empty($context['send_log']['failed']))
			foreach ($context['send_log']['failed'] as $log_entry)
				echo '<span class="error">', $log_entry, '</span><br />';

		echo '
					</div>
				</div>
			</div>
			<br />';
	}

	// Show the preview of the personal message.
	echo '
		<div id="preview_section" class="forumposts"', isset($context['preview_message']) ? '' : ' style="display: none;"', '>
			<h3 class="category_header">
				<span id="preview_subject">', empty($context['preview_subject']) ? '' : $context['preview_subject'], '</span>
			</h3>
			<div class="post" id="preview_body">
				', empty($context['preview_message']) ? '<br />' : $context['preview_message'], '
			</div>
		</div>';

	// Main message editing box.
	echo '
	<form action="', $scripturl, '?action=pm;sa=send2" method="post" accept-charset="UTF-8" name="postmodify" id="postmodify" class="flow_hidden" onsubmit="submitonce(this);smc_saveEntities(\'postmodify\', [\'subject\', \'message\']);">
		<div class="forumposts">
			<h3 class="category_header">
					<img src="', $settings['images_url'], '/icons/inbox_hd.png" class="icon" alt="', $txt['new_message'], '" title="', $txt['new_message'], '" />&nbsp;', $txt['new_message'], '
			</h3>';

	echo '
			<div class="windowbg">
				<div class="editor_wrapper">';

	// If there were errors for sending the PM, show them.
	template_show_error('post_error');

	if (!empty($modSettings['drafts_pm_enabled']))
		echo '
					<div id="draft_section" class="infobox"', isset($context['draft_saved']) ? '' : ' style="display: none;"', '>',
		sprintf($txt['draft_pm_saved'], $scripturl . '?action=pm;sa=showpmdrafts'), '
					</div>';

	echo '
					<dl id="post_header">';

	// To and bcc. Include a button to search for members.
	echo '
						<dt>
							<span', (isset($context['post_error']['no_to']) || isset($context['post_error']['bad_to']) ? ' class="error"' : ''), ' id="caption_to">', $txt['pm_to'], ':</span>
						</dt>';

	// Autosuggest will be added by the javascript later on.
	echo '
						<dd id="pm_to" class="clear_right">
							<input type="text" name="to" id="to_control" value="', $context['to_value'], '" tabindex="', $context['tabindex']++, '" size="40" style="width: 130px;" class="input_text" />';

	// A link to add BCC, only visible with javascript enabled.
	echo '
							<span class="smalltext" id="bcc_link_container" style="display: none;"></span>';

	// A div that'll contain the items found by the autosuggest.
	echo '
							<div id="to_item_list_container"></div>';

	echo '
						</dd>';

	// This BCC row will be hidden by default if javascript is enabled.
	echo '
						<dt  class="clear_left" id="bcc_div">
							<span', (isset($context['post_error']['no_to']) || isset($context['post_error']['bad_bcc']) ? ' class="error"' : ''), ' id="caption_bbc">', $txt['pm_bcc'], ':</span>
						</dt>
						<dd id="bcc_div2">
							<input type="text" name="bcc" id="bcc_control" value="', $context['bcc_value'], '" tabindex="', $context['tabindex']++, '" size="40" style="width: 130px;" class="input_text" />
							<div id="bcc_item_list_container"></div>
						</dd>';

	// The subject of the PM.
	echo '
						<dt class="clear_left">
							<span', (isset($context['post_error']['no_subject']) ? ' class="error"' : ''), ' id="caption_subject">', $txt['subject'], ':</span>
						</dt>
						<dd id="pm_subject">
							<input type="text" name="subject" value="', $context['subject'], '" tabindex="', $context['tabindex']++, '" size="80" maxlength="80"', isset($context['post_error']['no_subject']) ? ' class="error"' : ' class="input_text"', ' placeholder="', $txt['subject'], '" required="required" />
						</dd>
					</dl><hr class="clear" />';

	// Showing BBC?
	if ($context['show_bbc'])
		echo '
					<div id="bbcBox_message"></div>';

	// What about smileys?
	if (!empty($context['smileys']['postform']) || !empty($context['smileys']['popup']))
		echo '
					<div id="smileyBox_message"></div>';

	// Show BBC buttons, smileys and textbox.
	echo '
					', template_control_richedit($context['post_box_name'], 'smileyBox_message', 'bbcBox_message');

	// Require an image to be typed to save spamming?
	if ($context['require_verification'])
		echo '
					<div class="post_verification">
						<strong>', $txt['pm_visual_verification_label'], ':</strong>
						', template_control_verification($context['visual_verification_id'], 'all'), '
					</div>';

	// Send, Preview, spellchecker buttons.
	echo '
					<div class="submitbutton">
						', template_control_richedit_buttons($context['post_box_name']), '
					</div>
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
					<input type="hidden" name="seqnum" value="', $context['form_sequence_number'], '" />
					<input type="hidden" name="replied_to" value="', !empty($context['quoted_message']['id']) ? $context['quoted_message']['id'] : 0, '" />
					<input type="hidden" name="pm_head" value="', !empty($context['quoted_message']['pm_head']) ? $context['quoted_message']['pm_head'] : 0, '" />
					<input type="hidden" name="f" value="', isset($context['folder']) ? $context['folder'] : '', '" />
					<input type="hidden" name="l" value="', isset($context['current_label_id']) ? $context['current_label_id'] : -1, '" />';

	// If the admin enabled the pm drafts feature, show a draft selection box
	if (!empty($modSettings['drafts_enabled']) && !empty($context['drafts_pm_save']) && !empty($context['drafts']))
	{
		echo '
			<h3 id="postDraftOptionsHeader" class="category_header">
				<span id="category_toggle">&nbsp;
					<span id="postDraftExpand" class="', empty($context['minmax_preferences']['pmdraft']) ? 'collapse' : 'expand', '" style="display: none;" title="', $txt['hide'], '"></span>
				</span>
				<a href="#" id="postDraftExpandLink">', $txt['draft_load'], '</a>
			</h3>
			<div id="postDraftOptions" class="load_drafts padding"', empty($context['minmax_preferences']['pmdraft']) ? '' : ' style="display: none;"', '>
				<dl class="settings">
					<dt><strong>', $txt['subject'], '</strong></dt>
					<dd><strong>', $txt['draft_saved_on'], '</strong></dd>';

		foreach ($context['drafts'] as $draft)
			echo '
					<dt>', $draft['link'], '</dt>
					<dd>', $draft['poster_time'], '</dd>';

		echo '
				</dl>
			</div>';
	}

	echo '
				</div>
			</div>
		</div>
	</form>';

	// The vars used to preview a personal message without loading a new page.
	echo '
		<script><!-- // --><![CDATA[
			var post_box_name = "', $context['post_box_name'], '";
			var form_name = "postmodify";
			var preview_area = "pm";
			var txt_preview_title = "', $txt['preview_title'], '";
			var txt_preview_fetch = "', $txt['preview_fetch'], '";';

	// Code for showing and hiding drafts
	if (!empty($context['drafts']))
		echo '
			var oSwapDraftOptions = new elk_Toggle({
				bToggleEnabled: true,
				bCurrentlyCollapsed: ', empty($context['minmax_preferences']['pmdraft']) ? 'false' : 'true', ',
				aSwappableContainers: [
					\'postDraftOptions\',
				],
				aSwapClasses: [
					{
						sId: \'postDraftExpand\',
						classExpanded: \'collapse\',
						titleExpanded: ', JavaScriptEscape($txt['hide']), ',
						classCollapsed: \'expand\',
						titleCollapsed: ', JavaScriptEscape($txt['show']), '
					}
				],
				aSwapLinks: [
					{
						sId: \'postDraftExpandLink\',
						msgExpanded: ', JavaScriptEscape($txt['draft_hide']), ',
						msgCollapsed: ', JavaScriptEscape($txt['draft_load']), '
					}
				],
				oThemeOptions: {
					bUseThemeSettings: ', $context['user']['is_guest'] ? 'false' : 'true', ',
					sOptionName: \'minmax_preferences\',
					sSessionId: elk_session_id,
					sSessionVar: elk_session_var,
					sAdditionalVars: \';minmax_key=pmdraft\'
				},
			});';

	echo '
		// ]]></script>';

	// Show the message you're replying to.
	if ($context['reply'])
		echo '

	<div class="forumposts">
		<h3 class="category_header">', $txt['subject'], ': ', $context['quoted_message']['subject'], '</h3>
		<div class="windowbg2">
			<div class="content">
				<div class="clear">
					<span class="smalltext floatright">', $txt['on'], ': ', $context['quoted_message']['time'], '</span>
					<strong>', $txt['from'], ': ', $context['quoted_message']['member']['name'], '</strong>
				</div>
				<hr />
				', $context['quoted_message']['body'], '
			</div>
		</div>
	</div><br class="clear" />';

	echo '
		<script><!-- // --><![CDATA[
			var oPersonalMessageSend = new elk_PersonalMessageSend({
				sSelf: \'oPersonalMessageSend\',
				sSessionId: elk_session_id,
				sSessionVar: elk_session_var,
				sTextDeleteItem: \'', $txt['autosuggest_delete_item'], '\',
				sToControlId: \'to_control\',
				aToRecipients: [';

	foreach ($context['recipients']['to'] as $i => $member)
		echo '
					{
						sItemId: ', JavaScriptEscape($member['id']), ',
						sItemName: ', JavaScriptEscape($member['name']), '
					}', $i == count($context['recipients']['to']) - 1 ? '' : ',';

	echo '
				],
				aBccRecipients: [';

	foreach ($context['recipients']['bcc'] as $i => $member)
		echo '
					{
						sItemId: ', JavaScriptEscape($member['id']), ',
						sItemName: ', JavaScriptEscape($member['name']), '
					}', $i == count($context['recipients']['bcc']) - 1 ? '' : ',';

	echo '
				],
				sBccControlId: \'bcc_control\',
				sBccDivId: \'bcc_div\',
				sBccDivId2: \'bcc_div2\',
				sBccLinkId: \'bcc_link\',
				sBccLinkContainerId: \'bcc_link_container\',
				bBccShowByDefault: ', empty($context['recipients']['bcc']) && empty($context['bcc_value']) ? 'false' : 'true', ',
				sShowBccLinkTemplate: ', JavaScriptEscape('
					<a href="#" id="bcc_link">' . $txt['make_bcc'] . '</a> <a href="' . $scripturl . '?action=quickhelp;help=pm_bcc" onclick="return reqOverlayDiv(this.href);">(?)</a>'
	), '
			});
		';

	echo '
		// ]]></script>';
}

/**
 * This template asks the user whether they wish to empty out their folder/messages.
 */
function template_ask_delete()
{
	global $context, $scripturl, $txt;

	echo '
		<h2 class="category_header">', ($context['delete_all'] ? $txt['delete_message'] : $txt['delete_all']), '</h2>
		<div class="windowbg">
			<div class="content">
				<p>', $txt['delete_all_confirm'], '</p><br />
				<strong><a href="', $scripturl, '?action=pm;sa=removeall2;f=', $context['folder'], ';', $context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '', ';', $context['session_var'], '=', $context['session_id'], '">', $txt['yes'], '</a> - <a href="javascript:history.go(-1);">', $txt['no'], '</a></strong>
			</div>
		</div>';
}

/**
 * This template asks the user what messages they want to prune.
 */
function template_prune()
{
	global $context, $scripturl, $txt;

	echo '
	<form action="', $scripturl, '?action=pm;sa=prune" method="post" accept-charset="UTF-8" onsubmit="return confirm(\'', $txt['pm_prune_warning'], '\');">
		<h2 class="category_header">', $txt['pm_prune'], '</h2>
		<div class="windowbg">
			<div class="content">
				<p>', $txt['pm_prune_desc1'], ' <input type="text" name="age" size="3" value="14" class="input_text" /> ', $txt['pm_prune_desc2'], '</p>
				<input type="submit" value="', $txt['delete'], '" class="right_submit" />
			</div>
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
	</form>';
}

/**
 * Here we allow the user to setup labels, remove labels and change rules for labels (i.e, do quite a bit)
 */
function template_labels()
{
	global $context, $scripturl, $txt;

	echo '
	<form class="flow_auto" action="', $scripturl, '?action=pm;sa=manlabels" method="post" accept-charset="UTF-8">
		<h2 class="category_header">', $txt['pm_manage_labels'], '</h2>
		<div class="description">
			', $txt['pm_labels_desc'], '
		</div>
		<table class="table_grid">
		<thead>
			<tr class="table_head">
				<th class="lefttext">
					', $txt['pm_label_name'], '
				</th>
				<th style="width:4%">';

	if (count($context['labels']) > 2)
		echo '
					<input type="checkbox" class="input_check" onclick="invertAll(this, this.form);" />';

	echo '
				</th>
			</tr>
		</thead>
		<tbody>';

	if (count($context['labels']) < 2)
		echo '
			<tr class="windowbg2">
				<td colspan="2" class="centertext">', $txt['pm_labels_no_exist'], '</td>
			</tr>';
	else
	{
		$alternate = true;
		foreach ($context['labels'] as $label)
		{
			if ($label['id'] == -1)
				continue;

			echo '
			<tr class="', $alternate ? 'windowbg2' : 'windowbg', '">
				<td>
					<input type="text" name="label_name[', $label['id'], ']" value="', $label['name'], '" size="30" maxlength="30" class="input_text" />
				</td>
				<td class="centertext" style="width:4%">
					<input type="checkbox" class="input_check" name="delete_label[', $label['id'], ']" />
				</td>
			</tr>';

			$alternate = !$alternate;
		}
	}

	echo '
		</tbody>
		</table>';

	if (!count($context['labels']) < 2)
		echo '
		<div class="submitbutton">
			<input type="submit" name="save" value="', $txt['save'], '" class="button_submit" />
			<input type="submit" name="delete" value="', $txt['quickmod_delete_selected'], '" onclick="return confirm(\'', $txt['pm_labels_delete'], '\');" class="button_submit" />
		</div>';

	echo '
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
	</form>
	<br />
	<form class="flow_auto" action="', $scripturl, '?action=pm;sa=manlabels" method="post" accept-charset="UTF-8" style="margin-top: 1ex;">
		<h3 class="category_header">', $txt['pm_label_add_new'], '</h3>
		<div class="windowbg">
			<div class="content">
				<dl class="settings">
					<dt>
						<strong><label for="add_label">', $txt['pm_label_name'], '</label>:</strong>
					</dt>
					<dd>
						<input type="text" id="add_label" name="label" value="" size="30" maxlength="30" class="input_text" />
					</dd>
				</dl>
				<div class="submitbutton">
					<input type="submit" name="add" value="', $txt['pm_label_add_new'], '" class="button_submit" />
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
				</div>
			</div>
		</div>
	</form>';
}

/**
 * Template for reporting a personal message.
 */
function template_report_message()
{
	global $context, $txt, $scripturl;

	echo '
	<form action="', $scripturl, '?action=pm;sa=report;l=', $context['current_label_id'], '" method="post" accept-charset="UTF-8">
		<input type="hidden" name="pmsg" value="', $context['pm_id'], '" />
		<h2 class="category_header">', $txt['pm_report_title'], '</h2>
		<div class="description">
			', $txt['pm_report_desc'], '
		</div>
		<div class="windowbg">
			<div class="content">
				<dl class="settings">';

	// If there is more than one admin on the forum, allow the user to choose the one they want to direct to.
	// @todo Why?
	if ($context['admin_count'] > 1)
	{
		echo '
					<dt>
						<strong>', $txt['pm_report_admins'], ':</strong>
					</dt>
					<dd>
						<select name="id_admin">
							<option value="0">', $txt['pm_report_all_admins'], '</option>';

		foreach ($context['admins'] as $id => $name)
			echo '
							<option value="', $id, '">', $name, '</option>';

		echo '
						</select>
					</dd>';
	}

	echo '
					<dt>
						<strong>', $txt['pm_report_reason'], ':</strong>
					</dt>
					<dd>
						<textarea name="reason" rows="4" cols="70" style="' . (isBrowser('is_ie8') ? 'width: 635px; max-width: 80%; min-width: 80%' : 'width: 80%') . ';"></textarea>
					</dd>
				</dl>
				<div class="submitbutton">
					<input type="submit" name="report" value="', $txt['pm_report_message'], '" class="button_submit" />
				</div>
			</div>
		</div>
		<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
	</form>';
}

/**
 * Little template just to say "Yep, it's been submitted".
 */
function template_report_message_complete()
{
	global $context, $txt, $scripturl;

	echo '
		<h2 class="category_header">', $txt['pm_report_title'], '</h2>
		<div class="windowbg">
			<div class="content">
				<p>', $txt['pm_report_done'], '</p>
				<a href="', $scripturl, '?action=pm;l=', $context['current_label_id'], '">', $txt['pm_report_return'], '</a>
			</div>
		</div>';
}

// Manage rules.
function template_rules()
{
	global $context, $txt, $scripturl;

	echo '
	<form action="', $scripturl, '?action=pm;sa=manrules" method="post" accept-charset="UTF-8" name="manRules" id="manrules">
		<h2 class="category_header">', $txt['pm_manage_rules'], '</h2>
		<div class="description">
			', $txt['pm_manage_rules_desc'], '
		</div>
		<table class="table_grid">
		<thead>
			<tr class="table_head">
				<th class="lefttext">
					', $txt['pm_rule_title'], '
				</th>
				<th style="width:4%">';

	if (!empty($context['rules']))
		echo '
					<input type="checkbox" onclick="invertAll(this, this.form);" class="input_check" />';

	echo '
				</th>
			</tr>
		</thead>
		<tbody>';

	if (empty($context['rules']))
		echo '
			<tr class="windowbg2">
				<td colspan="2" class="centertext">
					', $txt['pm_rules_none'], '
				</td>
			</tr>';

	$alternate = false;
	foreach ($context['rules'] as $rule)
	{
		echo '
			<tr class="', $alternate ? 'windowbg' : 'windowbg2', '">
				<td>
					<a href="', $scripturl, '?action=pm;sa=manrules;add;rid=', $rule['id'], '">', $rule['name'], '</a>
				</td>
				<td class="centertext" style="width:4%">
					<input type="checkbox" name="delrule[', $rule['id'], ']" class="input_check" />
				</td>
			</tr>';
		$alternate = !$alternate;
	}

	echo '
		</tbody>
		</table>
		<div class="submitbutton">
			<a class="linkbutton" href="', $scripturl, '?action=pm;sa=manrules;add;rid=0">', $txt['pm_add_rule'], '</a>';

	if (!empty($context['rules']))
		echo '
			[<a href="', $scripturl, '?action=pm;sa=manrules;apply;', $context['session_var'], '=', $context['session_id'], '" onclick="return confirm(\'', $txt['pm_js_apply_rules_confirm'], '\');">', $txt['pm_apply_rules'], '</a>]';

	if (!empty($context['rules']))
		echo '
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
			<input type="submit" name="delselected" value="', $txt['pm_delete_selected_rule'], '" onclick="return confirm(\'', $txt['pm_js_delete_rule_confirm'], '\');" class="button_submit smalltext" />';

	echo '
		</div>
	</form>';
}

/**
 * Template for adding/editing a rule.
 */
function template_add_rule()
{
	global $context, $txt, $scripturl;

	echo '
	<script><!-- // --><![CDATA[
		var criteriaNum = 0;
		var actionNum = 0;
		var groups = new Array()
		var labels = new Array()

		var txt_pm_readable_and = "', $txt['pm_readable_and'], '";
		var txt_pm_readable_or = "', $txt['pm_readable_or'], '";
		var txt_pm_readable_member = "', $txt['pm_readable_member'], '";
		var txt_pm_readable_group = "', $txt['pm_readable_group'], '";
		var txt_pm_readable_subject  = "', $txt['pm_readable_subject'], '";
		var txt_pm_readable_body = "', $txt['pm_readable_body'], '";
		var txt_pm_readable_buddy = "', $txt['pm_readable_buddy'], '";
		var txt_pm_readable_label = "', $txt['pm_readable_label'], '";
		var txt_pm_readable_delete = "', $txt['pm_readable_delete'], '";
		var txt_pm_readable_start = "', $txt['pm_readable_start'], '";
		var txt_pm_readable_end = "', $txt['pm_readable_end'], '";
		var txt_pm_readable_then = "', $txt['pm_readable_then'], '";

		var txt_pm_rule_not_defined = "', $txt['pm_rule_not_defined'], '";
		var txt_pm_rule_bud = "', $txt['pm_rule_bud'], '";
		var txt_pm_rule_sub = "', $txt['pm_rule_sub'], '";
		var txt_pm_rule_msg = "', $txt['pm_rule_msg'], '";
		var txt_pm_rule_criteria_pick = "', $txt['pm_rule_criteria_pick'], '";
		var txt_pm_rule_mid = "', $txt['pm_rule_mid'], '";
		var txt_pm_rule_gid = "', $txt['pm_rule_gid'], '";
		var txt_pm_rule_sel_group = "', $txt['pm_rule_sel_group'], '";

		var txt_pm_rule_sel_action = "', $txt['pm_rule_sel_action'], '";
		var txt_pm_rule_label = "', $txt['pm_rule_label'], '";
		var txt_pm_rule_delete = "', $txt['pm_rule_delete'], '";
		var txt_pm_rule_sel_label = "', $txt['pm_rule_sel_label'], '";
		';

	// All of the groups
	foreach ($context['groups'] as $id => $title)
		echo '
		groups[', $id, '] = "', addslashes($title), '";';

	// And any existing labels
	foreach ($context['labels'] as $label)
		if ($label['id'] != -1)
			echo '
		labels[', ($label['id'] + 1), '] = "', addslashes($label['name']), '";';

	echo '
	// ]]></script>';

	echo '
	<form action="', $scripturl, '?action=pm;sa=manrules;save;rid=', $context['rid'], '" method="post" accept-charset="UTF-8" name="addrule" id="addrule" class="flow_hidden">
		<h2 class="category_header">', $context['rid'] == 0 ? $txt['pm_add_rule'] : $txt['pm_edit_rule'], '</h2>
		<div class="windowbg">
			<div class="content">
				<dl class="addrules">
					<dt class="floatleft">
						<strong>', $txt['pm_rule_name'], ':</strong><br />
						<span class="smalltext">', $txt['pm_rule_name_desc'], '</span>
					</dt>
					<dd class="floatleft">
						<input type="text" name="rule_name" value="', empty($context['rule']['name']) ? $txt['pm_rule_name_default'] : $context['rule']['name'], '" size="50" class="input_text" />
					</dd>
				</dl>
				<fieldset>
					<legend>', $txt['pm_rule_criteria'], '</legend>';

	// Add a dummy criteria to allow expansion for none js users.
	$context['rule']['criteria'][] = array('t' => '', 'v' => '');

	// For each criteria print it out.
	$isFirst = true;
	foreach ($context['rule']['criteria'] as $k => $criteria)
	{
		if (!$isFirst && $criteria['t'] == '')
			echo '<div id="removeonjs1">';
		elseif (!$isFirst)
			echo '<br />';

		echo '
					<select name="ruletype[', $k, ']" id="ruletype', $k, '" onchange="updateRuleDef(', $k, '); rebuildRuleDesc();">
						<option value="">', $txt['pm_rule_criteria_pick'], ':</option>
						<option value="mid" ', $criteria['t'] == 'mid' ? 'selected="selected"' : '', '>', $txt['pm_rule_mid'], '</option>
						<option value="gid" ', $criteria['t'] == 'gid' ? 'selected="selected"' : '', '>', $txt['pm_rule_gid'], '</option>
						<option value="sub" ', $criteria['t'] == 'sub' ? 'selected="selected"' : '', '>', $txt['pm_rule_sub'], '</option>
						<option value="msg" ', $criteria['t'] == 'msg' ? 'selected="selected"' : '', '>', $txt['pm_rule_msg'], '</option>
						<option value="bud" ', $criteria['t'] == 'bud' ? 'selected="selected"' : '', '>', $txt['pm_rule_bud'], '</option>
					</select>
					<span id="defdiv', $k, '" ', !in_array($criteria['t'], array('gid', 'bud')) ? '' : 'style="display: none;"', '>
						<input type="text" name="ruledef[', $k, ']" id="ruledef', $k, '" onkeyup="rebuildRuleDesc();" value="', in_array($criteria['t'], array('mid', 'sub', 'msg')) ? $criteria['v'] : '', '" class="input_text" />
					</span>
					<span id="defseldiv', $k, '" ', $criteria['t'] == 'gid' ? '' : 'style="display: none;"', '>
						<select name="ruledefgroup[', $k, ']" id="ruledefgroup', $k, '" onchange="rebuildRuleDesc();">
							<option value="">', $txt['pm_rule_sel_group'], '</option>';

		foreach ($context['groups'] as $id => $group)
			echo '
							<option value="', $id, '" ', $criteria['t'] == 'gid' && $criteria['v'] == $id ? 'selected="selected"' : '', '>', $group, '</option>';

		echo '
						</select>
					</span>';

		// If this is the dummy we add a means to hide for non js users.
		if ($isFirst)
			$isFirst = false;
		elseif ($criteria['t'] == '')
			echo '</div>';
	}

	echo '
					<span id="criteriaAddHere"></span><br />
					<a href="#" onclick="addCriteriaOption(); return false;" id="addonjs1" style="display: none;">(', $txt['pm_rule_criteria_add'], ')</a>
					<br /><br />
					', $txt['pm_rule_logic'], ':
					<select name="rule_logic" id="logic" onchange="rebuildRuleDesc();">
						<option value="and" ', $context['rule']['logic'] == 'and' ? 'selected="selected"' : '', '>', $txt['pm_rule_logic_and'], '</option>
						<option value="or" ', $context['rule']['logic'] == 'or' ? 'selected="selected"' : '', '>', $txt['pm_rule_logic_or'], '</option>
					</select>
				</fieldset>
				<fieldset>
					<legend>', $txt['pm_rule_actions'], '</legend>';

	// As with criteria - add a dummy action for "expansion".
	$context['rule']['actions'][] = array('t' => '', 'v' => '');

	// Print each action.
	$isFirst = true;
	foreach ($context['rule']['actions'] as $k => $action)
	{
		if (!$isFirst && $action['t'] == '')
			echo '<div id="removeonjs2">';
		elseif (!$isFirst)
			echo '<br />';

		echo '
					<select name="acttype[', $k, ']" id="acttype', $k, '" onchange="updateActionDef(', $k, '); rebuildRuleDesc();">
						<option value="">', $txt['pm_rule_sel_action'], ':</option>
						<option value="lab" ', $action['t'] == 'lab' ? 'selected="selected"' : '', '>', $txt['pm_rule_label'], '</option>
						<option value="del" ', $action['t'] == 'del' ? 'selected="selected"' : '', '>', $txt['pm_rule_delete'], '</option>
					</select>
					<span id="labdiv', $k, '">
						<select name="labdef[', $k, ']" id="labdef', $k, '" onchange="rebuildRuleDesc();">
							<option value="">', $txt['pm_rule_sel_label'], '</option>';

		foreach ($context['labels'] as $label)
			if ($label['id'] != -1)
				echo '
							<option value="', ($label['id'] + 1), '" ', $action['t'] == 'lab' && $action['v'] == $label['id'] ? 'selected="selected"' : '', '>', $label['name'], '</option>';

		echo '
						</select>
					</span>';

		if ($isFirst)
			$isFirst = false;
		elseif ($action['t'] == '')
			echo '
				</div>';
	}

	echo '
					<span id="actionAddHere"></span><br />
					<a href="#" onclick="addActionOption(); return false;" id="addonjs2" style="display: none;">(', $txt['pm_rule_add_action'], ')</a>
				</fieldset>
			</div>
			<h3 class="category_header">', $txt['pm_rule_description'], '</h3>
			<div class="information">
				<div id="ruletext">', $txt['pm_rule_js_disabled'], '</div>
			</div>
			<div class="submitbutton">
				<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
				<input type="submit" name="save" value="', $txt['pm_rule_save'], '" class="button_submit" />
			</div>
		</div>
	</form>';

	// Now setup all the bits!
	echo '
	<script><!-- // --><![CDATA[';

	foreach ($context['rule']['criteria'] as $k => $c)
		echo '
			updateRuleDef(', $k, ');';

	foreach ($context['rule']['actions'] as $k => $c)
		echo '
			updateActionDef(', $k, ');';

	echo '
			rebuildRuleDesc();';

	// If this isn't a new rule and we have JS enabled remove the JS compatibility stuff.
	if ($context['rid'])
		echo '
			document.getElementById("removeonjs1").style.display = "none";
			document.getElementById("removeonjs2").style.display = "none";';

	echo '
			document.getElementById("addonjs1").style.display = "";
			document.getElementById("addonjs2").style.display = "";';

	echo '
		// ]]></script>';
}

/**
 * Template for showing all the PM drafts of the user.
 */
function template_showPMDrafts()
{
	global $context, $settings, $scripturl, $txt;

	echo '
		<h2 class="category_header">
			<img src="', $settings['images_url'], '/message_sm.png" alt="" class="icon" />
				', $txt['drafts_show'], '
		</h2>';
	template_pagesection();

	// No drafts? Just show an informative message.
	if (empty($context['drafts']))
		echo '
		<div class="information centertext">
			', $txt['draft_none'], '
		</div>';
	else
	{
		// For every draft to be displayed, give it its own div, and show the important details of the draft.
		foreach ($context['drafts'] as $draft)
		{
			echo '
		<div class="', $draft['alternate'] === 0 ? 'windowbg2' : 'windowbg', ' core_posts">
			<div class="content">
				<div class="counter">', $draft['counter'], '</div>
				<div class="topic_details">
					<h5>
						<strong>', $draft['subject'], '</strong>&nbsp;
					</h5>
					<span class="smalltext">&#171;&nbsp;<strong>', $txt['draft_saved_on'], ':</strong> ', sprintf($txt['draft_days_ago'], $draft['age']), (!empty($draft['remaining']) ? ', ' . sprintf($txt['draft_retain'], $draft['remaining']) : ''), '&#187;</span>
					<br />
					<span class="smalltext">&#171;&nbsp;<strong>', $txt['to'], ':</strong> ', implode(', ', $draft['recipients']['to']), '&nbsp;&#187;</span>
					<br />
					<span class="smalltext">&#171;&nbsp;<strong>', $txt['pm_bcc'], ':</strong> ', implode(', ', $draft['recipients']['bcc']), '&nbsp;&#187;</span>
				</div>
				<div class="list_posts">
					', $draft['body'], '
				</div>
				<ul class="quickbuttons">
					<li class="listlevel1">
						<a href="', $scripturl, '?action=pm;sa=showpmdrafts;id_draft=', $draft['id_draft'], ';', $context['session_var'], '=', $context['session_id'], '" class="linklevel1 reply_button">', $txt['draft_edit'], '</a>
					</li>
					<li class="listlevel1">
						<a href="', $scripturl, '?action=pm;sa=showpmdrafts;delete=', $draft['id_draft'], ';', $context['session_var'], '=', $context['session_id'], '" onclick="return confirm(\'', $txt['draft_remove'], '?\');" class="linklevel1 remove_button">', $txt['draft_delete'], '</a>
					</li>
				</ul>
			</div>
		</div>';
		}
	}

	// Show page numbers.
	template_pagesection();
}