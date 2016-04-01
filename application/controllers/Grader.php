<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Controller for grader engine
 *
 * This is the controller responsible for grading submissions. This controller can only be accessed by command line.
 *
 * @package controllers
 * @author Ashar Fuadi <fushar@gmail.com>
 */
class Grader extends CI_Controller 
{
	/**
	 * Constructs a new controller object
	 *
	 * This function constructs a new controller. It also checks whether the controller is not accessed by web browser.
	 * If so, it shows the 404 page.
	 */
	public function __construct()
	{
		if (isset($_SERVER['REMOTE_ADDR']))
			show_404();

		parent::__construct();
		$this->load->model('submission_manager');
		$this->load->model('contest_manager');
		$this->load->model('scoreboard_manager');
		$this->load->language('submission');
	}

	private $grader_name;

	/**
	 * Runs the grader
	 *
	 * This function runs the grader. The grader will check and grade a submission every 1 second.
	 * @param $number The number (or label) of the grader that will be run.
	 */
	public function run($number)
	{
		set_time_limit(0);

		$this->grader_name = php_uname('n') . '-' . $number;

		// Adds entry to the database on table Grader if no Grader entry with hostname 'grader_name' exists
		$q = $this->db->query("SELECT * FROM `" . $_ENV['DB_GRADER_TABLE_NAME'] . "` WHERE hostname='" . $this->grader_name . "'");
		if ($q->num_rows() == 0)
		{
			$this->logger->log('grader', 'No grader entry with hostname = ' . $this->grader_name . ' found. Creating a new one...');
			if ( ! $this->db->query("INSERT INTO `" . $_ENV['DB_GRADER_TABLE_NAME'] . "` (hostname, last_activity, pid) VALUES ('" . $this->grader_name . "', NOW(), " . getmypid() . ")"))
			{
				$this->logger->log('grader', 'Entry creation failed!');
				die(0);
			}
		}
		
		$this->logger->log('grader', 'Grader ' . $this->grader_name . ' started.');
		$this->logger->log('grader', 'Grader ' . $this->grader_name . ' is waiting for new submission...');

		while (TRUE)
		{
			sleep(1);

			$this->check_in();
			if ( ! $this->has_new_submission())
				continue;

			$submission = $this->get_new_submission();
			if ($submission == false)
				continue ;

			$this->grade_submission($submission);
			$this->logger->log('grader', 'Waiting for new submission...');
		}
	}

	/**
	 * Checks in the grader
	 *
	 * This function set the current value of 'last_activity' attribute of the grader to the current time. This is a way
	 * for checking whether the grader is running or not.
	 */
	private function check_in()
	{
		$q = $this->db->query('UPDATE `' . $_ENV['DB_GRADER_TABLE_NAME'] . '` SET last_activity=NOW(), pid=' . getmypid() . ' WHERE hostname="' . $this->grader_name . '"');
	}

	/**
	 * Checks for a new submission
	 *
	 * This function checks whether there is a new submission or a regrade request.
	 * 
	 * @return boolean TRUE if there is at least a new submission or a regrade request, or FALSE otherwise.
	 */
	private function has_new_submission()
	{
		$q = $this->db->query('SELECT COUNT(id) AS cnt FROM `' . $_ENV['DB_SUBMISSION_TABLE_NAME'] . '` WHERE verdict<=0 AND verdict<>-85 LIMIT 1');
		$res = $q->row_array();
		return $res['cnt'] > 0;
	}

