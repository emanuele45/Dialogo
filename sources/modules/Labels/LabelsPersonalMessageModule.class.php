<?php

/**
 * Integration system for labels into PersonalMessage controller
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

class Labels_PersonalMessage_Module extends Action_Controller
{
	protected $_labels_obj = null;
	protected $_pm_list = null;

	public static function hooks()
	{
		add_integration_function('integrate_sa_pm_index', 'Labels_PersonalMessage_Module::integrate_pm_areas', '', false);

		return array(
			array('pre_dispatch', array('Labels_PersonalMessage_Module', 'listen_pre_dispatch'), array('user_info', 'pm_list', 'redirect_url_fragment')),
// 			array('before_sending', array('Drafts_PersonalMessage_Module', 'before_sending'), array('recipientList')),
		);
	}

	public static function integrate_pm_areas(&$subActions)
	{
		global $scripturl, $txt;

		$subActions['manlabels'] = array('controller' => 'Labels_PersonalMessage_Module', 'function' => 'action_manlabels', 'permission' => 'pm_read');
	}

	public function listen_pre_dispatch($user_info, $pm_list, &$redirect_url_fragment)
	{
		global $context;

		$this->_labels_obj = new Personal_Message_Labels($user_info, database());
		$this->_pm_list = $pm_list;

		$context['labels'] = $this->_labels_obj->getLabels();

		// Now we have the labels, and assuming we have unsorted mail, apply our rules!
		if ($user_info['pm']['new'])
		{
			// Apply our rules to the new PM's
			$this->_pm_list->applyRules();
			require_once(SUBSDIR . '/Members.subs.php');
			updateMemberData($user_info['id'], array('new_pm' => 0));

			// Turn the new PM's status off, for the popup alert, since they have entered the PM area
			$this->_pm_list->toggleNewPM();
		}

		// Load the label data.
		$context['labels'] = $this->_labels_obj->countLabels($user_info['pm']['new']);

		// This determines if we have more labels than just the standard inbox.
		$context['currently_using_labels'] = count($context['labels']) > 1 ? 1 : 0;

		$context['current_label_id'] = isset($_REQUEST['l']) && isset($context['labels'][(int) $_REQUEST['l']]) ? (int) $_REQUEST['l'] : -1;
		$context['current_label'] = &$context['labels'][$context['current_label_id']]['name'];
		$redirect_url_fragment .= isset($_REQUEST['l']) ? ';l=' . $_REQUEST['l'] : '';
	}

	/**
	 * @todo this should be moved to a standalone controller
	 */
	public function action_index()
	{
		return $this->action_manlabels();
	}

	/**
	 * This function handles adding, deleting and editing labels on messages.
	 * @todo this should be moved to a standalone controller
	 */
	public function action_manlabels()
	{
		global $txt, $context, $user_info, $scripturl;

		// Build the link tree elements...
		$context['linktree'][] = array(
			'url' => $scripturl . '?action=pm;sa=manlabels',
			'name' => $txt['pm_manage_labels']
		);

		// Some things for the template
		$context['page_title'] = $txt['pm_manage_labels'];
		$context['sub_template'] = 'labels';

		// Submitting changes?
		if (isset($_POST['add']) || isset($_POST['delete']) || isset($_POST['save']))
		{
			// Add all existing labels to the array to save, slashing them as necessary...
			$to_insert = array();
			$to_update = array();
			$to_delete = array();

			checkSession('post');

			// This will be for updating messages.
			$rule_changes = array();

			// Will most likely need this.
			$this->_pm_list->loadRules();

			// Adding a new label?
			if (isset($_POST['add']))
			{
				$label_text = trim(strtr(Util::htmlspecialchars($_POST['label']), array(',' => '&#044;')));

				if ($label_text != '')
					$to_insert[] = $label_text;
			}
			// Deleting an existing label?
			elseif (isset($_POST['delete'], $_POST['delete_label']))
			{
				foreach ($_POST['delete_label'] as $id => $dummy)
				{
					if (!isset($context['labels'][$id]))
						continue;

					$to_delete[] = $id;
				}
			}
			// The hardest one to deal with... changes.
			elseif (isset($_POST['save']) && !empty($_POST['label_name']))
			{
				foreach ($_POST['label_name'] as $id => $value)
				{
					if (!isset($context['labels'][$id]))
						continue;

					// Prepare the label name
					$label_text = trim(strtr(Util::htmlspecialchars($value), array(',' => '&#044;')));

					if ($label_text == '')
					{
						$to_delete[] = (int) $id;
					}
					else
					{
						$to_update[(int) $id] = $label_text;
					}
				}
			}

			// Save the label status.
			if (!empty($to_insert))
				$this->_pm_list->addLabels($to_insert);

			if (!empty($to_update))
				$this->_pm_list->updateLabels($to_update);

			if (!empty($to_delete))
			{
				$this->_pm_list->removeLabelsFromPMs($to_delete);

				// Now do the same the rules - check through each rule.
				foreach ($context['rules'] as $k => $rule)
				{
					// Each action...
					foreach ($rule['actions'] as $k2 => $action)
					{
						if ($action['t'] != 'lab' || !in_array($action['v'], $to_delete))
							continue;

						$rule_changes[] = $rule['id'];

						unset($context['rules'][$k]['actions'][$k2]);
					}
				}
			}

			// If we have rules to change do so now.
			if (!empty($rule_changes))
			{
				$rule_changes = array_unique($rule_changes);

				// Update/delete as appropriate.
				foreach ($rule_changes as $k => $id)
					if (!empty($context['rules'][$id]['actions']))
					{
						$this->_pm_list->updatePMRuleAction($id, $user_info['id'], $context['rules'][$id]['actions']);
						unset($rule_changes[$k]);
					}

				// Anything left here means it's lost all actions...
				if (!empty($rule_changes))
					$this->_pm_list->deletePMRules($user_info['id'], $rule_changes);
			}

			// Make sure we're not caching this!
			cache_put_data('labelCounts__' . $user_info['id'], null, 720);

			// To make the changes appear right away, redirect.
			redirectexit('action=pm;sa=manlabels');
		}
	}
}