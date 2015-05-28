<?php

/**
 * This class is the core of the upgrade system.
 * Methods starting with "__" (double underscore) are not executed.
 * Each method that contains one or more actions is paired with a method
 * with the same name plus "_title", for example:
 *   - my_new_action
 *   - my_new_action_title
 * Methods whose name ends with "_title" are supposed to return a single
 * string representing the title of the step.
 * Methods containing the actions are supposed to return a multidimentional
 * array with the following structure:
 * array(
 *     array(
 *         'debug_title' => 'A string representing a title shown when debugging',
 *         'function' => function($db, $db_table) { // Code },
 *     ),
 *     [...],
 * );
 */
class UpgradeInstructions_upgrade_1_1
{
	protected $db = null;
	protected $table = null;

	public function __construct($db, $table)
	{
		$this->db = $db;
		return $this->table = $table;
	}

	public function admin_info_files_title()
	{
		return 'Deprecating admin_info_files table...';
	}

	public function admin_info_files()
	{
		return array(
			array(
				'debug_title' => 'Remove any old file left and check if the table is empty...',
				'function' => function($db, $db_table)
				{
					foreach (array('current-version.js', 'detailed-version.js', 'latest-news.js', 'latest-smileys.js', 'latest-versions.txt') as $file)
					{
						$db->query('', '
							DELETE FROM {db_prefix}admin_info_files
							WHERE file = {string:current_file}',
							array(
								'current_file' => $file
							)
						);
					}
					$request = $db->query('', '
						SELECT COUNT(*)
						FROM {db_prefix}admin_info_files',
						array()
					);

					if ($request)
					{
						// Drop it only if it is empty
						list ($count) = (int) $db->fetch_row($request);
						if ($count == 0)
							$db_table->db_drop_table('{db_prefix}admin_info_files');
					}
				}
			),
		);
	}

	public function adding_opt_title()
	{
		return 'Adding two-factor authentication...';
	}

	public function adding_opt($title = false)
	{
		return array(
			array(
				'debug_title' => 'Adding new columns to members table...',
				'function' => function($db, $db_table)
				{
					$db_table->db_add_column('{db_prefix}members',
						array(
							'name' => 'otp_secret',
							'type' => 'varchar',
							'size' => 16,
							'default' => '',
						),
						array(),
						'ignore'
					);
					$db_table->db_add_column('{db_prefix}members',
						array(
							'name' => 'enable_otp',
							'type' => 'tinyint',
							'size' => 1,
							'default' => 0,
						),
						array(),
						'ignore'
					);
				}
			)
		);
	}

	public function mentions_title()
	{
		return 'Adapt mentions...';
	}

	public function mentions()
	{
		return array(
			array(
				'debug_title' => 'Separate visibility from accessibility...',
				'function' => function($db, $db_table)
				{
					$db_table->db_add_column('{db_prefix}log_mentions',
						array(
							'name' => 'is_accessible',
							'type' => 'tinyint',
							'size' => 1,
							'default' => 0
						)
					);

					$db_table->db_change_column('{db_prefix}log_mentions',
						'mention_type',
						array(
							'type' => 'varchar',
							'size' => 12,
							'default' => ''
						)
					);
				}
			),
			array(
				'debug_title' => 'Update mention logs...',
				'function' => function($db, $db_table)
				{
					$db->query('', '
						UPDATE {db_prefix}log_mentions
						SET is_accessible = CASE WHEN status < 0 THEN 0 ELSE 1 END',
						array()
					);

					$db->query('', '
						UPDATE {db_prefix}log_mentions
						SET status = -(status + 1)
						WHERE status < 0',
						array()
					);

					$db->query('', '
						UPDATE {db_prefix}log_mentions
						SET mention_type = mentionmem
						WHERE mention_type = men',
						array()
					);

					$db->query('', '
						UPDATE {db_prefix}log_mentions
						SET mention_type = likemsg
						WHERE mention_type = like',
						array()
					);

					$db->query('', '
						UPDATE {db_prefix}log_mentions
						SET mention_type = rlikemsg
						WHERE mention_type = rlike',
						array()
					);

					$enabled_mentions = !empty($modSettings['enabled_mentions']) ? explode(',', $modSettings['enabled_mentions']) : array();
					$known_settings = array(
						'mentions_enabled' => 'mentionmem',
						'likes_enabled' => 'likemsg',
						'mentions_dont_notify_rlike' => 'rlikemsg',
						'mentions_buddy' => 'buddy',
					);
					foreach ($known_settings as $setting => $toggle)
					{
						if (!empty($modSettings[$setting]))
							$enabled_mentions[] = $toggle;
						else
							$enabled_mentions = array_diff($enabled_mentions, array($toggle));
					}
					updateSettings(array('enabled_mentions' => implode(',', $enabled_mentions)));
				}
			),
			array(
				'debug_title' => 'Make mentions generic and not message-centric...',
				'function' => function($db, $db_table)
				{
					$db_table->db_change_column('{db_prefix}log_mentions', 'id_msg',
						array(
							'name' => 'id_target',
						)
					);
				}
			),
		);
	}

	public function add_modules_support_title()
	{
		return 'Introducing modules...';
	}