	/**
	 * Retrieve a new submission
	 *
	 * This function retrieves a new submission or a regrade request.
	 * 
	 * @return mixed A new submission or a regrade request, or FALSE if there is none.
	 */
	private function get_new_submission()
	{
		$sem_res = sem_get(SEM_KEY, 1, 0666, 0);

		if (sem_acquire($sem_res))
		{
			$q = $this->db->query('SELECT * FROM `' . $_ENV['DB_SUBMISSION_TABLE_NAME'] . '` WHERE verdict<=0 AND verdict<>-85 ORDER BY id ASC LIMIT 1');
			if ($q->num_rows() == 0)
				$submission = FALSE;
			else
			{
				$submission = $q->row_array();
				if ($submission['verdict'] == -99)
				{
					$this->db->query("UPDATE `" . $_ENV['DB_SUBMISSION_TABLE_NAME'] . "` SET verdict=99 WHERE id=" . $submission['id']);
					$submission = FALSE;
				}
				else
					$this->db->query("UPDATE `" . $_ENV['DB_SUBMISSION_TABLE_NAME'] . "` SET verdict=-85 WHERE id=" . $submission['id']);
			}
			sem_release($sem_res);
			return $submission;
		}
		return FALSE;
	}

	/**
	 * Grades a submission
	 *
	 * This function grades the submission whose ID is $submission_id.
	 * 
	 * @param array $submission The submission ID.
	 */
	private function grade_submission($submission)
	{
		$this->db->trans_start();

		$language = $this->get_language($submission['language_id']);
		$user = $this->get_user($submission['user_id']);
		$contest = $this->get_contest($submission['contest_id']);
		$problem = $this->get_problem($submission['problem_id']);
		$alias = $this->get_alias($submission['contest_id'], $submission['problem_id']);

		$msg  = 'Found new submission!' . "\n";
		$msg .= '                      Grader         : ' . $this->grader_name . "\n";
		$msg .= '                      Submission     : ' . $submission['id'] . "\n";
		$msg .= '                      User           : ' . $user['username'] . ' (' . $user['name'] . ')' . "\n";
		$msg .= '                      Contest        : ' . $contest['id'] . ' (' . $contest['name'] . ')' . "\n";
		$msg .= '                      Problem        : ' . @$alias['alias'] . ' (' . $problem['name'] . ')' . "\n";
		$msg .= '                      Language       : ' . $language['name'];
		$this->logger->log('grader', $msg);

		// the previous verdict of this submission. If this is a new submission, the value is 0.
		$prev_verdict = ['overall_verdict' => 0, 'total_score' => 0];

		// a request to regrade this submission
		if ($submission['verdict'] < 0)
			$prev_verdict['overall_verdict'] = -$submission['verdict'];

		// a request to ignore this submission
		if ($prev_verdict['overall_verdict'] == 99)
			$this->set_submission_verdict($submission, 99, $prev_verdict);
		else
		{
			$this->db->query('UPDATE `' . $_ENV['DB_SUBMISSION_TABLE_NAME'] . '` SET start_judge_time="' . date('Y-m-d H:i:s') . '" WHERE id=' . $submission['id']);
			$compile_status = $this->compile_submission($submission, $language);

			// if there is no compile error, run the solution
			if ($compile_status == 0)
				$verdict = $this->run_submission($submission, $problem, $language);
			else
				$verdict = ['overall_verdict' => 1, 'total_score' => 0];

			$this->set_submission_verdict($submission, $verdict, ['overall_verdict' => $prev_verdict, 'total_score' => $submission['score']]);
			$this->db->query('UPDATE `' . $_ENV['DB_SUBMISSION_TABLE_NAME'] . '` SET end_judge_time="' . date('Y-m-d H:i:s') . '" WHERE id=' . $submission['id']);
		}

		$this->db->trans_complete();
	}

