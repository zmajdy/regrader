<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Model for scoreboard management
 *
 * This model manages the scores of the scoreboard.
 *
 * @package models
 * @author  Ashar Fuadi <fushar@gmail.com>
 */
class Scoreboard_manager extends CI_Model 
{
	/**
	 * Adds a particular submission to the scoreboard
	 *
	 * This function adds the submission whose ID is $submission_id to either contestant scoreboard or admin scoreboard,
	 * depending on the submission time.
	 *
	 * @param int $submission_id The submission ID.
	 */
	public function add_submission($submission_id)
	{
		$submission = $this->submission_manager->get_row($submission_id);
		$contest = $this->contest_manager->get_row($submission['contest_id']);

		if (strtotime($submission['submit_time']) <= strtotime($contest['freeze_time']))
			$this->add_submission_helper('contestant', $submission_id);
		$this->add_submission_helper('admin', $submission_id);
	}

	/**
	 * Adds a particular submission to the scoreboard
	 *
	 * This function adds the submission whose ID is $submission_id to $target scoreboard.
	 *
	 * @param string $target 	 The target scoreboard.
	 * @param int $submission_id The submission ID.
	 */
	private function add_submission_helper($target, $submission_id)
	{
		$submission = $this->submission_manager->get_row($submission_id);
		$contest = $this->contest_manager->get_row($submission['contest_id']);

		$res['contest_id'] = $submission['contest_id'];
		$res['user_id'] = $submission['user_id'];
		$res['problem_id'] = $submission['problem_id']; 
		$res['last_submit_time'] = 0;

		$res['score'] = 0;

		$this->db->from($this->get_table_name($target));
		$this->db->where('contest_id', $submission['contest_id']);
		$this->db->where('user_id', $submission['user_id']);
		$this->db->where('problem_id', $submission['problem_id']);
		$this->db->limit(1);
		$q = $this->db->get();

		$is_present = FALSE;
		if ($q->num_rows() == 1)
		{
			// there is an entry
			$score = $q->row_array();
			$res['last_submit_time'] = $score['last_submit_time'];
			$res['score'] = $score['score'];

			// all following submissions having smaller score than the latest score will be skipped
			if ($submission['score'] <= $res['score'])
				return;
			$is_present = TRUE;
		}

		$res['last_submit_time'] = ceil((strtotime($submission['submit_time']) - strtotime($contest['start_time'])));
		if (isset($submission['score'])) $res['score'] = $submission['score'];


		if ($is_present)
		{
			// if there has been an entry, update it
			$this->db->where('contest_id', $res['contest_id']);
			$this->db->where('user_id', $res['user_id']);
			$this->db->where('problem_id', $res['problem_id']);
			$this->db->set('last_submit_time', $res['last_submit_time']);

			$this->db->set('score', $res['score']);
			$this->db->update($this->get_table_name($target));
		}
		else
		{
			// else, insert it
			foreach ($res as $k => $v)
				$this->db->set($k, $v);
			$this->db->insert($this->get_table_name($target));
		}
	}

	/**
	 * Recalculates the scores in all scoreboards
	 *
	 * This function reconsiders alls submissions and updates all scoreboards. The criteria is given in $args. Usually
	 * used when there is a regrade.
	 *
	 * @param array $args The parameters.
	 */
	public function recalculate_scores($args)
	{
		// removes all scores
		$this->db->where('contest_id', $args['contest_id']);
		if (isset($args['user_id']))
			$this->db->where('user_id', $args['user_id']);
		if (isset($args['problem_id']))
			$this->db->where('problem_id', $args['problem_id']);
		$this->db->delete($_ENV['DB_SCOREBOARD_CONTESTANT_TABLE_NAME']);

		$this->db->where('contest_id', $args['contest_id']);
		if (isset($args['user_id']))
			$this->db->where('user_id', $args['user_id']);
		if (isset($args['problem_id']))
			$this->db->where('problem_id', $args['problem_id']);
		$this->db->delete($_ENV['DB_SCOREBOARD_ADMIN_TABLE_NAME']);

		// retrieves all submissions
		$this->db->select('id, verdict');
		$this->db->from($_ENV['DB_SUBMISSION_TABLE_NAME']);
		$this->db->where('contest_id', $args['contest_id']);
		if (isset($args['user_id']))
			$this->db->where('user_id', $args['user_id']);
		if (isset($args['problem_id']))
			$this->db->where('problem_id', $args['problem_id']);
		$q = $this->db->get();
		$res = $q->result_array();

		// read the submissions
		foreach ($res as $v)
			// if the submission is not ignored
			if (abs($v['verdict']) != 99)
				$this->add_submission($v['id']);
	}