	public function add_modules_support()
	{
		return array(
			array(
				'debug_title' => 'Converts settings to modules...',
				'function' => function($db, $db_table)
				{
					global $modSettings;

					require_once(SUBSDIR . '/Admin.subs.php');
					if (!empty($modSettings['attachmentEnable']))
					{
						enableModules('attachments', array('post'));
					}
					if (!empty($modSettings['cal_enabled']))
					{
						enableModules('calendar', array('post', 'boardindex'));
						Hooks::get()->enableIntegration('Calendar_Integrate');
					}
					if (!empty($modSettings['drafts_enabled']))
					{
						enableModules('drafts', array('post', 'display', 'profile', 'personalmessage'));
						Hooks::get()->enableIntegration('Drafts_Integrate');
					}
					if (!empty($modSettings['enabled_mentions']))
					{
						enableModules('mentions', 'post', 'display');
					}
					enableModules('poll', array('display'));
				}
			)
		);
	}

	public function introducing_notifications_title()
	{
		return 'Introducing notifications...';
	}

	public function introducing_notifications()
	{
		return array(
			array(
				'debug_title' => 'Adding new tables...',
				'function' => function($db, $db_table)
				{
					$db_table->db_create_table('{db_prefix}pending_notifications',
						array(
							array('name' => 'notification_type', 'type' => 'varchar', 'size' => 10),
							array('name' => 'id_member',         'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
							array('name' => 'log_time',          'type' => 'int', 'size' => 10, 'default' => 0),
							array('name' => 'frequency',         'type' => 'varchar', 'size' => 1, 'default' => ''),
							array('name' => 'snippet',           'type' => 'text'),
						),
						array(
							array('name' => 'types_member', 'columns' => array('notification_type', 'id_member'), 'type' => 'unique'),
						),
						array(),
						'ignore'
					);

					$db_table->db_create_table('{db_prefix}notifications_pref',
						array(
							array('name' => 'id_member',          'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
							array('name' => 'notification_level', 'type' => 'tinyint', 'size' => 1, 'default' => 1),
							array('name' => 'mention_type',       'type' => 'varchar', 'size' => 12, 'default' => ''),
						),
						array(
							array('name' => 'mention_member', 'columns' => array('id_member', 'mention_type'), 'type' => 'unique'),
						),
						array(),
						'ignore'
					);
				}
			)
		);
	}

	public function convertig_personal_discussion_title()
	{
		return 'Converting Personal Messages to Personal Discussions...';
	}

	public function convertig_personal_discussion()
	{
		return array(
			array(
				'debug_title' => 'Preparing tables...',
				'function' => function($db, $db_table)
				{
					$db_table->db_create_table('{db_prefix}pm_topics',
						array(
							array('name' => 'id_pm_head',        'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0),
							array('name' => 'id_member',         'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
							array('name' => 'id_first_pm',       'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0),
							array('name' => 'id_last_pm',        'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0),
							array('name' => 'id_member_started', 'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
							array('name' => 'num_pms',           'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0),
						),
						array(
							array('name' => 'id_pm_member', 'columns' => array('id_pm_head', 'id_member'), 'type' => 'primary'),
						),
						array(),
						'ignore'
					);

					$db_table->db_create_table('{db_prefix}pm_user_labels',
						array(
							array('name' => 'id_member', 'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0),
							array('name' => 'frequency', 'type' => 'varchar', 'size' => 255, 'default' => ''),
						),
						array(
							array('name' => 'id_member', 'columns' => array('id_member'), 'type' => 'key'),
						),
						array(),
						'ignore'
					);

					$db_table->db_add_column('{db_prefix}pm_recipients',
						array(
							'name' => 'id_pm_head',
							'type' => 'int',
							'size' => 10,
							'unsigned' => true,
							'default' => 0,
						),
						array(),
						'ignore'
					);
				}
			),
			array(
				'debug_title' => 'Moving data...',
				'function' => function($db, $db_table)
				{
					if (isset($modSettings['elkVersion']) && compareVersions('1.0', $modSettings['elkVersion']) < 1)
						return;

					// @todo verify it works in SQLite and PostgreSQL
					$db->query('', '
						UPDATE {db_prefix}pm_recipients as pr
						INNER JOIN {db_prefix}personal_messages as pm ON (pr.id_pm = pm.id_pm)
						SET pr.id_pm_head = pm.id_pm_head',
						array()
					);

					$db->query('', '
						INSERT INTO {db_prefix}pm_topics
							(id_pm_head, id_member, id_first_pm, id_last_pm)
						SELECT id_pm_head, id_member, MIN(id_pm) as id_first_pm, MAX(id_pm) as id_last_pm
						FROM {db_prefix}pm_recipients
						WHERE deleted = 0
						GROUP BY id_member, id_pm_head',
						array()
					);

					// @todo complete moving of discussions
				}
			),
			array(
				'debug_title' => 'Converting labels...',
				'function' => function($db, $db_table)
				{
					$request = $db->query('', '
						SELECT id_member, message_labels
						FROM {db_prefix}members',
						array()
					);

					while ($row = $db->fetch_assoc($request))
					{
						if (empty($row['message_labels']))
							continue;

						$labels = explode(',', $row['message_labels']);
						$inserts = array();
						foreach ($labels as $$label)
						{
							$inserts = array($row['id_member'], $label);
						}

						$db->insert('ignore',
							'{db_prefix}pm_user_labels',
							array(
								'id_member' => 'int',
								'label' => 'string-255'
							),
							$inserts,
							array('id_member')
						);
					}
				}
			),
		);
	}
}