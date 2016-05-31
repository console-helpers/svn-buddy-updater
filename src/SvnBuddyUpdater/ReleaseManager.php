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


use Aura\Sql\ExtendedPdoInterface;
use Aws\S3\S3Client;
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
	 * @var ExtendedPdoInterface
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
	 * Creates release manager instance.
	 *
	 * @param ExtendedPdoInterface $db Database.
	 */
	public function __construct(ExtendedPdoInterface $db)
	{
		$this->_db = $db;
		$this->_repositoryPath = realpath(__DIR__ . '/../../workspace/repository');
		$this->_snapshotsPath = realpath(__DIR__ . '/../../workspace/snapshots');
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

		foreach ( $this->_getReleasesFromGitHub() as $release_data ) {
			$bind_params = array(
				'version_name' => $release_data['name'],
				'release_date' => strtotime($release_data['published_at']),
				'phar_download_url' => '',
				'signature_download_url' => '',
				'stability' => self::STABILITY_STABLE,
			);

			foreach ( $release_data['assets'] as $asset_data ) {
				$asset_name = $asset_data['name'];

				if ( isset($this->_fileMapping[$asset_name]) ) {
					$bind_params[$this->_fileMapping[$asset_name]] = $asset_data['browser_download_url'];
				}
			}

			$sql = 'INSERT INTO releases (version_name, release_date, phar_download_url, signature_download_url, stability)
					VALUES (:version_name, :release_date, :phar_download_url, :signature_download_url, :stability)';
			$this->_db->perform($sql, $bind_params);
		}
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

		return $client->api('repo')->releases()->all('console-helpers', 'svn-buddy');
	}

	/**
	 * Syncs releases from Repository.
	 *
	 * @param integer $stability Stability.
	 *
	 * @return void
	 * @throws \InvalidArgumentException When invalid snapshot mode is given.
	 */
	public function createRelease($stability)
	{
		$this->_gitCommand('checkout', array('master'));
		$this->_gitCommand('pull');

		if ( $stability === self::STABILITY_PREVIEW ) {
			// Preview release is created from last commit of this week.
			$year = date('Y');
			$week = date('W');
		}
		elseif ( $stability === self::STABILITY_SNAPSHOT ) {
			// Snapshot release is created from last commit of previous week.
			list($year, $week) = $this->_subtractWeek(date('Y'), date('W'));
		}
		else {
			throw new \InvalidArgumentException('Stability "' . $stability . '" is unknown.');
		}

		$commit_data = $this->_getLastCommitOfWeek($year, $week);

		if ( $commit_data ) {
			$this->_doCreateRelease($commit_data[0], $commit_data[1], $stability);
		}
	}

	/**
	 * Returns commit hash/date for next snapshot release.
	 *
	 * @param integer $year Year.
	 * @param integer $week Week.
	 *
	 * @return array
	 */
	private function _getLastCommitOfWeek($year, $week)
	{
		$week_start = strtotime($year . 'W' . $week);
		$week_end = strtotime('+1 week -1 second', $week_start);

		$output = $this->_gitCommand('log', array(
			'--format=%H:%cd',
			'--max-count=1',
			'--after=' . date('Y-m-d H:i:s', $week_start),
			'--before=' . date('Y-m-d H:i:s', $week_end),
		));
		$output = trim($output);

		// No commits in given week > try previous week.
		if ( !$output ) {
			list($prev_week_year, $prev_week) = $this->_subtractWeek($year, $week);

			return $this->_getLastCommitOfWeek($prev_week_year, $prev_week);
		}

		return explode(':', $output, 2);
	}

	/**
	 * Subtracts one week.
	 *
	 * @param integer $year Year.
	 * @param integer $week Week.
	 *
	 * @return array
	 */
	private function _subtractWeek($year, $week)
	{
		$this_monday = strtotime($year . 'W' . $week);
		$prev_sunday = strtotime('-1 second', $this_monday);

		return array(
			date('Y', $prev_sunday),
			date('W', $prev_sunday),
		);
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
		$version = $this->getVersionFromCommit($commit_hash, $stability);

		$sql = 'SELECT version_name
				FROM releases
				WHERE version_name = :version';
		$found_version = $this->_db->fetchValue($sql, array(
			'version' => $version,
		));

		if ( $found_version === $commit_hash ) {
			return;
		}

		list($phar_download_url, $signature_download_url) = $this->_createPhar($commit_hash, $stability);

		$bind_params = array(
			'version_name' => $version,
			'release_date' => strtotime($commit_date),
			'phar_download_url' => $phar_download_url,
			'signature_download_url' => $signature_download_url,
			'stability' => $stability,
		);

		$sql = 'INSERT INTO releases (version_name, release_date, phar_download_url, signature_download_url, stability)
				VALUES (:version_name, :release_date, :phar_download_url, :signature_download_url, :stability)';
		$this->_db->perform($sql, $bind_params);
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
		$this->_gitCommand('checkout', array($commit_hash));

		$this->_shellCommand(
			$this->_repositoryPath . '/bin/svn-buddy',
			array(
				'dev:phar-create',
				'--build-dir=' . $this->_snapshotsPath,
			)
		);

		$phar_file = $this->_snapshotsPath . '/svn-buddy.phar';
		$signature_file = $this->_snapshotsPath . '/svn-buddy.phar.sig';

		return $this->_uploadToS3(
			$stability . 's/' . $commit_hash,
			array($phar_file, $signature_file)
		);
	}

	/**
	 * Deletes old snapshots.
	 *
	 * @param string $stability Stability.
	 * @param string $threshold Threshold.
	 *
	 * @return void
	 */
	public function deleteOldSnapshots($stability, $threshold)
	{
		$latest_versions = $this->getLatestVersionsForStability();

		if ( !isset($latest_versions[$stability]) ) {
			return;
		}

		$sql = 'SELECT version_name
				FROM releases
				WHERE stability = :stability AND release_date < :release_date AND version_name != :latest_version
				ORDER BY release_date ASC';
		$versions = $this->_db->fetchCol($sql, array(
			'stability' => $stability,
			'release_date' => strtotime('-' . $threshold),
			'latest_version' => $latest_versions[$stability]['version'],
		));

		if ( !$versions ) {
			return;
		}

		// Delete associated S3 objects.
		$s3_objects = array();

		foreach ( $versions as $version ) {
			$s3_objects[] = array('Key' => 'snapshots/' . $version . '/svn-buddy.phar');
			$s3_objects[] = array('Key' => 'snapshots/' . $version . '/svn-buddy.phar.sig');
			$s3_objects[] = array('Key' => 'snapshots/' . $version);
		}

		$s3 = S3Client::factory();
		$s3->deleteObjects(array(
			'Bucket' => $this->_s3BucketName,
			'Objects' => $s3_objects,
		));

		// Delete versions.
		$sql = 'DELETE FROM releases
				WHERE version_name IN (:versions)';
		$this->_db->perform($sql, array(
			'versions' => $versions,
		));
	}

	/**
	 * Uploads files to S3.
	 *
	 * @param string $parent_folder Parent folder.
	 * @param array  $files         Files.
	 *
	 * @return array
	 */
	private function _uploadToS3($parent_folder, array $files)
	{
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
		$sql = 'DELETE FROM releases
				WHERE stability = :stability';
		$this->_db->perform($sql, array(
			'stability' => $stability,
		));
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

	/**
	 * Returns latest versions for each stability.
	 *
	 * @return array
	 */
	public function getLatestVersionsForStability()
	{
		$sql = 'SELECT stability, MAX(release_date)
				FROM releases
				GROUP BY stability';
		$stabilities = $this->_db->fetchPairs($sql);

		$versions = array();

		foreach ( $stabilities as $stability => $release_date ) {
			$sql = 'SELECT version_name
					FROM releases
					WHERE stability = :stability AND release_date = :release_date';
			$release_data = $this->_db->fetchOne($sql, array(
				'stability' => $stability,
				'release_date' => $release_date,
			));

			$versions[$stability] = array(
				'path' => '/download/' . $release_data['version_name'] . '/svn-buddy.phar',
				'version' => $release_data['version_name'],
				'min-php' => 50300,
			);
		}

		return $versions;
	}

	/**
	 * Returns download url for version.
	 *
	 * @param string $version Version.
	 * @param string $file    File.
	 *
	 * @return string
	 */
	public function getDownloadUrl($version, $file)
	{
		$file_mapping = array(
			'svn-buddy.phar' => 'phar_download_url',
			'svn-buddy.phar.sig' => 'signature_download_url',
		);

		if ( !isset($this->_fileMapping[$file]) ) {
			return '';
		}

		$sql = 'SELECT ' . $file_mapping[$file] . '
				FROM releases
				WHERE version_name = :version';
		$download_url = $this->_db->fetchValue($sql, array(
			'version' => $this->_resolveStabilityVersion($version),
		));

		return (string)$download_url;
	}

	/**
	 * In case, when version is in fact stability return latest version for stability.
	 *
	 * @param string $version Version.
	 *
	 * @return string
	 */
	private function _resolveStabilityVersion($version)
	{
		$stabilities = array(self::STABILITY_PREVIEW, self::STABILITY_SNAPSHOT, self::STABILITY_STABLE);

		if ( in_array($version, $stabilities) ) {
			$stability = $version;
			$latest_versions = $this->getLatestVersionsForStability();

			if ( isset($latest_versions[$stability]) ) {
				return $latest_versions[$stability]['version'];
			}
		}

		return $version;
	}

}
