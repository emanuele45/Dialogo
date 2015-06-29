<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace ElkArte\Form;

class Form
{
	protected $_options = array();
	protected $_elements = array();

	public function __construct($options = null)
	{
		loadTemplate('Form');
		$this->_options = $options;
	}

	public function addElement(Element $element)
	{
		$this->_elements[$element->getName()] = $element;
	}

	public function validate($rawdata)
	{
		$is_valid = true;

		foreach ($this->_elements as $name => $element)
		{
			$key = $this->_addPrefix($name);
			if (isset($rawdata[$key]))
				$is_valid = $is_valid && $element->isValid($rawdata[$key]);
		}

		return $is_valid;
	}

	public function getFormContext()
	{
		$return = array();

		foreach ($this->_elements as $element)
		{
			$data = $element->getData();
			if (isset($this->_options['prefix']))
			{
				foreach (array('id', 'name') as $key)
					$data[$key] = $this->_options['prefix'] . '_' . $data[$key];
			}

			$return[] = $data;
		}

		return $return;
	}
}