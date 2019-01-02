<?php
/**
 * Created by PhpStorm.
 * User: emanuele
 * Date: 30/12/18
 * Time: 13.12
 */

namespace ElkArte\Attachments;


use ElkArte\Exceptions\Exception;

class Path
{
	const NORMAL = 0;
	const AUTO_SPACE = 1;
	const AUTO_YEARS = 2;
	const AUTO_MONTHS = 3;
	const AUTO_RANDOM = 4;
	const AUTO_RANDOM_2 = 5;

	/**
	 * @var mixed[]
	 */
	protected $options = [];

	/**
	 * @var string
	 */
	protected $clean_separator = '';
	/**
	 * @var string
	 */
	protected $active_dir = '';

	/**
	 * @var int
	 */
	protected $active_dir_id = 0;
	/**
	 * @var string[]
	 */
	protected $valid_dirs = [];
	/**
	 * @var string[]
	 */
	protected $baseDirectories = [];
	/**
	 * @var int[]
	 */
	protected $last_attachments_directory = [];

	/**
	 * @var int
	 */
	protected $dir_size = 0;

	/**
	 * @var int
	 */
	protected $dir_num_files = 0;

	/**
	 * @var \ElkArte\Database\QueryInterface|null
	 */
	protected $db = null;

	/**
	 * @var int
	 */
	protected $mode = 0;

	public function __construct(\ElkArte\Database\QueryInterface $db, $options)
	{
		$this->db = $db;
		$this->options = $options;
		$this->mode = $this->options['automanage_attachments'];
		/*
			In Windows server both \ and / can be used as directory separators in paths
			In Linux (and presumably *nix) servers \ can be part of the name
			So for this reasons:
				* in Windows we need to explode for both \ and /
				* while in linux should be safe to explode only for / (aka DIRECTORY_SEPARATOR)
		*/
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
		{
			$this->clean_separator = '\\/';
		}
		else
		{
			$this->clean_separator = '/';
		}

		$this->findCurrentAttachmentPath();
	}

	/**
	 * Physically create a directory.
	 *
	 * What it does:
	 *
	 * - Attempts to make the directory writable
	 * - Places an .htaccess in new directories for security
	 *
	 * @package Attachments
	 *
	 * @param string $updir
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected function createDirectory($updir)
	{
		// First attempt if the directory as-is, second attempt is the assumption that $updir is
		// just the name of the directory and not the full path.
		foreach ([$updir, BOARDDIR . '/' . $updir] as $dir)
		{
			$tree = $this->getDirectoryTreeElements($dir);
			$count = count($tree);

			$directory = !empty($tree) ? $this->initDir($tree, $count) : false;
			if ($directory !== false)
			{
				break;
			}
		}
		if ($directory === false)
		{
			return false;
		}

		$directory .= '/' . array_shift($tree);

		while (!@is_dir($directory) || $count != -1)
		{
			if (!@is_dir($directory))
			{
				if (!@mkdir($directory, 0755))
				{
					throw new Exception('attachments_no_create');
				}
			}

			$directory .= '/' . array_shift($tree);
			$count--;
		}

		// Try to make it writable, 3 tries from more strict to fully writable
		$writable = is_writable($directory);
		$chmods = [0755, 0775, 0777];
		for ($i = 0; $i < count($chmods); $i++)
		{
			if ($writable === true)
			{
				break;
			}
			chmod($directory, $chmods[$i]);
			$writable = is_writable($directory);
		}
		if ($writable === false)
		{
			throw new Exception('attachments_no_write');
		}

		// Everything seems fine...let's create the .htaccess
		secureDirectory($updir, true);

		$updir = rtrim($updir, $this->clean_separator);

		// Only update if it's a new directory
		if (!in_array($updir, $this->valid_dirs))
		{
			$this->active_dir_id = max(array_keys($this->valid_dirs)) + 1;
			$this->valid_dirs[$this->active_dir_id] = $updir;

			updateSettings(array(
				'attachmentUploadDir' => serialize($this->valid_dirs),
				'currentAttachmentUploadDir' => $this->active_dir_id,
			), true);
			$this->active_dir = $this->valid_dirs[$this->active_dir_id];
		}

		return true;
	}

	/**
	 * Finds the current directory tree for the supplied base directory
	 *
	 * @package Attachments
	 * @param string $directory
	 * @return string[] array of directory names
	 */
	protected function getDirectoryTreeElements($directory)
	{
		$tree = preg_split('#[' . preg_quote($this->clean_separator, '#') . ']#', $directory);

		return $tree;
	}

	/**
	 * Helper function for createDirectory
	 *
	 * What it does:
	 *
	 * - Gets the directory w/o drive letter for windows
	 *
	 * @package Attachments
	 *
	 * @param string[] $tree
	 * @param int $count
	 *
	 * @return bool|mixed|string
	 */
	protected function initDir(&$tree, &$count)
	{
		$directory = '';

		// If on Windows servers the first part of the path is the drive (e.g. "C:")
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
		{
			// Better be sure that the first part of the path is actually a drive letter...
			// ...even if, I should check this in the admin page...isn't it?
			// ...NHAAA Let's leave space for users' complains! :P
			if (preg_match('/^[a-z]:$/i', $tree[0]))
			{
				$directory = array_shift($tree);
			}
			else
			{
				return false;
			}

			$count--;
		}

		return $directory;
	}