	/**
	 * Compiles a submission
	 *
	 * This function grades the submission whose ID is $submission['id'] using the language whose ID is $language['id'].
	 *
	 * @param array $submission The submission.
	 * @param array $language The language.
	 * @return int  Return status of the source code's compilation.
	 */
	private function compile_submission($submission, $language)
	{
		$submission_path = $this->setting->get('submission_path') . '/' . $submission['id'];

		$code = file_get_contents($submission_path . '/source/' . $language['source_name']);
		$filter_result = $this->check_forbidden_keywords($code, $language);
		
		if (empty($filter_result))
		{
			$compile_cmd = str_replace('[PATH]', $submission_path . '/source' , $language['compile_cmd']) . ' 2>&1';
			exec($compile_cmd, $output, $retval);

			$compile_result = '';
			foreach ($output as $v)
				$compile_result .= str_replace($submission_path . '/source/', '', $v) . "\n";
			file_put_contents($submission_path . '/source/compile', $compile_result);
		}
		else
		{
			$retval = 1;
			file_put_contents($submission_path . '/source/compile', $filter_result);
		}
		
		$this->logger->log('grader', 'Compile status : ' . $retval);
		return $retval;
	}

	/**
	 * Sets a submission's verdict
	 *
	 * This function sets the verdict of the submission whose ID is $submission['id'] to $verdict.
	 * 
	 * @param array $submission The submission.
	 * @param array $verdict The new verdict for this submission.
	 * @param array $prev_verdict The previous verdict of this submission.
	 */
	private function set_submission_verdict($submission, $verdict, $prev_verdict)
	{
		$this->db->query('UPDATE `' . $_ENV['DB_SUBMISSION_TABLE_NAME'] . '` SET verdict=' . $verdict['overall_verdict'] . ', score=' . $verdict['total_score'] . ' WHERE id=' . $submission['id']);

		$this->logger->log('grader', 'Final verdict  : ' . $verdict['total_score'] . ' (' . $this->lang->line('verdict_' . $verdict['overall_verdict']) . ')');

		$this->scoreboard_manager->add_submission($submission['id']);

		// if this is a regrade request or ignore request, recalculate the scores.
		if ($prev_verdict != 0)
		{
			$args['contest_id'] = $submission['contest_id'];
			$args['problem_id'] = $submission['problem_id'];
			$args['user_id'] = $submission['user_id'];
			$this->scoreboard_manager->recalculate_scores($args);
		}
	}

