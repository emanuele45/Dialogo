<?php

/**
 * The base for any SSI-like block.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * SimplePortal (SP)
 * copyright:	2015 SimplePortal Team (http://simpleportal.org)
 * license:  	BSD 3-clause
 *
 * @version 1.1 beta 1
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Abstract SSI block
 *
 * - Implements SSI_Block_Interface
 * - Sets base functionality for use in blocks
 */
abstract class SSI_Abstract_Block implements SSI_Block_Interface
{
	/**
	 * Database object
	 * @var object
	 */
	protected $_db = null;

	/**
	 * Block parameters
	 * @var array
	 */
	protected $block_parameters = array();

	/**
	 * Data array for use in the blocks
	 * @var mixed[]
	 */
	protected $data = array();

	/**
	 * Name of the template function to call
	 * @var string|mixed[]
	 */
	protected $template = '';

	/**
	 * {@inheritdoc }
	 */
	public function __construct($db = null)
	{
		$this->_db = $db;
	}

	/**
	 * {@inheritdoc }
	 */
	public function parameters()
	{
		return $this->block_parameters;
	}

	/**
	 * {@inheritdoc }
	 */
	public function setTemplate(callable $template)
	{
		$this->template = $template;
	}

	/**
	 * {@inheritdoc }
	 */
	abstract public function setup($parameters);

	/**
	 * {@inheritdoc }
	 */
	public function render()
	{
		if (is_callable($this->template))
			call_user_func_array($this->template, array($this->data));
	}

	/**
	 * {@inheritdoc }
	 */
	public function getData()
	{
		return $this->data;
	}

	/**
	 * {@inheritdoc }
	 */
	public static function permissionsRequired()
	{
		return array();
	}
}