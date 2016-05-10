<?php

namespace ElkArte\SSI;

class Welcome_Block extends SSI_Abstract_Block
{
	/**
	 * {@inheritdoc }
	 */
	public function __construct($db = null)
	{
		$this->template = array($this, 'render_echo');

		parent::__construct($db);
	}

	/**
	 * {@inheritdoc }
	 */
	public function setup($parameters)
	{
		global $context, $txt, $scripturl;

		if (allowedTo('pm_read'))
		{
			if (empty($parameters['messages']))
			{
				$txt_message = $txt['msg_alert_no_messages'];
			}
			else
			{
				if ($parameters['messages'] == 1)
				{
					$txt_message = sprintf($txt['msg_alert_one_message'], $scripturl . '?action=pm');
				}
				else
				{
					$txt_message = sprintf($txt['msg_alert_many_message'], $scripturl . '?action=pm', $parameters['messages']);
				}
				if ($parameters['unread_messages'] == 1)
				{
					$txt_message .= $txt['msg_alert_one_new'];
				}
				else
				{
					$txt_message .= sprintf($txt['msg_alert_many_new'], $parameters['unread_messages']);
				}
			}
		}
		else
		{
			$txt_message = '';
		}

		$this->data = array(
			'is_guest' => $parameters['is_guest'],
			'can_register' => $parameters['can_register'],
			'can_read_pm' => allowedTo('pm_read'),
			'unread_pm' => $parameters['messages'],
			'name' => $parameters['name'],
			'text_messages' => $txt_message,
		);
	}

	protected function render_echo()
	{
		global $txt;

		if ($this->data['is_guest'])
		{
			echo replaceBasicActionUrl($txt[$context['can_register'] ? 'welcome_guest_register' : 'welcome_guest']);
		}
		else
		{
			echo $txt['hello_member'], ' <strong>', $this->data['name'], '</strong>', $this->data['can_read_pm'] ? ', ' . $this->data['text_messages'] : '';
		}
	}
}