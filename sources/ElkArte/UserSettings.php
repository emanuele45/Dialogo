<?php

/**
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 *
 * @version 2.0 dev
 *
 */

namespace ElkArte;

/**
 * This class holds all the data belonging to a certain member.
 */
class UserSettings
{
	/**
	 * @var int
	 */
	const HASH_LENGTH = 4;

	/**
	 * @var int
	 */
	const BAN_OFFSET = 10;

	/**
	 * The user id
	 *
	 * @var int
	 */
	protected $id = 0;

	/**
	 * The member_name field from the db
	 *
	 * @var string
	 */
	protected $username = '';

	/**
	 * The database object
	 *
	 * @var \ElkArte\Database\QueryInterface
	 */
	protected $db = null;

	/**
	 * The cache object
	 *
	 * @var \ElkArte\Cache\Cache
	 */
	protected $cache = null;

	/**
	 * The request object
	 *
	 * @var \ElkArte\Request
	 */
	protected $req = null;

	/**
	 * The settings data
	 *
	 * @var \ElkArte\ValuesContainerReadOnly
	 */
	protected $settings = null;

	/**
	 * The into data
	 *
	 * @var \ElkArte\ValuesContainer
	 */
	protected $info = null;

	/**
	 * Constructor
	 *
	 * @param \ElkArte\Database\QueryInterface $db
	 * @param \ElkArte\Cache\Cache $cache
	 * @param \ElkArte\Request $req
	 */
	public function __construct($db, $cache, $req)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->req = $req;
	}

	/**
	 * Returns the user settings
	 *
	 * @return \ElkArte\ValuesContainerReadOnly
	 */
	public function getSettings()
	{
		return $this->settings;
	}

	/**
	 * Returns the user into
	 *
	 * @return \ElkArte\ValuesContainer
	 */
	public function getInfo()
	{
		return $this->info;
	}

	/**
	 * Loads from the db the data of a user based on the member id
	 *
	 * @param int $id
	 * @param bool $already_verified
	 * @param string $session_password
	 *
	 * @event integrate_user_info
	 */
	public function loadUserById($id, $already_verified, $session_password)
	{
		$this->id = $id;

		if ($this->id != 0)
		{
			$this->loadUserData($already_verified, $session_password);
			$user_info = $this->initUser();
		}
		else
		{
			$user_info = $this->initGuest();
		}
		\ElkArte\Hooks::instance()->hook('integrate_user_info', [$user_info]);

		$this->compileInfo($user_info);
	}

	/**
	 * Repeat the hashing of the password, either because it was not previously
	 * hashed or because it needs a new one
	 *
	 * @param string $password
	 */
	public function rehashPassword($password)
	{
		$this->settings->rehashPassword($password);
	}

	/**
	 * Checkes the provided password is the same as the one in the database.
	 *
	 * @param string $password
	 * @return bool
	 */
	public function validatePassword($password)
	{
		return $this->settings->validatePassword($password);
	}

	/**
	 * Check activation status of the current user.
	 *
	 * What it does:
	 * is_activated value key is as follows:
	 * - > 10 Banned with activation status as value - 10
	 * - 5 = Awaiting COPPA consent
	 * - 4 = Awaiting Deletion approval
	 * - 3 = Awaiting Admin approval
	 * - 2 = Awaiting reactivation from email change
	 * - 1 = Approved and active
	 * - 0 = Not active
	 *
	 * @param bool $undelete - whether the current request is to revert the
	 *                         request to delete the account or not
	 */
	public function checkActivation($undelete)
	{
		global $context, $txt, $modSettings;

		if (!isset($context['login_errors']))
		{
			$context['login_errors'] = array();
		}

		// What is the true activation status of this account?
		$activation_status = $this->settings->getActivationStatus();

		// Check if the account is activated - COPPA first...
		if ($activation_status == 5)
		{
			$context['login_errors'][] = $txt['coppa_no_concent'] . ' <a href="' . getUrl('action', ['action' => 'register', 'sa' => 'coppa', 'member' => $this->settings['id_member']]) . '">' . $txt['coppa_need_more_details'] . '</a>';
			return false;
		}
		// Awaiting approval still?
		elseif ($activation_status == 3)
		{
			throw new \ElkArte\Exceptions\Exception('still_awaiting_approval', 'user');
		}
		// Awaiting deletion, changed their mind?
		elseif ($activation_status == 4)
		{
			if ($undelete === true)
			{
				require_once(SUBSDIR . '/Members.subs.php');
				updateMemberData($this->settings['id_member'], array('is_activated' => 1));
				updateSettings(array('unapprovedMembers' => ($modSettings['unapprovedMembers'] > 0 ? $modSettings['unapprovedMembers'] - 1 : 0)));
			}
			else
			{
				$context['disable_login_hashing'] = true;
				$context['login_errors'][] = $txt['awaiting_delete_account'];
				$context['login_show_undelete'] = true;
				return false;
			}
		}
		// Standard activation?
		elseif ($activation_status != 1)
		{
			\ElkArte\Errors\Errors::instance()->log_error($txt['activate_not_completed1'] . ' - <span class="remove">' . $this->settings['member_name'] . '</span>', false);

			$context['login_errors'][] = $txt['activate_not_completed1'] . ' <a class="linkbutton" href="' . getUrl('action', ['action' => 'register', 'sa' => 'activate', 'resend', 'u' => $this->settings['id_member']]) . '">' . $txt['activate_not_completed2'] . '</a>';
			return false;
		}
		return true;
	}

	/**
	 * Fills up the $this->info variable
	 *
	 * @param mixed[] $user_info
	 */
	protected function compileInfo($user_info)
	{
		global $modSettings;

		// Set up the $user_info array.
		$user_info += array(
			'id' => $this->id,
			'username' => $this->member_name,
			'name' => $this->settings->real_name(''),
			'email' => $this->settings->email_address(''),
			'passwd' => $this->settings->passwd(''),
			'language' => $this->getLanguage(),
			'is_guest' => (bool) $this->id == 0,
			'is_admin' => (bool) in_array(1, $user_info['groups']),
			'is_mod' => false,
			'theme' => (int) $this->settings->id_theme,
			'last_login' => (int) $this->settings->last_login,
			'ip' => $this->req->client_ip(),
			'ip2' => $this->req->ban_ip(),
			'posts' => (int) $this->settings->posts,
			'time_format' => $this->settings->getEmpty('time_format', $modSettings['time_format']),
			'time_offset' => (int) $this->settings->time_offset,
			'avatar' => $this->buildAvatarArray(),
			'smiley_set' => determineSmileySet($this->settings->smiley_set(''), $modSettings['smiley_sets_known']),
			'messages' => (int) $this->settings->personal_messages,
			'mentions' => max(0, (int) $this->settings->mentions),
			'unread_messages' => (int) $this->settings->unread_messages,
			'total_time_logged_in' => (int) $this->settings->total_time_logged_in,
			'buddies' => !empty($modSettings['enable_buddylist']) ? explode(',', (string) $this->settings->buddy_list) : [],
			'ignoreboards' => explode(',', (string) $this->settings->ignore_boards),
			'ignoreusers' => explode(',', (string) $this->settings->pm_ignore_list),
			'warning' => (int) $this->settings->warning,
			'permissions' => [],
		);
		$user_info['groups'] = array_unique($user_info['groups']);

		// Make sure that the last item in the ignore boards array is valid.  If the list was too long it could have an ending comma that could cause problems.
		if (!empty($user_info['ignoreboards']) && empty($user_info['ignoreboards'][$tmp = count($user_info['ignoreboards']) - 1]))
		{
			unset($user_info['ignoreboards'][$tmp]);
		}

		// Just build this here, it makes it easier to change/use - administrators can see all boards.
		if ($user_info['is_admin'])
		{
			$user_info['query_see_board'] = '1=1';
		}
		// Otherwise just the groups in $user_info['groups'].
		else
		{
			$user_info['query_see_board'] = '((FIND_IN_SET(' . implode(', b.member_groups) != 0 OR FIND_IN_SET(', $user_info['groups']) . ', b.member_groups) != 0)' . (!empty($modSettings['deny_boards_access']) ? ' AND (FIND_IN_SET(' . implode(', b.deny_member_groups) = 0 AND FIND_IN_SET(', $user_info['groups']) . ', b.deny_member_groups) = 0)' : '') . (isset($user_info['mod_cache']) ? ' OR ' . $user_info['mod_cache']['mq'] : '') . ')';
		}
		// Build the list of boards they WANT to see.
		// This will take the place of query_see_boards in certain spots, so it better include the boards they can see also

		// If they aren't ignoring any boards then they want to see all the boards they can see
		if (empty($user_info['ignoreboards']))
		{
			$user_info['query_wanna_see_board'] = $user_info['query_see_board'];
		}
		// Ok I guess they don't want to see all the boards
		else
		{
			$user_info['query_wanna_see_board'] = '(' . $user_info['query_see_board'] . ' AND b.id_board NOT IN (' . implode(',', $user_info['ignoreboards']) . '))';
		}

		$this->info = new class($user_info) extends \ElkArte\ValuesContainer{
			public function isFirstLogin()
			{
				return $this->data['last_login'] === 0;
			}
			public function canMod($postmodActive)
			{
				return allowedTo('access_mod_center') || ($this->data['is_guest'] === false && ($this->data['mod_cache']['gq'] != '0=1' || $this->data['mod_cache']['bq'] != '0=1' || ($postmodActive && !empty($this->data['mod_cache']['ap']))));
			}
		};
	}

	/**
	 * Prepares the data of the avatar (path, url, etc.)
	 *
	 * @return mixed[]
	 */
	protected function buildAvatarArray()
	{
		return array_merge([
				'url' => $this->settings->avatar(''),
				'filename' => $this->settings->getEmpty('filename', ''),
				'custom_dir' => $this->settings['attachment_type'] == 1,
				'id_attach' => (int) $this->settings->id_attach
			], determineAvatar($this->settings));
	}

	/**
	 * Determines the language to be used.
	 * Checks the current user setting, the $_GET['language'], the session and $modSettings
	 *
	 * @param mixed[] $user_info
	 */
	protected function getLanguage()
	{
		global $modSettings, $language;

		$user_lang = $this->settings->getEmpty('lngfile', $language);

		// Do we have any languages to validate this?
		if (!empty($modSettings['userLanguage']) && (!empty($_GET['language']) || !empty($_SESSION['language'])))
		{
			$languages = getLanguages();

			// Allow the user to change their language if its valid.
			if (!empty($modSettings['userLanguage']) && !empty($_GET['language']) && isset($languages[strtr($_GET['language'], './\\:', '____')]))
			{
				$user_lang = strtr($_GET['language'], './\\:', '____');
				$_SESSION['language'] = $user_lang;
			}
			elseif (!empty($modSettings['userLanguage']) && !empty($_SESSION['language']) && isset($languages[strtr($_SESSION['language'], './\\:', '____')]))
			{
				$user_lang = strtr($_SESSION['language'], './\\:', '____');
			}
		}
		return $user_lang;
	}

	/**
	 * Prepares the basic data necessary for $this->info
	 *
	 * @return mixed[]
	 */
	protected function initUser()
	{
		global $modSettings;

		// Let's not update the last visit time in these cases...
		// 1. SSI doesn't count as visiting the forum.
		// 2. RSS feeds and XMLHTTP requests don't count either.
		// 3. If it was set within this session, no need to set it again.
		// 4. New session, yet updated < five hours ago? Maybe cache can help.
		if (
			ELK != 'SSI' &&
			!isset($_REQUEST['xml']) && (!isset($_REQUEST['action']) || $_REQUEST['action'] != '.xml') &&
			empty($_SESSION['id_msg_last_visit']) &&
			(!$this->cache->isEnabled() || !$this->cache->getVar($_SESSION['id_msg_last_visit'], 'user_last_visit-' . $this->id, 5 * 3600))
		)
		{
			// @todo can this be cached?
			// Do a quick query to make sure this isn't a mistake.
			require_once(SUBSDIR . '/Messages.subs.php');
			$visitOpt = basicMessageInfo($this->settings['id_msg_last_visit'], true);

			$_SESSION['id_msg_last_visit'] = $this->settings['id_msg_last_visit'];

			// If it was *at least* five hours ago...
			if ($visitOpt['poster_time'] < time() - 5 * 3600)
			{
				require_once(SUBSDIR . '/Members.subs.php');
				updateMemberData($this->id, array('id_msg_last_visit' => (int) $modSettings['maxMsgID'], 'last_login' => time(), 'member_ip' => $this->req->client_ip(), 'member_ip2' => $this->req->ban_ip()));
				$this->settings->updateLastLogin();

				if ($this->cache->levelHigherThan(1))
					$this->cache->put('user_settings-' . $this->id, $this->settings->toArray(), 60);

				$this->cache->put('user_last_visit-' . $this->id, $_SESSION['id_msg_last_visit'], 5 * 3600);
			}
		}
		elseif (empty($_SESSION['id_msg_last_visit']))
		{
			$_SESSION['id_msg_last_visit'] = $this->settings['id_msg_last_visit'];
		}

		$this->member_name = $this->settings['member_name'];

		if (empty($this->settings['additional_groups']))
		{
			$user_info = array(
				'groups' => array($this->settings['id_group'], $this->settings['id_post_group'])
			);
		}
		else
		{
			$user_info = array(
				'groups' => array_merge(
					array($this->settings['id_group'], $this->settings['id_post_group']),
					explode(',', $this->settings['additional_groups'])
				)
			);
		}

		// Because history has proven that it is possible for groups to go bad - clean up in case.
		foreach ($user_info['groups'] as $k => $v)
			$user_info['groups'][$k] = (int) $v;

		// This is a logged in user, so definitely not a spider.
		$user_info['possibly_robot'] = false;

		return $user_info;
	}

	/**
	 * Prepares the $this->settings property
	 *
	 * @param mixed[] $user_settings
	 */
	protected function initSettings($user_settings)
	{
		$this->settings = new class($user_settings) extends ValuesContainerReadOnly {
			/**
			 * The object used to create hashes
			 *
			 * @var \PasswordHash
			 */
			protected $hasher = null;

			/**
			 * Stores the data of the user into an array
			 *
			 * @return mixed[]
			 */
			public function toArray()
			{
				return $this->data;
			}

			/**
			 * Sets last_login to the current time
			 */
			public function updateLastLogin()
			{
				$this->data['last_login'] = time();
			}

			/**
			 * Changes the password to the provided one in $this->settings
			 * Doesn't actually change the database.
			 *
			 * @param string $password The hashed password
			 */
			public function updatePassword($password)
			{
				$this->data['passwd'] = $password;

				$tokenizer = new \ElkArte\TokenHash();
				$this->data['password_salt'] = $tokenizer->generate_hash(UserSettings::HASH_LENGTH);
			}

			/**
			 * Updates total_time_logged_in
			 *
			 * @param int $increment_offset
			 */
			public function updateTotalTimeLoggedIn($increment_offset)
			{
				$this->data['total_time_logged_in'] += time() - $increment_offset;
			}

			/**
			 * Fixes the password salt if not present or if it needs to be changed
			 *
			 * @param bool $force - If true the salt is changed no matter what
			 */
			public function fixSalt($force = false)
			{
				// Correct password, but they've got no salt; fix it!
				if ($this->data['password_salt'] === '' || $force === true)
				{
					$tokenizer = new \ElkArte\TokenHash();

					$this->data['password_salt'] = $tokenizer->generate_hash(UserSettings::HASH_LENGTH);
					return true;
				}
				return false;
			}

			/**
			 * Updates total_time_logged_in
			 *
			 * @param int $increment_offset
			 */
			public function getActivationStatus($strip_ban = true)
			{
				return $this->is_activated > UserSettings::BAN_OFFSET ? $this->is_activated - UserSettings::BAN_OFFSET : $this->is_activated;
			}

			/**
			 * Repeat the hashing of the password
			 *
			 * @param string $password The plain text (or sha256 hashed) password
			 * @return bool|null Returns false if something fails
			 */
			public function rehashPassword($password)
			{
				$this->initHasher();

				// If the password is not 64 characters, lets make it a (SHA-256)
				if (strlen($password) !== 64)
				{
					$password = hash('sha256', \ElkArte\Util::strtolower($this->member_name) . un_htmlspecialchars($password));
				}

				$passhash = $this->hasher->HashPassword($password);

				// Something is not right, we can not generate a valid hash that's <20 characters
				if (strlen($passhash) < 20)
				{
					// @todo here we should throw an exception
					return false;
				}
				else
				{
					$this->settings->updatePassword($passhash);
				}
			}

			/**
			 * Initialize the password hashing object
			 */
			protected function initHasher()
			{
				if ($this->hasher === null)
				{
					// Our hashing controller
					require_once(EXTDIR . '/PasswordHash.php');

					// Base-2 logarithm of the iteration count used for password stretching, the
					// higher the number the more secure and CPU time consuming
					$hash_cost_log2 = 10;

					// Do we require the hashes to be portable to older systems (less secure)?
					$hash_portable = false;

					// Get an instance of the hasher
					$this->hasher = new \PasswordHash($hash_cost_log2, $hash_portable);
				}
			}

			/**
			 * Checks whether a password meets the current forum rules
			 *
			 * What it does:
			 *
			 * - called when registering/choosing a password.
			 * - checks the password obeys the current forum settings for password strength.
			 * - if password checking is enabled, will check that none of the words in restrict_in appear in the password.
			 * - returns an error identifier if the password is invalid.
			 *
			 * @param string $password
			 * @return bool true or false
			 */
			public function validatePassword($password)
			{
				$this->initHasher();

				// If the password is not 64 characters, lets make it a (SHA-256)
				if (strlen($password) !== 64)
				{
					$password = hash('sha256', \ElkArte\Util::strtolower($this->member_name) . un_htmlspecialchars($password));
				}

				return (bool) $this->hasher->CheckPassword($password, $this->passwd);
			}
		};
	}

	/**
	 * Initialize the data necessary to "load" a guest
	 *
	 * @return mixed[]
	 */
	protected function initGuest()
	{
		global $cookiename, $modSettings, $context;

		// This is what a guest's variables should be.
		$this->member_name = '';
		$user_info = array('groups' => array(-1));
		$this->initSettings([]);

		if (isset($_COOKIE[$cookiename]))
			$_COOKIE[$cookiename] = '';

		// Create a login token if it doesn't exist yet.
		if (!isset($_SESSION['token']['post-login']))
			createToken('login');
		else
			list ($context['login_token_var'],,, $context['login_token']) = $_SESSION['token']['post-login'];

		// Do we perhaps think this is a search robot? Check every five minutes just in case...
		if ((!empty($modSettings['spider_mode']) || !empty($modSettings['spider_group'])) && (!isset($_SESSION['robot_check']) || $_SESSION['robot_check'] < time() - 300))
		{
			require_once(SUBSDIR . '/SearchEngines.subs.php');
			$user_info['possibly_robot'] = spiderCheck();
		}
		elseif (!empty($modSettings['spider_mode']))
			$user_info['possibly_robot'] = isset($_SESSION['id_robot']) ? $_SESSION['id_robot'] : 0;
		// If we haven't turned on proper spider hunts then have a guess!
		else
		{
			$ci_user_agent = strtolower($this->req->user_agent());
			$user_info['possibly_robot'] = (strpos($ci_user_agent, 'mozilla') === false && strpos($ci_user_agent, 'opera') === false) || preg_match('~(googlebot|slurp|crawl|msnbot|yandex|bingbot|baidu|duckduckbot)~u', $ci_user_agent) == 1;
		}

		return $user_info;
	}

	/**
	 * Loads the data of a certain member from the database
	 *
	 * @param bool $already_verified
	 * @param string $session_password
	 */
	protected function loadUserData($already_verified, $session_password)
	{
		$user_settings = [];

		// Is the member data cached?
		if ($this->cache->levelLowerThan(2) || $this->cache->getVar($user_settings, 'user_settings-' . $this->id, 60) === false)
		{
			$this_user = $this->db->fetchQuery('
				SELECT mem.*, COALESCE(a.id_attach, 0) AS id_attach, a.filename, a.attachment_type
				FROM {db_prefix}members AS mem
					LEFT JOIN {db_prefix}attachments AS a ON (a.id_member = {int:id_member})
				WHERE mem.id_member = {int:id_member}
				LIMIT 1',
				array(
					'id_member' => $this->id,
				)
			);

			$user_settings = $this_user->fetch_assoc();
			$this_user->free_result();

			if ($this->cache->levelHigherThan(1))
			{
				$this->cache->put('user_settings-' . $this->id, $user_settings, 60);
			}
		}

		// Did we find 'im?  If not, junk it.
		if (!empty($user_settings))
		{
			// Make the ID specifically an integer
			$user_settings['id_member'] = (int) ($user_settings['id_member'] ?? 0);

			// As much as the password should be right, we can assume the integration set things up.
			if (!empty($already_verified) && $already_verified === true)
			{
				$check = true;
			}
			// SHA-256 passwords should be 64 characters long.
			elseif (strlen($session_password) == 64)
			{
				$check = hash('sha256', ($user_settings['passwd'] . $user_settings['password_salt'])) == $session_password;
			}
			else
			{
				$check = false;
			}

			// Wrong password or not activated - either way, you're going nowhere.
			$this->id = $check && ($user_settings['is_activated'] == 1 || $user_settings['is_activated'] == 11) ? $user_settings['id_member'] : 0;
		}
		else
		{
			$this->id = 0;
			$user_settings = null;
		}

		$this->initSettings($user_settings);

		// If we no longer have the member maybe they're being all hackey, stop brute force!
		if (empty($this->id))
		{
			validatePasswordFlood($this->settings->id_member($this->id), $this->settings->passwd_flood(false), $this->id != 0);
		}
	}
}
