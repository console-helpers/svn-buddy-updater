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


use Aura\Sql\ExtendedPdo;

class Container extends \ConsoleHelpers\ConsoleKit\Container
{

	/**
	 * {@inheritdoc}
	 */
	public function __construct(array $values = array())
	{
		parent::__construct($values);

		$this['app_name'] = 'SVN-Buddy Updater';
		$this['app_version'] = '@git-version@';

		$this['working_directory_sub_folder'] = '.svn-buddy-updater';

		$this['config_defaults'] = array();

		$this['db'] = function ($c) {
			$url_parts = parse_url($_SERVER['DATABASE_URL']);

			$dsn = sprintf(
				'pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s',
				$url_parts['host'],
				$url_parts['port'],
				ltrim($url_parts['path'], '/'),
				$url_parts['user'],
				$url_parts['pass']
			);

			return new ExtendedPdo($dsn);
		};

		$this['release_manager'] = function ($c) {
			return new ReleaseManager($c['db'], $c['io']);
		};

		$this['environment_patcher'] = function () {
			return new EnvironmentPatcher();
		};
	}

}
