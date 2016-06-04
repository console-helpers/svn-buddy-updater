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


class Week
{

	/**
	 * Year.
	 *
	 * @var integer
	 */
	private $_year;

	/**
	 * Week number.
	 *
	 * @var integer
	 */
	private $_week;

	/**
	 * Returns current week.
	 *
	 * @return self
	 */
	static public function current()
	{
		return new self(
			date('Y'),
			date('W')
		);
	}

	/**
	 * Creates week instance.
	 *
	 * @param integer $year Year.
	 * @param integer $week Week.
	 */
	public function __construct($year, $week)
	{
		$this->_year = $year;
		$this->_week = $week;
	}

	/**
	 * Returns previous week.
	 *
	 * @return Week
	 */
	public function previous()
	{
		$this_monday = $this->getStart();
		$prev_sunday = strtotime('-1 second', $this_monday);

		return new self(
			date('Y', $prev_sunday),
			date('W', $prev_sunday)
		);
	}

	/**
	 * Returns week start.
	 *
	 * @return integer
	 */
	public function getStart()
	{
		return strtotime($this->_year . 'W' . $this->_week);
	}

	/**
	 * Returns week end.
	 *
	 * @return integer
	 */
	public function getEnd()
	{
		return strtotime('+1 week -1 second', $this->getStart());
	}

}
