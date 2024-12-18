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


use Aws\S3\S3Client;
use ConsoleHelpers\ConsoleKit\ConsoleIO;
use ConsoleHelpers\ConsoleKit\Exception\CommandException;
use Github\Client;
use Github\HttpClient\CachedHttpClient;
use Symfony\Component\Process\ProcessBuilder;

class ReleaseManager
{

	const STABILITY_STABLE = 'stable';

	const STABILITY_SNAPSHOT = 'snapshot';

	const STABILITY_PREVIEW = 'preview';

	const SNAPSHOT_MODE_THIS_WEEK = 1;

	const SNAPSHOT_MODE_PREV_WEEK = 2;

	/**
	 * Database
	 *
	 * @var ReleaseDatabase
	 */
	private $_db;

	/**
	 * Mapping between downloaded files and columns, where they are stored.
	 *
	 * @var array
	 */
	private $_fileMapping = array(
		'svn-buddy.phar' => 'phar_download_url',
		'svn-buddy.phar.sig' => 'signature_download_url',
	);

	/**
	 * Repository path.
	 *
	 * @var string
	 */
	private $_repositoryPath;

	/**
	 * Snapshots path.
	 *
	 * @var string
	 */
	private $_snapshotsPath;

	/**
	 * Name of S3 bucket where snapshots are stored.
	 *
	 * @var string
	 */
	private $_s3BucketName;

	/**
	 * Console IO.
	 *
	 * @var ConsoleIO
	 */
	private $_io;

	/**
	 * Creates release manager instance.
	 *
	 * @param ReleaseDatabase $release_database Database.
	 * @param ConsoleIO       $io               Console IO.
	 *
	 * @throws \RuntimeException When Amazon AWS S3 bucket isn't specified.
	 */
	public function __construct(ReleaseDatabase $release_database, ConsoleIO $io)
	{
		$this->_db = $release_database;
		$this->_io = $io;
		$this->_repositoryPath = realpath(__DIR__ . '/../../workspace/repository');
		$this->_snapshotsPath = realpath(__DIR__ . '/../../workspace/snapshots');

		if ( empty($_SERVER['S3_BUCKET']) ) {
			throw new \RuntimeException('The Amazon AWS S3 bucket is not specified.');
		}

		$this->_s3BucketName = $_SERVER['S3_BUCKET'];
	}

	/**
	 * Syncs releases from GitHub.
	 *
	 * @return void
	 */
	public function syncReleasesFromGitHub()
	{
		$this->_deleteReleases(self::STABILITY_STABLE);
		$github_releases = $this->_getReleasesFromGitHub();

		foreach ( $github_releases as $release_data ) {
			$assets = array(
				'phar_download_url' => '',
				'signature_download_url' => '',
			);

			foreach ( $release_data['assets'] as $asset_data ) {
				$asset_name = $asset_data['name'];

				if ( isset($this->_fileMapping[$asset_name]) ) {
					$assets[$this->_fileMapping[$asset_name]] = $asset_data['browser_download_url'];
				}
			}

			$this->_db->add(
				$release_data['name'],
				strtotime($release_data['published_at']),
				$assets['phar_download_url'],
				$assets['signature_download_url'],
				self::STABILITY_STABLE
			);
		}

		$this->_io->writeln('Added <info>' . count($github_releases) . ' stable</info> releases from GitHub.');
	}

	/**
	 * Returns releases from GitHub.
	 *
	 * @return array
	 */
	private function _getReleasesFromGitHub()
	{
		$client = new Client(
			new CachedHttpClient(array('cache_dir' => '/tmp/github-api-cache'))
		);

		$client->authenticate($_SERVER['GH_TOKEN'], null, Client::AUTH_HTTP_TOKEN);

		return $client->api('repo')->releases()->all('console-helpers', 'svn-buddy');
	}

	/**
	 * Syncs releases from Repository.
	 *
	 * @param integer $stability Stability.
	 *
	 * @return void
	 */
	public function createRelease($stability)
	{
		$this->_io->writeln('1. preparing to create <info>' . $stability . '</info> release');

		$this->_io->write(' * updating cloned repository ... ');
		$this->_gitCommand('checkout', array('master'));
		$this->_gitCommand('pull');
		$this->_io->writeln('done');

		$this->_io->write(' * updating dependencies ... ');
		$this->_shellCommand(
			'composer',
			array(
				'install',
				'--no-dev',
			),
			$this->_repositoryPath
		);
		$this->_io->writeln('done');

		$this->_io->write(' * detecting commit for a release ... ');
		$commit_data = $this->_getLastCommit($this->_getWeekByStability($stability));
		$this->_io->writeln('done (<info>' . $commit_data[0] . '</info>)');

		if ( $commit_data ) {
			$this->_doCreateRelease($commit_data[0], $commit_data[1], $stability);
		}
	}