	/**
	 * Retrieves the scores of a particular scoreboard of a particular contest
	 *
	 * This function returns the scores of $target scoreboard of the contest whose ID is $contest_id.
	 *
	 * @param string $target 	The target scoreboard.
	 * @param int $contest_id 	The contest ID.
	 */

	public function get_scores($target, $contest_id, $user_id = -1)
	{
		$contest = $this->contest_manager->get_row($contest_id);

		// if the current time is greater than the unfreeze time, change target to admin.
		// hack bentar
		if ($target == 'contestant' && time() > strtotime($contest['unfreeze_time']))
		{
			$target = 'admin';
			$user_id = -1;
		}

		$this->db->select($_ENV['DB_USER_TABLE_NAME'].'.id, '.$_ENV['DB_USER_TABLE_NAME'] . '.name, '. $_ENV['DB_USER_TABLE_NAME'] . '.username, '. $_ENV['DB_USER_TABLE_NAME'] . '.institution');
		$this->db->from($_ENV['DB_USER_TABLE_NAME']);
		$this->db->join($_ENV['DB_CONTEST_MEMBER_TABLE_NAME'], $_ENV['DB_CONTEST_MEMBER_TABLE_NAME'].'.category_id=' . $_ENV['DB_USER_TABLE_NAME'] . '.category_id');
		$this->db->where($_ENV['DB_CONTEST_MEMBER_TABLE_NAME'] . '.contest_id', $contest_id);
		if ($user_id != -1)
			$this->db->where($_ENV['DB_USER_TABLE_NAME'] . '.id', $user_id);

		$q = $this->db->get();
		$users = $q->result_array();

		$this->db->select($_ENV['DB_PROBLEM_TABLE_NAME'] . '.id, '. $_ENV['DB_PROBLEM_TABLE_NAME'] . '.name, alias, SUM('. $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] . '.score) as maximum_score');
		$this->db->from($_ENV['DB_PROBLEM_TABLE_NAME']);
		$this->db->join($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'], $_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] . '.problem_id='.$_ENV['DB_PROBLEM_TABLE_NAME'].'.id');
		$this->db->join($_ENV['DB_TESTCASE_PACKET_TABLE_NAME'], $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] .'.problem_id='.$_ENV['DB_PROBLEM_TABLE_NAME'].'.id');
		$this->db->where($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] . '.contest_id', $contest_id);
		$this->db->order_by('alias');
		$this->db->group_by($_ENV['DB_PROBLEM_TABLE_NAME'] . '.id');
		$q = $this->db->get();
		$res['problems'] = $q->result_array();
		$res['scores'] = array();

		$this->db->from($this->get_table_name($target));
		$this->db->where('contest_id', $contest_id);
		$q = $this->db->get();
		$scores = $q->result_array();

		$max_scores = array();
		$max_total_score = 0;

		foreach ($res['problems'] as $w)
		{
			$max_scores[$w['id']] = $w['maximum_score'];
			$max_total_score += $w['maximum_score'];
		}

		foreach ($users as $v) 
		{
			$res['scores'][$v['id']]['name'] = $v['name'];
			$res['scores'][$v['id']]['username'] = $v['username'];
			$res['scores'][$v['id']]['institution'] = $v['institution'];

			$res['scores'][$v['id']]['last_submit_time'] = 0;
			$res['scores'][$v['id']]['score'] = array();
			$res['scores'][$v['id']]['total_score'] = 0;

			foreach ($res['problems'] as $w)
			{
				$res['scores'][$v['id']]['score'][$w['id']]['score'] = 0;
				$res['scores'][$v['id']]['score'][$w['id']]['color'] = $this->score_to_color(0, 100); // force red
			}
		}

		foreach ($scores as $v)
		{
			if (isset($res['scores'][$v['user_id']]['score'][$v['problem_id']]))
			{
				if ($v['score'] > $res['scores'][$v['user_id']]['score'][$v['problem_id']]['score'])
				{
					$res['scores'][$v['user_id']]['total_score'] += $v['score'] - $res['scores'][$v['user_id']]['score'][$v['problem_id']]['score'];
					$res['scores'][$v['user_id']]['score'][$v['problem_id']]['score'] = $v['score'];
					$res['scores'][$v['user_id']]['score'][$v['problem_id']]['color'] = $this->score_to_color($v['score'], $max_scores[$v['problem_id']]);

					if ($v['last_submit_time'] > $res['scores'][$v['user_id']]['last_submit_time'])
						$res['scores'][$v['user_id']]['last_submit_time'] = $v['last_submit_time'];
				}
			}
		}

		foreach ($users as $v)
		{
			$res['scores'][$v['id']]['total_color'] = $this->score_to_color($res['scores'][$v['id']]['total_score'], $max_total_score);
		}

		usort($res['scores'], array('Scoreboard_manager', 'score_cmp'));
		return $res;
	}

	/**
	 * The comparison function of the scores
	 *
	 * This function returns the relative ordering of the user whose ID is $a and the user whose ID is $b.
	 *
	 * @param int $a A user ID.
	 * @param int $b Another user ID.
	 *
	 * @return int The ordering.
	 */
	private function score_cmp($a, $b)
	{
		if ($a['total_score'] != $b['total_score'])
			return $b['total_score'] - $a['total_score'];

		if ($a['last_submit_time'] != $b['last_submit_time'])
			return $a['last_submit_time'] - $b['last_submit_time'];

		if ($a['name'] == $b['name'])
			return 0;

		return $a['name'] < $b['name'] ? -1 : 1;
	}

	private static function get_percent_colors()
	{
		return
		[
			['percentage' => 0.0, 'color' => ['r' => 0xF4, 'g' => 0x00, 'b' => 0x00]],
			['percentage' => 0.5, 'color' => ['r' => 0xB9, 'g' => 0x9F, 'b' => 0x00]],
			['percentage' => 1.0, 'color' => ['r' => 0x4E, 'g' => 0x9A, 'b' => 0x05]]
		];
	}

	/**
	 * Returns hex color code for scoreboard cell's background based on a score and maximum_score.
	 *
	 * This function returns the hex color code for scoreboard cell's background for each score cell. For 0 score, it
	 * will be reddish (#F40000). For 100 score, it will be greenish (#4E9A05). For 50 score, it will be yellow-brown-ish
	 * (#B99F00). For in-between scores, it will be the color relative to both color based on the percentage
	 * (closer to 0-50 or 50-100).
	 *
	 * @param int $score The score.
	 * @param int $maximum_score The maximum score.
	 *
	 * @return string The hex color code.
	 */
	private function score_to_color($score, $maximum_score)
	{
		$percent_colors = $this->get_percent_colors();
		$score_percentage = $maximum_score == 0 ? 2 : 1.0 * $score / $maximum_score;
		for ($i = 1; $i < count($percent_colors) - 1; ++$i)
			if ($score_percentage < $percent_colors[$i]['percentage'])
				break;

		$lower = $percent_colors[$i - 1];
		$upper = $percent_colors[$i];

		$range = $upper['percentage'] - $lower['percentage'];
		$relative_percentage = 1. * ($score_percentage - $lower['percentage']) / $range;
		$lower_percentage = 1.00 - $relative_percentage;
		$upper_percentage = $relative_percentage;

		$res = '#';
		$res .= str_pad(dechex(floor($lower['color']['r'] * $lower_percentage + $upper['color']['r'] * $upper_percentage)), 2, '0', STR_PAD_LEFT);
		$res .= str_pad(dechex(floor($lower['color']['g'] * $lower_percentage + $upper['color']['g'] * $upper_percentage)), 2, '0', STR_PAD_LEFT);
		$res .= str_pad(dechex(floor($lower['color']['b'] * $lower_percentage + $upper['color']['b'] * $upper_percentage)), 2, '0', STR_PAD_LEFT);
		return $res;
	}

	/**
	 * Clears data based on specification specified by $args.
	 *
	 * This function resets scoreboard entry to default entry for each entry that satisfies specification $args.
	 *
	 * @param string $target Target scoreboard (admin or constestant).
	 * @param array $args Data clearance specification.
	 */
	public function clear_data($target, $args)
	{
		foreach ($args as $k => $v)
			$this->db->where($k, $v);
		$this->db->set('last_submit_time', 0);
		$this->db->set('score', 0);

		$this->db->update($this->get_table_name($target));
	}

	public function get_table_name($target) {
		if ($target == 'admin')
			return $_ENV['DB_SCOREBOARD_ADMIN_TABLE_NAME'];
		else if ($target == 'contestant')
			return $_ENV['DB_SCOREBOARD_CONTESTANT_TABLE_NAME'];
	}
}


/* End of file scoreboard_manager.php */
/* Location: ./application/models/scoreboard_manager.php */