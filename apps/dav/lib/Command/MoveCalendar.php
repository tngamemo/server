<?php
/**
 * @author Thomas Citharel <tcit@tcit.fr>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Command;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCA\DAV\Connector\Sabre\Principal;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MoveCalendar extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var IGroupManager $groupManager */
	private $groupManager;

	/** @var \OCP\IDBConnection */
	protected $dbConnection;

	/** @var IL10N */
	protected $l10n;

	/**
	 * @param IUserManager $userManager
	 * @param IGroupManager $groupManager
	 * @param IDBConnection $dbConnection
	 * @param IL10N $l10n
	 */
	function __construct(IUserManager $userManager, IGroupManager $groupManager, IDBConnection $dbConnection, IL10N $l10n) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->dbConnection = $dbConnection;
		$this->l10n = $l10n;
	}

	protected function configure() {
		$this
			->setName('dav:move-calendar')
			->setDescription('Move a calendar from an user to another')
			->addArgument('name',
				InputArgument::REQUIRED,
				'Name of the calendar to move')
			->addArgument('userorigin',
				InputArgument::REQUIRED,
				'User who currently owns the calendar')
			->addArgument('userdestination',
				InputArgument::REQUIRED,
				'User who will receive the calendar')
			->addOption('force', 'f', InputOption::VALUE_NONE, "Force the migration by removing shares with groups that the destination user is not in");
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userOrigin = $input->getArgument('userorigin');
		$userDestination = $input->getArgument('userdestination');

		if (in_array('system', [$userOrigin, $userDestination], true)) {
			throw new \InvalidArgumentException("User can't be system");
		}

		if (!$this->userManager->userExists($userOrigin)) {
			throw new \InvalidArgumentException("User <$userOrigin> in unknown.");
		}


		if (!$this->userManager->userExists($userDestination)) {
			throw new \InvalidArgumentException("User <$userDestination> in unknown.");
		}

		$principalBackend = new Principal(
			$this->userManager,
			$this->groupManager
		);
		$random = \OC::$server->getSecureRandom();
		$dispatcher = \OC::$server->getEventDispatcher();

		$name = $input->getArgument('name');
		$caldav = new CalDavBackend($this->dbConnection, $principalBackend, $this->userManager, $random, $dispatcher);

		$calendar = $caldav->getCalendarByUri("principals/users/" . $userOrigin, $name);
		if (null === $calendar) {
			throw new \InvalidArgumentException("User <$userOrigin> has no calendar named <$name>.");
		}

		if (null !== $caldav->getCalendarByUri("principals/users/" . $userDestination, $name)) {
			throw new \InvalidArgumentException("User <$userDestination> already has a calendar named <$name>.");
		}

		/**
		 * Check that user destination is member of the groups which whom the calendar was shared
		 * If we ask to force the migration, the share with the group is dropped
		 */
		$shares = $caldav->getShares($calendar['id']);
		foreach ($shares as $share) {
			list($prefix, $group) = str_split($share['href'], 28);
			if ('principal:principals/groups/' === $prefix && !$this->groupManager->isInGroup($userDestination, $group)) {
				if ($input->getOption('force')) {
					$caldav->updateShares(new Calendar($caldav, $calendar, $this->l10n), [], ['href' => 'principal:principals/groups/' . $group]);
				} else {
					throw new \InvalidArgumentException("User <$userDestination> is not part of the group <$group> with which the calendar <$name> was shared. You may use -f to move the calendar while deleting this share.");
				}
			}
		}

		$caldav->moveCalendar($name, $userOrigin, $userDestination);

		$output->writeln("Calendar <$name> was moved from user <$userOrigin> to <$userDestination>");
	}
}