	/**
	 * Determines the current attachments path:
	 *
	 * What it does:
	 *  - BOARDDIR . '/attachments', if nothing is set yet.
	 *  - if the forum is using multiple attachments directories,
	 *    then the current path is stored as unserialize($modSettings['attachmentUploadDir'])[$modSettings['currentAttachmentUploadDir']]
	 *  - otherwise, the current path is $modSettings['attachmentUploadDir'].
	 */
	protected function findCurrentAttachmentPath()
	{
		if (!empty($this->options['attachmentUploadDir']))
		{
			$this->active_dir_id = 0;
			$this->valid_dirs = [BOARDDIR . '/attachments'];
		}
		elseif (!empty($this->options['currentAttachmentsUploadDir']))
		{
			// @todo this is here to prevent the package manager to die when complete the installation of the patch (the new Util class has not yet been loaded so we need the normal one)
			// Even though now we rely on the class loader, so it should not be an issue.
			if (function_exists('\\ElkArte\\Util::unserialize'))
			{
				$this->valid_dirs = \ElkArte\Util::unserialize($this->options['attachmentUploadDir']);
			}
			else
			{
				$this->valid_dirs = unserialize($this->options['attachmentUploadDir']);
			}
			$this->active_dir_id = $this->options['currentAttachmentUploadDir'];
		}
		else
		{
			$this->active_dir_id = 0;
			$this->valid_dirs = [$this->options['attachmentUploadDir']];
		}
		$this->active_dir = $this->valid_dirs[$this->active_dir_id];
		if (!empty($this->options['attachment_basedirectories']) && empty($this->baseDirectories))
		{
			$this->baseDirectories = \ElkArte\Util::unserialize($this->options['attachment_basedirectories']);
		}
		if (!empty($this->options['last_attachments_directory']))
		{
			$this->last_attachments_directory = \ElkArte\Util::unserialize($this->options['last_attachments_directory']);
		}
	}

	/**
	 * Returns the current attachments path.
	 *
	 * @return string
	 */
	public function getAttachmentPath($force = false)
	{
		$this->checkDirectory();
		return $this->active_dir;
	}

	/**
	 * Returns the current attachments path.
	 *
	 * @return string
	 */
	public function getActivePath()
	{
		return $this->active_dir;
	}

	/**
	 * Returns the current attachments path.
	 *
	 * @return string
	 */
	public function setAttachmentPath($path)
	{
		$this->createDirectory($path);
		return $this->active_dir;
	}

	/**
	 * Returns the current attachments path.
	 *
	 * @return string
	 */
	public function getPathById($id)
	{
		if (isset($this->valid_dirs[$id]))
		{
			return $this->valid_dirs[$id];
		}
		else
		{
			return false;
		}
	}

	/**
	 * The avatars path: if custom avatar directory is set, that's it.
	 * Otherwise, it's attachments path.
	 *
	 * @package Attachments
	 * @return string
	 */
	public function getAvatarPath()
	{
		if ($this->options['custom_avatar_enabled'])
		{
			return $this->getAttachmentPath();
		}
		else
		{
			return $this->options['custom_avatar_dir'];
		}
	}

	/**
	 * Check and create a directory automatically.
	 *
	 * @package Attachments
	 */
	protected function checkDirectory()
	{
		$doit = false;
		// Not pretty, but since we don't want folders created for every post.
		// It'll do unless a better solution can be found.
		if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'admin')
		{
			$doit = true;
		}
		elseif ($this->mode === self::NORMAL)
		{
			$doit = false;
		}
		elseif (!isset($_FILES))
		{
			$doit = false;
		}
		elseif (isset($_FILES['attachment']))
		{
			foreach ($_FILES['attachment']['tmp_name'] as $dummy)
			{
				if (!empty($dummy))
				{
					$doit = true;
					break;
				}
			}
		}

		if ($doit === false)
		{
			return;
		}

