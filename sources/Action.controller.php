<?php

/**
 * Abstract base class for controllers. Holds action_index and pre_dispatch
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Release Candidate 2
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Abstract base class for controllers.
 *
 * - Requires a default action handler, action_index().
 * - Defines an empty implementation for pre_dispatch() method.
 */
abstract class Action_Controller
{
	/**
	 * An (unique) id that triggers a hook
	 * @var string
	 */
	protected $_name = null;

	/**
	 * Default action handler.
	 *
	 * - This will be called by the dispatcher in many cases.
	 * - It may set up a menu, sub-dispatch at its turn to the method matching ?sa= parameter
	 * or simply forward the request to a known default method.
	 */
	abstract public function action_index();

	/**
	 * Called before any other action method in this class.
	 *
	 * - Allows for initializations, such as default values or
	 * loading templates or language files.
	 */
	public function pre_dispatch()
	{
		// By default, do nothing.
		// Sub-classes may implement their prerequisite loading,
		// such as load the template, load the language(s) file(s)
	}

	protected function dispatch()
	{
		if ($this->_name !== null)
			call_integration_hook('integrate_sa_' . $this->_name);

		$this->_controller = $this;

		if (isset($_REQUEST['sa']))
		{
			$this->_possible = $_REQUEST['sa'];

			if (is_callable(array($this, 'action_' . $this->_possible)))
			{
				$this->_action = $this->_possible;
			}
			elseif (is_callable(array(ucfirst($this->_possible) . '_Subaction_Controller', 'action_' . $this->_possible)))
			{
				$controller_name = ucfirst($this->_possible) . '_Subaction_Controller';
				$this->_controller = new $controller_name();
				$this->_action = $this->_possible;
			}
		}

		// If no action found, let the controller handle it, maybe there is something special
		if (empty($this->_action))
			return false;

		$this->_action = 'action_' . $this->_action;
		$this->_controller->{$this->_action}();
	}
}