	/**
	 * Returns week to base current release upon.
	 *
	 * @param string $stability Stability.
	 *
	 * @return Week
	 * @throws \InvalidArgumentException When invalid stability is given.
	 */
	private function _getWeekByStability($stability)
	{
		if ( $stability === self::STABILITY_PREVIEW ) {
			// Preview release is created from last commit of this week.
			return Week::current();
		}

		if ( $stability === self::STABILITY_SNAPSHOT ) {
			// Snapshot release is created from last commit of previous week.
			return Week::current()->previous();
		}

		throw new \InvalidArgumentException('Stability "' . $stability . '" is unknown.');
	}

	/**
	 * Returns commit hash/date for next snapshot release.
	 *
	 * @param Week $week Week.
	 *
	 * @return array
	 */
	private function _getLastCommit(Week $week)
	{
		$output = $this->_gitCommand('log', array(
			'--format=%H:%cd',
			'--max-count=1',
			'--after=' . date('Y-m-d H:i:s', $week->getStart()),
			'--before=' . date('Y-m-d H:i:s', $week->getEnd()),
		));
		$output = trim($output);

		// No commits in given week > try previous week.
		if ( !$output ) {
			return $this->_getLastCommit($week->previous());
		}

		return explode(':', $output, 2);
	}

	/**
	 * Generates phar for snapshot release.
	 *
	 * @param string $commit_hash Commit hash.
	 * @param string $commit_date Commit date.
	 * @param string $stability   Stability.
	 *
	 * @return void
	 */
	private function _doCreateRelease($commit_hash, $commit_date, $stability)
	{
		$this->_io->writeln('2. creating <info>' . $stability . '</info> release');
		$version = $this->getVersionFromCommit($commit_hash, $stability);

		$found_version = $this->_db->getField($version, 'version_name');

		if ( $found_version !== null ) {
			$this->_io->writeln(' * release for <info>' . $version . '</info> version found > skipping');

			return;
		}

		list($phar_download_url, $signature_download_url) = $this->_createPhar($commit_hash, $stability);

		$this->_db->add(
			$version,
			strtotime($commit_date),
			$phar_download_url,
			$signature_download_url,
			$stability
		);

		$this->_io->writeln(' * release for <info>' . $version . '</info> version created');
	}

	/**
	 * Returns version commit and stability.
	 *
	 * @param string $commit_hash Commit hash.
	 * @param string $stability   Stability.
	 *
	 * @return string
	 */
	protected function getVersionFromCommit($commit_hash, $stability)
	{
		$git_version = $this->_gitCommand('describe', array(
			$commit_hash,
			'--tags',
		));

		return $stability . ':' . trim($git_version);
	}

	/**
	 * Creates phar.
	 *
	 * @param string $commit_hash Commit hash.
	 * @param string $stability   Stability.
	 *
	 * @return array
	 */
	private function _createPhar($commit_hash, $stability)
	{
		$this->_io->write(' * creating phar file ... ');
		$this->_gitCommand('checkout', array($commit_hash));

		$this->_shellCommand(
			$this->_repositoryPath . '/bin/svn-buddy',
			array(
				'dev:phar-create',
				'--build-dir=' . $this->_snapshotsPath,
				'--stability=' . $stability,
			)
		);
		$this->_io->writeln('done');

		$phar_file = $this->_snapshotsPath . '/svn-buddy.phar';
		$signature_file = $this->_snapshotsPath . '/svn-buddy.phar.sig';

		$this->_executePhar($phar_file);

		return $this->_uploadToS3(
			$stability . 's/' . $commit_hash,
			array($phar_file, $signature_file)
		);
	}

	/**
	 * Executes a PHAR file.
	 *
	 * @param string $phar_file Path to a PHAR file.
	 *
	 * @return void
	 * @throws CommandException When PHAR execution ended with an error.
	 */
	private function _executePhar($phar_file)
	{
		$this->_io->writeln(' * executing phar file ... ');

		$exit_code = 0;
		$output = array();
		exec('php ' . escapeshellarg($phar_file), $output, $exit_code);

		$this->_io->writeln(array('Exit Code: ' . $exit_code, 'Output:'));
		$this->_io->writeln($output);

		if ( $exit_code !== 0 ) {
			throw new CommandException('Failed to execute "' . $phar_file . '" phar file.');
		}
	}

