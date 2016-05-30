<?php
/**
 * This file is part of the SVN-Buddy Updater library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy-updater
 */

namespace ConsoleHelpers\SvnBuddyUpdater;


use ConsoleHelpers\ConsoleKit\Application as BaseApplication;
use ConsoleHelpers\ConsoleKit\Container;
use ConsoleHelpers\SvnBuddyUpdater\Command\CreateSnapshotCommand;
use ConsoleHelpers\SvnBuddyUpdater\Command\SyncReleaseCommand;
use Symfony\Component\Console\Command\Command;

class Application extends BaseApplication
{

	public function __construct(Container $container)
	{
		parent::__construct($container);

		/** @var EnvironmentPatcher $environment_patcher */
		$environment_patcher = $container['environment_patcher'];
		$environment_patcher->patch();
	}

	/**
	 * Initializes all the composer commands.
	 *
	 * @return Command[] An array of default Command instances.
	 */
	protected function getDefaultCommands()
	{
		$default_commands = parent::getDefaultCommands();
		$default_commands[] = new SyncReleaseCommand();
		$default_commands[] = new CreateSnapshotCommand();

		return $default_commands;
	}

}
