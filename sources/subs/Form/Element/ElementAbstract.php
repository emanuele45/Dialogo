<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace ElkArte\sources\subs\Form\Element;

abstract class ElementAbstract implements ElementInterface
{
	protected $_config;

	public function getName()
	{
		if (isset($this->_config['name']))
			return $this->_config['name'];
		else
			return null;
	}

	abstract public function getData();

	public function isValid($data)
	{
		return true;
	}
}