<?php
/**
 * This file is part of the SVN-Buddy Updater library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy-updater
 */

use ConsoleHelpers\SvnBuddyUpdater\Container;
use ConsoleHelpers\SvnBuddyUpdater\EnvironmentPatcher;
use ConsoleHelpers\SvnBuddyUpdater\ReleaseManager;
use Silex\Application;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application();

$app['container'] = new Container();

/** @var EnvironmentPatcher $environment_patcher */
$environment_patcher = $app['container']['environment_patcher'];
$environment_patcher->patch();

$app->get('/versions', function (Application $app) {
	/** @var ReleaseManager $release_manager */
	$release_manager = $app['container']['release_manager'];

	return new JsonResponse(
		$release_manager->getLatestVersionsForStability()
	);
});

$app->get('/download/{version}/{file}', function (Application $app, $version, $file) {
	/** @var ReleaseManager $release_manager */
	$release_manager = $app['container']['release_manager'];

	$download_url = $release_manager->getDownloadUrl($version, $file);

	if ( !$download_url ) {
		throw new NotFoundHttpException();
	}

	return $app->redirect($download_url);
});

$app->run();
