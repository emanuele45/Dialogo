<?php

/**
 * The interface any SSI block shall implement.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 beta 1
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Abstract Simple Portal block
 *
 * - Implements Sp_Block
 * - Sets base functionality for use in blocks
 */
interface SSI_Block_Interface
{

	/**
	 * Class constuctor, makes db availalbe
	 *
	 * @param Database|null $db
	 */
	public function __construct($db = null);

	/**
	 * Returns optional block parameters
	 *
	 * @return mixed[]
	 */
	public function parameters();

	/**
	 * Sets the template name that will be called via render
	 *
	 * @param callable $template
	 */
	public function setTemplate(callable $template);

	/**
	 * @todo
	 */
	public function setup($parameters);

	/**
	 * Renders a block with a given template and data
	 */
	public function render();

	/**
	 * Returns the data for external usage
	 */
	public function getData();

	/**
	 * Validates that a user can access a block
	 *
	 * @return string[]
	 */
	public static function permissionsRequired();
}