	/**
	 * Runs a submission against the testcases
	 *
	 * This function runs the submission whose ID is $submission['id'] against the testcases, and returns the verdict.
	 *
	 * @param array $submission The submission.
	 * @param array $problem 	The problem.
	 * @param array $language 	The language.
	 *
	 * @return array $res An associative array:
	 *                      overall_verdict => The verdict for the submission on problem $problem
	 *                      total_score     => The score got by the submission
	 */
	private function run_submission($submission, $problem, $language)
	{
		$testcase_packets = $this->get_testcase_packets($submission['problem_id']);
		$checker = $this->get_checker($submission['problem_id']); // empty array if no checker

		$logger_message = '';

		$res['overall_verdict'] = 2; // Accepted
		$res['total_score'] = 0;

		$grader_path = 'moe/obj/box';
		$submission_path = $this->setting->get('submission_path') . '/' . $submission['id'];
		$checker_path = null;
		$checker_exec_path = null;

		if ($checker) // Get the executable version of the checker, if exists.
		{
			$checker_path = $this->setting->get('checker_path') . '/' . $problem['id'];
			$checker_exec_path = $checker_path . '/check';
		}

		$skip_testcases = false;
		$base_tc_path = $this->setting->get('testcase_path');

		foreach ($testcase_packets as $x)
		{
			$tc_path = $base_tc_path . '/' . $x['id'];
			$testcase_packet_verdict = 2; // accepted

			$testcases = $this->get_testcases($x['id']);

			foreach ($testcases as $v)
			{
				// remaining tcs should be skipped
				if (!$skip_testcases && $problem['progressive_scoring'] && $res['overall_verdict'] > 2) // not accepted, it is
					$skip_testcases = true;

				$verdict = 99; // skipped

				if (!$skip_testcases)
				{
					$out_path = $submission_path . '/judging/' . $v['id'];

					// creates directory for judging
					if ( ! is_dir($out_path))
					{
						mkdir($out_path);
						chmod($out_path, 0777);
					}

					$run_cmd  = $grader_path . '/box';

					if ($language['limit_memory'])
						$run_cmd .= ' -m' . (1024 * $problem['memory_limit']);

					if ($language['limit_syscall'])
						$run_cmd .= ' -f -a3';

					$run_cmd .= ' -w' . number_format(.001 * $problem['time_limit'], 3);
					$run_cmd .= ' -i' . $tc_path . '/' . $v['input'] ;
					$run_cmd .= ' -o' . $out_path . '/' . $v['output'] ;
					$run_cmd .= ' -r' . $out_path . '/error';
					$run_cmd .= ' -M' . $out_path . '/result';
					$run_cmd .= ' -- ' . str_replace(array('[PATH]', '[TIME_LIMIT]', '[MEMORY_LIMIT]'),
							array($submission_path . '/source', $problem['time_limit'], $problem['memory_limit']),
							$language['run_cmd'])
						. ' 2> /dev/null';

					exec($run_cmd, $output, $retval);

					// reads the execution results
					$run_result = array();
					foreach (explode("\n", file_get_contents($out_path . '/result')) as $w)
					{
						$line = explode(':', $w);
						$run_result[$line[0]] = @$line[1];
					}

					$verdict = 2; // Accepted
					if (@$run_result['status'] == 'SG' && @$run_result['exitsig'] == 11)
						$verdict = 4; // Runtime Error
					else if (@$run_result['status'] == 'TO')
						$verdict = 5; // Time Limit Exceeded
					else if (@$run_result['status'] == 'SG' && @$run_result['exitsig'] == 9)
						$verdict = 6; // Memory Limit Exceeded
					else if (@$run_result['status'] == 'FO')
						$verdict = 7; // Forbidden System Call
					else if (isset($run_result['status']))
						$verdict = 4; // Unknown Error; treated as Runtime Error
					else
					{
						if ($checker) // Use the checker
						{
							$checker_cmd  = $grader_path . '/box';
							$checker_cmd .= ' -w5';
							$checker_cmd .= ' -i' . $out_path . '/' . $v['output'] ;
							$checker_cmd .= ' -o' . $out_path . '/checker_op';
							$checker_cmd .= ' -r' . $out_path . '/error';
							$checker_cmd .= ' -M' . $out_path . '/result';
							$checker_cmd .= ' -- ' . $checker_exec_path . ' '
								. FCPATH . $tc_path . '/' . $v['input'] . ' '
								. FCPATH . $tc_path . '/' . $v['output']
								. ' 2> /dev/null';

							exec($checker_cmd, $output, $retval);

							$checker_content = explode("\n", file_get_contents($out_path . '/checker_op'));
							$checker_result = $checker_content[0];

							if ('[OK]' === $checker_result)
								$verdict = 2;
							else
								$verdict = 3;

							unlink($out_path . '/checker_op');
						}
						else
						{
							$diff_cmd = 'diff -q ' . $tc_path . '/' . $v['output'] . ' ' . $out_path . '/' . $v['output'] . ' > ' . $out_path . '/diff';
							exec($diff_cmd, $output, $retval);

							$diff = file_get_contents($out_path . '/diff');
							var_dump($diff);
							if ( ! empty($diff))
								$verdict = 3; // Wrong Answer
							unlink($out_path . '/diff');
						}
					}

					$run_result_time = ceil(((float)$run_result['time-wall']) * 1000);
					$run_result_memory = ceil($run_result['mem'] / 1024);
					$this->db->query('INSERT INTO `' . $_ENV['DB_JUDGING_TABLE_NAME'] . '`(submission_id, testcase_id, time, memory, verdict) VALUES(' . $submission['id'] . ', ' . $v['id'] . ', ' . $run_result_time . ', ' . $run_result_memory . ', ' . $verdict . ')');

					$logger_message .= 'TC ' . $v['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $verdict) . ' (' . $run_result_time . ' ms, ' . $run_result_memory . ' KB)';
					$logger_message .= PHP_EOL;
					// $this->logger->log('grader', 'TC ' . $v['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $verdict) . ' (' . $run_result_time . ' ms, ' . $run_result_memory . ' KB)');

					unlink($out_path . '/error');
					unlink($out_path . '/result');
					unlink($out_path . '/' . $v['output']);
					rmdir($out_path);
				}
				else
				{
					$this->db->query('INSERT INTO `' . $_ENV['DB_JUDGING_TABLE_NAME'] . '`(submission_id, testcase_id, time, memory, verdict) VALUES(' . $submission['id'] . ', ' . $v['id'] . ', ' . 0 . ', ' . 0 . ', ' . $verdict . ')');

					$logger_message .= 'TC ' . $v['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $verdict);
					$logger_message .= PHP_EOL;
					// $this->logger->log('grader', 'TC ' . $v['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $verdict));
				}

				if ($res['overall_verdict'] < $verdict && $verdict != 99 && $x['packet_order_id'] != 0)
					$res['overall_verdict'] = $verdict;

				if ($testcase_packet_verdict < $verdict)
					$testcase_packet_verdict = $verdict;
			}

			if ($testcase_packet_verdict == 2) // accepted
				$res['total_score'] += $x['score'];

			$this->db->query('INSERT INTO `' . $_ENV['DB_JUDGING_PACKET_TABLE_NAME'] . '`(submission_id, testcase_packet_id, verdict) VALUES(' . $submission['id'] . ', ' . $x['id'] . ', ' . $testcase_packet_verdict . ')');

			$logger_message .= 'TC Packet ' . $x['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $testcase_packet_verdict);
			$logger_message .= PHP_EOL;
			// $this->logger->log('grader', 'TC Packet' . $x['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $testcase_packet_verdict));
		}

		$logger_message .= 'Final verdict ' . $this->lang->line('verdict_' . $testcase_packet_verdict);
		$logger_message .= PHP_EOL;
		//$this->logger->log('grader', 'Final verdict' . $x['id'] . ' verdict   : ' . $this->lang->line('verdict_' . $testcase_packet_verdict));

		$this->logger->log('grader', $logger_message);

		unlink($submission_path . '/source/' . $language['exe_name']);
		return $res;
	}

	/**
	 * Checks whether a source code contains forbidden keywords
	 *
	 * This function checks whether $code contains one of the forbidden keywords in $language, and then returns the
	 * error message.
	 *
	 * @param array $code 		The source code.
	 * @param array $language 	The language.
	 *
	 * @return string The error message, or an empty string if $code does not contain any forbidden keywords.
	 */
	private function check_forbidden_keywords($code, $language)
	{
		$lines = explode("\n", $code);
		$lines_cnt = count($lines);

		$forbidden_keywords = explode("\n", $language['forbidden_keywords']);

		$error = '';
		for ($i = 0; $i < $lines_cnt; $i++)
		{
			foreach ($forbidden_keywords as $v)
				if ( ! empty($v) && preg_match('/\b' . $v . '\b/', $lines[$i]))
					$error .= $language['source_name'] . ':' . ($i+1) . ': forbidden keyword `' . $v . "'\n";
		}

		return $error;
	}

	/**
	 * Retrieves a particular user
	 *
	 * This function retrieves the name and username of the user whose ID is $user_id.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array The retrieved user.
	 */
	private function get_user($user_id)
	{
		$q = $this->db->query('SELECT name, username FROM `' . $_ENV['DB_USER_TABLE_NAME'] . '` WHERE id=' . $user_id . ' LIMIT 1');
		return $q->row_array();
	}
	/**
	 * Retrieves a particular contest
	 *
	 * This function retrieves the name of the contest whose ID is $contest_id.
	 * 
	 * @param int $contest_id The contest ID.
	 */
	private function get_contest($contest_id)
	{
		$q = $this->db->query('SELECT id, name FROM `' . $_ENV['DB_CONTEST_TABLE_NAME'] . '` WHERE id=' . $contest_id . ' LIMIT 1');
		return $q->row_array();
	}

	/**
	 * Retrieves the testcase packets of a particular problem
	 *
	 * This function retrieves the testcase packets of the problem whose ID is $problem_id.
	 *
	 * @param int $problem_id The problem ID.
	 *
	 * @return array The retrieved testcase packets.
	 */
	private function get_testcase_packets($problem_id)
	{
		$q = $this->db->query('SELECT id, packet_order_id, score FROM `' . $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] . '` WHERE problem_id=' . $problem_id . ' ORDER BY packet_order_id');
		return $q->result_array();
	}

	/**
	 * Retrieves the testcases of a particular testcase packet
	 *
	 * This function retrieves the testcasesof the testcase packet whose ID is $testcase_packet_id.
	 *
	 * @param int $testcase_packet_id The testcase packet ID.
	 *
	 * @return array The retrieved testcases.
	 */
	private function get_testcases($testcase_packet_id)
	{
		$q = $this->db->query('SELECT id, input, output FROM `' . $_ENV['DB_TESTCASE_TABLE_NAME'] . '` WHERE testcase_packet_id=' . $testcase_packet_id);
		return $q->result_array();
	}

	/**
	 * Retrieves the checker of a particular problem
	 *
	 * This function retrieves the checker name of the problem whose ID is $problem_id.
	 * 
	 * @param int $problem_id The problem ID.
	 * 
	 * @return array The retrieved checker. Returns an empty array if no checker exists.
	 */
	private function get_checker($problem_id)
	{
		$q = $this->db->query('SELECT id, checker FROM `' . $_ENV['DB_CHECKER_TABLE_NAME'] . '` WHERE problem_id=' . $problem_id);
		return $q->row_array();
	}

	/**
	 * Retrieves a particular problem
	 *
	 * This function retrieves the name, time limit, and memory limit of the problem whose ID is $problem_id.
	 * 
	 * @param int $problem_id The problem ID.
	 * 
	 * @return array The retrieved problem.
	 */
	private function get_problem($problem_id)
	{
		$q = $this->db->query('SELECT id, name, progressive_scoring, time_limit, memory_limit FROM `' . $_ENV['DB_PROBLEM_TABLE_NAME'] . '` WHERE id=' . $problem_id . ' LIMIT 1');
		return $q->row_array();
	}

	/**
	 * Retrieves the alias of a particular problem in a contest
	 *
	 * This function retrieves the alias of the problem whose ID is $problem_id in the contest whose ID is $contest_id.
	 * 
	 * @param int $user_id The user ID.
	 * 
	 * @return array The retrieved alias.
	 */
	private function get_alias($contest_id, $problem_id)
	{
		$q = $this->db->query('SELECT alias FROM `' . $_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] . '` WHERE contest_id=' . $contest_id . ' AND problem_id=' . $problem_id . ' LIMIT 1');
		return $q->row_array();
	}

	/**
	 * Retrieves a particular language
	 *
	 * This function retrieves the information of the language whose ID is $language_id.
	 * 
	 * @param int $language_id The language ID.
	 * 
	 * @return array The retrieved language.
	 */
	private function get_language($language_id)
	{
		$q = $this->db->query('SELECT id, name, source_name, exe_name, compile_cmd, run_cmd, limit_memory, limit_syscall, forbidden_keywords FROM `' . $_ENV['DB_LANGUAGE_TABLE_NAME'] . '` WHERE id=' . $language_id . ' LIMIT 1');
		return $q->row_array();
	}
}

/* End of file grader.php */
/* Location: ./application/controllers/grader.php */
