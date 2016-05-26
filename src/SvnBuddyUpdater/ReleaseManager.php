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
use Github\Client;
use Github\HttpClient\CachedHttpClient;

class ReleaseManager
{

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
	 * Creates release manager instance.
	 *
	 * @param ExtendedPdoInterface $db Database.
	 */
	public function __construct(ExtendedPdoInterface $db)
	{
		$this->_db = $db;
	}

	/**
	 * Syncs releases from GitHub.
	 *
	 * @return void
	 */
	public function syncReleasesFromGitHub()
	{
		$this->_deleteReleases();

		foreach ( $this->_getReleasesFromGitHub() as $release_data ) {
			$bind_params = array(
				'version_name' => $release_data['name'],
				'release_date' => strtotime($release_data['published_at']),
				'phar_download_url' => '',
				'signature_download_url' => '',
				'stability' => 'stable',
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
	 * Deletes releases.
	 *
	 * @return void
	 */
	private function _deleteReleases()
	{
		$sql = 'DELETE FROM releases';
		$this->_db->perform($sql);
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
		$download_url = $this->_db->fetchValue($sql, array('version' => $version));

		return (string)$download_url;
	}

}
