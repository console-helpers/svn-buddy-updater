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


class EnvironmentPatcher
{

	/**
	 * Overrides environment vars from ".env" file.
	 *
	 * @return void
	 */
	public function patch()
	{
		$env_override_file = __DIR__ . '/../../.env';

		if ( file_exists($env_override_file) ) {
			$env_override_vars = parse_ini_file($env_override_file);

			foreach ( $env_override_vars as $var_name => $var_value ) {
				$_SERVER[$var_name] = $var_value;
			}
		}
	}

}