		$new_dir = $this->getNewDirectoryName();
		if  (!empty($new_dir))
		{
			$this->createDirectory($new_dir);
		}
		else
		{
			throw new Exception('directory_error');
		}
	}

	protected function loadCurrentDirectoryStatus($refresh = false)
	{
		// Check the folder size and count. If it hasn't been done already.
		if (empty($this->dir_size) || empty($this->dir_num_files) || $refresh === true)
		{
			$request = $this->db->query('', '
					SELECT COUNT(*), SUM(size)
					FROM {db_prefix}attachments
					WHERE id_folder = {int:folder_id}
						AND attachment_type != {int:type}',
				array(
					'folder_id' => $this->options['currentAttachmentUploadDir'],
					'type' => 1,
				)
			);
			list ($this->dir_num_files, $this->dir_size) = $request->fetch_row();
			$request->free_result();
		}
	}

	public function isCloseToLimits($new_attach_size = 0)
	{
		// Is there room for this in the directory?
		if (!empty($this->options['attachmentDirSizeLimit']) || !empty($this->options['attachmentDirFileLimit']))
		{
			$this->loadCurrentDirectoryStatus();
			$new_dir_size = $this->dir_size + $new_attach_size;
			$new_dir_files = $this->dir_num_files + (!empty($new_attach_size) ? 0 : 1);

			// Are we about to run out of room? Let's notify the admin then.
			if (!empty($this->options['attachmentDirSizeLimit']) && $this->options['attachmentDirSizeLimit'] > 4000 && $new_dir_size > ($this->options['attachmentDirSizeLimit'] - 2000) * 1024)
			{
				return true;
			}
			if (!empty($this->options['attachmentDirFileLimit']) && $this->options['attachmentDirFileLimit'] * .95 < $new_dir_files && $this->options['attachmentDirFileLimit'] > 500)
			{
				return true;
			}
		}
	}

	public function limitReached($new_attach_size = 0)
	{
		// Is there room for this in the directory?
		if (!empty($this->options['attachmentDirSizeLimit']) || !empty($this->options['attachmentDirFileLimit']))
		{
			$this->loadCurrentDirectoryStatus();
			$new_dir_size = $this->dir_size + $new_attach_size;
			$new_dir_files = $this->dir_num_files + (!empty($new_attach_size) ? 0 : 1);

			// Are we about to run out of room? Let's notify the admin then.
			if (!empty($this->options['attachmentDirSizeLimit']) && $new_dir_size > $this->options['attachmentDirSizeLimit'] * 1024)
			{
				return true;
			}
			if (!empty($this->options['attachmentDirFileLimit']) && $this->options['attachmentDirFileLimit'] < $new_dir_files)
			{
				return true;
			}
		}

		return false;
	}

	protected function newSpaceDirectory($base_dir_idx, $force = false)
	{
		$this->loadCurrentDirectoryStatus(true);
		if ($this->limitReached() || $force)
		{
			$this->last_attachments_directory[$base_dir_idx]++;
		}
	}

	/**
	 * This function returns the full path of a new directory for attachments.
	 *
	 * @return string
	 */
	protected function getNewDirectoryName($force = false)
	{
		// Get our date and random numbers for the directory choices
		$year = date('Y');
		$month = date('m');

		$rand = md5(mt_rand());
		$rand1 = $rand[1];
		$rand = $rand[0];

		if (!empty($this->baseDirectories) && !empty($this->options['use_subdirectories_for_attachments']))
		{
			$base_dir_idx = array_search($this->options['basedirectory_for_attachments'], $this->baseDirectories);
		}
		else
		{
			$base_dir_idx = 0;
		}
		// Get the last attachment directory for that base directory
		if (!isset($this->last_attachments_directory[$base_dir_idx]))
		{
			$this->last_attachments_directory[$base_dir_idx] = 0;
		}

		if (!empty($this->options['use_subdirectories_for_attachments']))
		{
			$basedirectory = $this->options['basedirectory_for_attachments'];
			$prefix = 'attachments-';
		}
		else
		{
			$basedirectory = BOARDDIR;
			$prefix = 'random-';
		}

		// Just to be sure: I don't want directory separators at the end
		$basedirectory = rtrim($basedirectory, $this->clean_separator);

		switch ($this->mode)
		{
			case self::AUTO_SPACE:
				$this->newSpaceDirectory($base_dir_idx, $force);
				$updir = $basedirectory . '/' . 'attachments_' . $this->last_attachments_directory[$base_dir_idx];
				break;
			case self::AUTO_YEARS:
				$updir = $basedirectory . '/' . $year;
				break;
			case self::AUTO_MONTHS:
				$updir = $basedirectory . '/' . $year . '/' . $month;
				break;
			case self::AUTO_RANDOM:
				$updir = $basedirectory . '/' . $prefix . $rand;
				break;
			case self::AUTO_RANDOM_2:
				$updir = $basedirectory . '/' . $prefix . $rand . '/' . $rand1;
				break;
			default:
				$updir = '';
		}

		return $updir;
	}
//------ To work on ------//

	/**
	 * Little utility function for the $id_folder computation for attachments.
	 *
	 * What it does:
	 *
	 * - This returns the id of the folder where the attachment or avatar will be saved.
	 * - If multiple attachment directories are not enabled, this will be 1 by default.
	 *
	 * @package Attachments
	 * @return int 1 if multiple attachment directories are not enabled,
	 * or the id of the current attachment directory otherwise.
	 */
	public function getAttachmentPathID()
	{
		return $this->active_dir_id;
	}

	/**
	 * Returns the ID of the folder avatars are currently saved in.
	 *
	 * @package Attachments
	 * @return int 1 if custom avatar directory is enabled,
	 * and the ID of the current attachment folder otherwise.
	 * NB: the latter could also be 1.
	 */
	public function getAvatarPathID()
	{
		global $modSettings;

		// Little utility function for the endless $id_folder computation for avatars.
		if (!empty($modSettings['custom_avatar_enabled']))
			return 1;
		else
			return $this->getAttachmentPathID();
	}
}