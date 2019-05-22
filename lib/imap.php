<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Jonas Sulzer <jonas@violoncello.ch>
 * @copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

use OCA\user_external\imap\imap_rcube;

/**
 * User authentication against an IMAP mail server
 *
 * @category Apps
 * @package  UserExternal
 * @author   Robin Appelman <icewind@owncloud.com>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     http://github.com/owncloud/apps
 */
class OC_User_IMAP extends \OCA\user_external\Base {
	private $mailbox;
	private $port;
	private $sslmode;
	private $domain;
	private $stripeDomain;
	private $groupDomain;

	/**
	 * Create new IMAP authentication provider
	 *
	 * @param string $mailbox IMAP server domain/IP
	 * @param string $port IMAP server $port
	 * @param string $sslmode
	 * @param string $domain  If provided, loging will be restricted to this domain
	 */
	public function __construct($mailbox, $port = null, $sslmode = null, $domain = null, $stripeDomain = true, $groupDomain = false) {
		parent::__construct($mailbox);
		$this->mailbox = $mailbox;
		$this->port = $port === null ? 143 : $port;
		$this->sslmode = $sslmode;
		$this->domain = $domain === null ? '' : $domain;
		$this->stripeDomain = $stripeDomain;
		$this->groupDomain = $groupDomain;
	}

	/**
	 * Check if the password is correct without logging in the user
	 *
	 * @param string $uid      The username
	 * @param string $password The password
	 *
	 * @return true/false
	 */
	public function checkPassword($uid, $password) {
		// Replace escaped @ symbol in uid (which is a mail address)
		// but only if there is no @ symbol and if there is a %40 inside the uid
		if (!(strpos($uid, '@') !== false) && (strpos($uid, '%40') !== false)) {
			$uid = str_replace("%40","@",$uid);
		}

		$pieces = explode('@', $uid);
		if ($this->domain !== '') {
			if (count($pieces) === 1) {
				$username = $uid . '@' . $this->domain;
			} else if(count($pieces) === 2 && $pieces[1] === $this->domain) {
				$username = $uid;
				if ($this->stripeDomain) {
					$uid = $pieces[0];
				}
			} else {
				return false;
			}
		} else {
			$username = $uid;
 		}

		$rcube = new imap_rcube();

		$params = ["port"=>$this->port, "timeout"=>10];

		if ($this->sslmode !== null){
			$params["ssl_mode"] = $this->sslmode;
		}
		$canconnect = $rcube->connect(
					$this->mailbox,
					$username,
					$password,
					$params
		);

		if($canconnect) {
 			$rcube->closeConnection();
			$uid = mb_strtolower($uid);
			$this->storeUser($uid, $pieces[1]);
			return $uid;
		}
		return false;
	}

	protected function storeUser($uid, $group) {
		if (!$this->userExists($uid)) {
			$query = \OC::$server->getDatabaseConnection()->getQueryBuilder();
			$query->insert('users_external')
				->values([
					'uid' => $query->createNamedParameter($uid),
					'backend' => $query->createNamedParameter($this->backend),
				]);
			$query->execute();

			if($this->groupDomain && $group) {
				$createduser = \OC::$server->getUserManager()->get($uid);
				\OC::$server->getGroupManager()->createGroup($group)->addUser($createduser);
			}
		}
	}
}
