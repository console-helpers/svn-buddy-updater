<?php
/**
 * This file is part of the SVN-Buddy Updater library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy-updater
 */

namespace ConsoleHelpers\SvnBuddyUpdater\Command;


use ConsoleHelpers\SvnBuddyUpdater\ReleaseManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncReleaseCommand extends AbstractCommand
{

	/**
	 * Release manager.
	 *
	 * @var ReleaseManager
	 */
	private $_releaseManager;

	/**
	 * {@inheritdoc}
	 */
	protected function configure()
	{
		$this
			->setName('release:sync')
			->setDescription('Updates stable release information from GitHub');
	}

	/**
	 * Prepare dependencies.
	 *
	 * @return void
	 */
	protected function prepareDependencies()
	{
		$container = $this->getContainer();
		$this->_releaseManager = $container['release_manager'];
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$this->_releaseManager->syncReleasesFromGitHub();
	}

}
