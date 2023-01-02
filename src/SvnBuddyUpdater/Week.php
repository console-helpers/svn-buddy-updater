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
		$correct_this_monday = $this->getStart();
		$correct_prev_sunday = strtotime('-1 second', $correct_this_monday);

		$prev_year = date('Y', $correct_prev_sunday);
		$prev_week = date('W', $correct_prev_sunday);
		$ret = new self($prev_year, $prev_week);

		$prev_monday = $ret->getStart();
		$prev_sunday = $ret->getEnd();

		if ( $correct_prev_sunday >= $prev_monday && $correct_prev_sunday <= $prev_sunday ) {
			return $ret;
		}

		// If timestamp earlier than interval, then decrease year (case with timestamp from 01/01/2021).
		if ( $correct_prev_sunday < $prev_monday ) {
			return new self($prev_year - 1, $prev_week);
		}

		// Case with timestamp from 12/31/2019.
		return new self($prev_year + 1, $prev_week);
	}

	/**
	 * Returns week start.
	 *
	 * @return integer
	 */
	public function getStart()
	{
		return strtotime($this->_year . 'W' . \str_pad($this->_week, 2, '0', \STR_PAD_LEFT));
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
