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


final class ReleaseDatabase
{

	/**
	 * Storage filename.
	 *
	 * @var string
	 */
	protected $storageFilename;

	/**
	 * Data.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Creates release database object.
	 *
	 * @param string $storage_filename Storage filename.
	 *
	 * @throws \InvalidArgumentException When file isn't supported.
	 * @throws \RuntimeException When database file is malformed.
	 */
	public function __construct($storage_filename)
	{
		if ( !\file_exists($storage_filename) || !\is_file($storage_filename) ) {
			throw new \InvalidArgumentException('The "' . $storage_filename . '" file doesn\'t exist.');
		}

		$data = \json_decode(\file_get_contents($storage_filename), true);

		if ( !\is_array($data) ) {
			throw new \RuntimeException('The "' . $storage_filename . '" file is malformed.');
		}

		$this->storageFilename = $storage_filename;
		$this->data = $data;
	}

	/**
	 * Saves changes back to the database file.
	 *
	 * @return void
	 */
	protected function save()
	{
		\file_put_contents($this->storageFilename, \json_encode($this->data, \JSON_PRETTY_PRINT));
	}

	/**
	 * Deletes releases.
	 *
	 * @param string $stability Stability.
	 *
	 * @return integer
	 */
	public function deleteByStability($stability)
	{
		$stability_release_count = count($this->data[$stability]);
		$this->data[$stability] = array();
		$this->save();

		return $stability_release_count;
	}

	/**
	 * Deletes several releases by version.
	 *
	 * @param array $versions Versions.
	 *
	 * @return void
	 */
	public function deleteByVersions(array $versions)
	{
		$changed = false;

		foreach ( $versions as $version ) {
			$stability = $this->getVersionStability($version);

			if ( !\array_key_exists($version, $this->data[$stability]) ) {
				continue;
			}

			$changed = true;
			unset($this->data[$stability][$version]);
		}

		if ( $changed ) {
			$this->save();
		}
	}

	/**
	 * Returns version stability.
	 *
	 * @param string $version Version.
	 *
	 * @return string
	 */
	protected function getVersionStability($version)
	{
		$parts = \explode(':', $version, 2);

		return count($parts) === 2 ? $parts[0] : 'stable';
	}

	/**
	 * Searches for a release.
	 *
	 * @param string $version Version.
	 * @param string $field   Field.
	 *
	 * @return mixed
	 */
	public function getField($version, $field)
	{
		$stability = $this->getVersionStability($version);

		if ( !\array_key_exists($version, $this->data[$stability]) ) {
			return null;
		}

		if ( $field === 'version_name' ) {
			return $version;
		}

		return $this->data[$stability][$version][$field];
	}

	/**
	 * Adds a release.
	 *
	 * @param string  $version                Version.
	 * @param integer $release_date           Release date.
	 * @param string  $phar_download_url      PHAR download url.
	 * @param string  $signature_download_url Signature download url.
	 * @param string  $stability              Stability.
	 *
	 * @return void
	 */
	public function add($version, $release_date, $phar_download_url, $signature_download_url, $stability)
	{
		$this->data[$stability][$version] = array(
			'release_date' => $release_date,
			'phar_download_url' => $phar_download_url,
			'signature_download_url' => $signature_download_url,
		);

		$this->sortReleases($stability);
		$this->save();
	}

	/**
	 * Sorts releases for a given stability.
	 *
	 * @param string $stability Stability.
	 *
	 * @return void
	 */
	protected function sortReleases($stability)
	{
		\uasort($this->data[$stability], function (array $release_a, array $release_b) {
			if ( $release_a['release_date'] === $release_b['release_date'] ) {
				return 0;
			}

			return $release_a['release_date'] > $release_b['release_date'] ? -1 : 1;
		});
	}

	/**
	 * Search releases.
	 *
	 * @param string  $stability      Stability.
	 * @param integer $max_date       Maximal release date.
	 * @param string  $except_version Except version.
	 *
	 * @return array
	 */
	public function findByStabilityAndMaxDateWithException($stability, $max_date, $except_version)
	{
		$ret = array();

		foreach ( $this->data[$stability] as $version => $release_data ) {
			if ( $version === $except_version ) {
				continue;
			}

			if ( $release_data['release_date'] < $max_date ) {
				$ret[] = array(
					'version_name' => $version,
					'phar_download_url' => $release_data['phar_download_url'],
					'signature_download_url' => $release_data['signature_download_url'],
				);
			}
		}

		return $ret;
	}

	/**
	 * Returns the latest versions for each stability.
	 *
	 * @return array
	 */
	public function getLatestVersionsForStability()
	{
		$ret = array();

		foreach ( $this->data as $stability => $stability_releases ) {
			$ret[$stability] = key($stability_releases);
		}

		return $ret;
	}

}
