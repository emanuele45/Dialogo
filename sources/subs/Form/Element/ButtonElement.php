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

class ButtonElement extends ElementAbstract
{
	public function __construct($name, $value, $options = null)
	{
		$config = array(
			'template' => 'button',
			'id' => $name,
			'name' => $name,
			'value' => $value
		);

		if ($options !== null)
			$config = array_merge($this->_config, (array) $options);

		$this->_config = new ValuesContainer($config);
	}

	public function getData()
	{
		return array(
			'template' => $this->_config['template'],
			'data' => $this->_config->toArray(),
		);
	}

	public function isValid($data);
}