	/**
	 * Deletes old releases.
	 *
	 * @param string $stability Stability.
	 * @param string $threshold Threshold.
	 *
	 * @return void
	 */
	public function deleteOldReleases($stability, $threshold)
	{
		$this->_io->writeln(
			'Deleting <info>' . $stability . '</info> releases older then <info>' . $threshold . '</info>.'
		);
		$latest_versions = $this->_db->getLatestVersionsForStability();

		if ( $latest_versions[$stability] === null ) {
			$this->_io->writeln(' * <info>0</info> found at all');

			return;
		}

		$releases = $this->_db->findByStabilityAndMaxDateWithException(
			$stability,
			strtotime('-' . $threshold),
			$latest_versions[$stability]
		);

		if ( !$releases ) {
			$this->_io->writeln(' * <info>0</info> found that old');

			return;
		}

		$this->_io->writeln(' * <info>' . count($releases) . '</info> found');

		// Delete associated S3 objects.
		$this->_io->write(' * deleting from s3 ... ');
		$s3_objects = array();

		foreach ( $releases as $release_data ) {
			$s3_objects[] = array('Key' => $this->_getS3ObjectPath($release_data['phar_download_url']));
			$s3_objects[] = array('Key' => $this->_getS3ObjectPath($release_data['signature_download_url']));
			$s3_objects[] = array('Key' => dirname($this->_getS3ObjectPath($release_data['signature_download_url'])));
		}

		$s3 = S3Client::factory();
		$s3->deleteObjects(array(
			'Bucket' => $this->_s3BucketName,
			'Objects' => $s3_objects,
		));
		$this->_io->writeln('done');

		// Delete versions.
		$this->_io->write(' * deleting from database ... ');
		$versions = array();

		foreach ( $releases as $release_data ) {
			$versions[] = $release_data['version_name'];
		}

		$this->_db->deleteByVersions($versions);

		$this->_io->writeln('done');
	}

	/**
	 * Returns path to S3 object.
	 *
	 * @param string $url Url.
	 *
	 * @return string
	 */
	private function _getS3ObjectPath($url)
	{
		return ltrim(parse_url($url, PHP_URL_PATH), '/');
	}

	/**
	 * Uploads files to S3.
	 *
	 * @param string $parent_folder Parent folder.
	 * @param array  $files         Files.
	 *
	 * @return array
	 * @throws \RuntimeException When Amazon AWS credentials are not specified.
	 */
	private function _uploadToS3($parent_folder, array $files)
	{
		if ( empty($_SERVER['AWS_ACCESS_KEY_ID']) || empty($_SERVER['AWS_SECRET_ACCESS_KEY']) ) {
			throw new \RuntimeException('The Amazon AWS credentials are not specified.');
		}

		$this->_io->write(' * uploading to s3 ... ');
		$urls = array();
		$s3 = S3Client::factory();

		foreach ( $files as $index => $file ) {
			$uploaded = $s3->upload(
				$this->_s3BucketName,
				$parent_folder . '/' . basename($file),
				fopen($file, 'rb'),
				'public-read'
			);

			$urls[$index] = $uploaded->get('ObjectURL');
		}

		$this->_io->writeln('done');

		return $urls;
	}

	/**
	 * Deletes releases.
	 *
	 * @param string $stability Stability.
	 *
	 * @return void
	 */
	private function _deleteReleases($stability)
	{
		$rows_affected = $this->_db->deleteByStability($stability);

		$this->_io->writeln('Deleted <info>' . $rows_affected . ' ' . $stability . '</info> releases.');
	}

	/**
	 * Runs git command.
	 *
	 * @param string $command   Command.
	 * @param array  $arguments Arguments.
	 *
	 * @return string
	 */
	private function _gitCommand($command, array $arguments = array())
	{
		array_unshift($arguments, $command);

		return $this->_shellCommand('git', $arguments, $this->_repositoryPath);
	}

	/**
	 * Runs command.
	 *
	 * @param string      $command           Command.
	 * @param array       $arguments         Arguments.
	 * @param string|null $working_directory Working directory.
	 *
	 * @return string
	 */
	private function _shellCommand($command, array $arguments = array(), $working_directory = null)
	{
		$final_arguments = array_merge(array($command), $arguments);

		$process = ProcessBuilder::create($final_arguments)
			->setWorkingDirectory($working_directory)
			->getProcess();

		return $process->mustRun()->getOutput();
	}

}
