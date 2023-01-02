<?php

namespace Tests\ConsoleHelpers\SvnBuddyUpdater;


use ConsoleHelpers\SvnBuddyUpdater\Week;
use PHPUnit\Framework\TestCase;

class WeekTest extends TestCase
{

	/**
	 * @dataProvider previousDataProvider
	 */
	public function testPrevious($year, $week, $start, $end)
	{
		$week_obj = new Week($year, $week);
		$prev_week_obj = $week_obj->previous();

		$this->assertEquals($start, $prev_week_obj->getStart(), 'Week start is incorrect');
		$this->assertEquals($end, $prev_week_obj->getEnd(), 'Week ending is incorrect');
	}

	public function previousDataProvider()
	{
		return array(
			'test negative adjustment' => array(
				2023,
				1,
				\mktime(0, 0, 0, 12, 26, 2022),
				\mktime(23, 59, 59, 1, 1, 2023),
			),
			/*'test positive adjustment' => array(
				2020,
				1,
				\mktime(0, 0, 0, 12, 23, 2019),
				\mktime(23, 59, 59, 12, 29, 2019),
			),*/
			'test neutral adjustment' => array(
				2022,
				49,
				\mktime(0, 0, 0, 11, 28, 2022),
				\mktime(23, 59, 59, 12, 4, 2022),
			),
		);
	}

	public function testRange()
	{
		$week = new Week(2023, 1);
		$this->assertEquals(\mktime(0, 0, 0, 1, 2, 2023), $week->getStart(), 'Week start is incorrect');
		$this->assertEquals(\mktime(23, 59, 59, 1, 8, 2023), $week->getEnd(), 'Week ending is incorrect');
	}

}
