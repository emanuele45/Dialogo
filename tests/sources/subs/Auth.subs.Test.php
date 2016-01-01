<?php

class TestAuthsubs extends PHPUnit_Framework_TestCase
{
	protected $passwd = 'test_admin_pwd';
	protected $user = 'test_admin';
	protected $useremail = 'email@testadmin.tld';

	/**
	 * Prepare what is necessary to use in these tests.
	 *
	 * setUp() is run automatically by the testing framework before each test method.
	 */
	public function setUp()
	{
		require_once(SUBSDIR . '/Auth.subs.php');
	}

	/**
	 * cleanup data we no longer need at the end of the tests in this class.
	 *
	 * tearDown() is run automatically by the testing framework after each test method.
	 */
	public function tearDown()
	{
	}

	/**
	 * We run this test in a seperate process to prevent headers already sent errors
	 * when the cookie is generated.
	 *
	 * @runInSeparateProcess
	 */
	public function test_login_cookie()
	{
		global $cookiename, $context, $user_profile;

		$context['admin_features'] = array();

		// Lets test load data, this should be id #1 for the testcase
		$user_data = loadMemberData($this->user, true, 'profile');
		$this->assertEquals(1, $user_data[0]);

		$salt = $user_profile[1]['password_salt'];
		setLoginCookie(60 * 60, $user_profile[1]['id_member'], hash('sha256', $this->passwd . $salt));

		// Cookie should be set, with our values
		$array = @unserialize($_COOKIE[$cookiename]);
		$this->assertEquals($array[1], hash('sha256', $this->passwd . $salt));
	}
}