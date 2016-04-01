<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Model class that wraps table problem
 *
 * This is the active record class wrapper for table 'problem' from the database.
 * 
 * @package models
 * @author  Ashar Fuadi <fushar@gmail.com>
 */
class Problem_manager extends AR_Model 
{
	/**
	 * Constructs a new model object
	 *
	 * This function constructs a new model.
	 */
	public function __construct()
	{
		parent::__construct($_ENV['DB_PROBLEM_TABLE_NAME']);
	}

	/**
	 * Inserts a new row into the table
	 *
	 * This function inserts a new row described by $attributes into the wrapped table. If a certain attribute is not
	 * present in $attributes, its default value will be inserted instead.
	 *
	 * This function overrides AR_Model::insert_row(). It will also create the directory for the problem's testcases.
	 * 
	 * @param  array $attributes The row to be inserted as an array of <attribute name> => <attribute value> pairs.
	 * 
	 * @return int               The newly inserted row ID.
	 */
	public function insert_row($attributes)
	{
		$insert_id = parent::insert_row($attributes);
		
		$checker_path = $this->setting->get('checker_path') . '/' . $insert_id;
		if ( ! is_dir($checker_path))
		{
			mkdir($checker_path);
			chmod($checker_path, 0777);
		}
		return $insert_id;
	}

	/**
	 * Retrieves zero or more rows from table 'problem' satisfying some criteria
	 *
	 * This function returns zero or more rows from the wrapped table that satisfy the given criteria. All attributes
	 * are returned.
	 *
	 * This function overrides AR_Model::get_rows(). It can also accept 'contest_id' as a criteria key; i.e., the
	 * contest IDs that this problem is assigned.
	 * 
	 * @param  array $criteria   An array of <attribute-name> => <attribute-value> pairs. Only rows that satisfy all
	 *                           criteria will be retrieved.
	 * @param  array $conditions An array of zero or more of the following pairs:
	 *                           - 'limit'    => The maximum number of rows to be retrieved.
	 *                           - 'offset'   => The starting number of the rows to be retrieved.
	 *                           - 'order_by' => The resulting rows order as an array of zero or more
	 *                                           <attribute-name> => ('ASC' | 'DESC') pairs.
	 * 
	 * @return array             Zero or more rows that satisfies the criteria as an array of <row-ID> => <row>.
	 */
	public function get_rows($criteria = array(), $conditions = array())
	{
		if (isset($criteria['contest_id']))
		{
			$this->db->select('id, name, author, time_limit, memory_limit, alias');
			$this->db->join($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'], $_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] . '.problem_id=id');
			$this->db->where($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] . '.contest_id', $criteria['contest_id']);
		}
		else
		{
			$this->db->select($_ENV['DB_PROBLEM_TABLE_NAME'] . '.id AS id, name, author, time_limit, memory_limit, COUNT(' . $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] . '.id) AS tc_count');
			$this->db->join($_ENV['DB_TESTCASE_PACKET_TABLE_NAME'], $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] . '.problem_id=' . $_ENV['DB_PROBLEM_TABLE_NAME'] . '.id', 'left outer');
			$this->db->group_by($_ENV['DB_PROBLEM_TABLE_NAME'] . '.id');
		}
		
		return parent::get_rows($criteria, $conditions);
	}

	/**
	 * Retrieves the testcases for a particular problem
	 *
	 * This function returns the testcases for the problem whose ID is $problem_id.
	 * 
	 * @param int $problem_id The problem ID.
	 *
	 * @return array The testcases.
	 */
	public function get_testcases($problem_id)
	{
		$this->db->from($_ENV['DB_TESTCASE_TABLE_NAME']);
		$this->db->where('problem_id', $problem_id);
		$q = $this->db->get();
		return $q->result_array();
	}

	
	/**
	 * Adds a new checker for a particular problem
	 *
	 * This function adds a new checker with file $args['checker'] to the problem whose ID is $args['problem_id']
	 * 
	 * @param  array $args The parameter.
	 * 
	 * @return string An empty string if there is no error, or the current language representation of:
	 *                - 'checker_exists'
	 *                - 'checker_compile_error'
	 */
	public function add_checker($args)
	{
		$q = $this->db->query('SELECT * FROM ' . $_ENV['DB_CHECKER_TABLE_NAME'] . ' WHERE problem_id=' . $args['problem_id']);
		if ($q->num_rows() > 0)
			return $this->lang->line('checker_exists');

		$checker_path = $this->setting->get('checker_path') . '/' . $args['problem_id'];
		
		$this->load->library('upload');
		$config['upload_path'] = $checker_path;
		$config['allowed_types'] = '*';
		$config['file_name'] = $args['checker'];
		$config['remove_spaces'] = FALSE;

		$this->upload->initialize($config);
		$this->upload->do_upload('new_checker');

		if ($this->upload->display_errors() != '')
			return $this->upload->display_errors();

		$compile_cmd = $_ENV['CPP_EXECUTABLE_PATH'] . ' -std=c++11 -fsyntax-only -w ' . $checker_path . '/' . $args['checker'] . ' 2>&1';
		exec($compile_cmd, $output, $retval);

		if (0 !== @$retval) // fail while compiling
		{
			unlink($checker_path . '/' . $args['checker']); // delete the uploaded file
			return $this->lang->line('checker_compile_error');
		}

		$compile_cmd = $_ENV['CPP_EXECUTABLE_PATH'] . ' -std=c++11 -w -O3 -o ' . $checker_path . '/check ' . $checker_path . '/' . $args['checker'] . ' 2>&1';
		exec($compile_cmd, $output, $retval);


		$this->db->set('problem_id', $args['problem_id']);
		$this->db->set('checker', $args['checker']);
		$this->db->set('checker_size', filesize($checker_path . '/' . $args['checker']));
		$this->db->insert($_ENV['DB_CHECKER_TABLE_NAME']);
		return '';
	}

	/**
	 * Deletes a particular checker from a particular problem
	 *
	 * This function removes the checker whose ID is $checker_id.
	 * 
	 * @param int $checker_id The checker ID.
	 */
	public function delete_checker($checker_id)
	{
		$this->db->from($_ENV['DB_CHECKER_TABLE_NAME']);
		$this->db->where('id', $checker_id);
		$this->db->limit(1);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return;
		$res = $q->row_array();

		$this->db->where('id', $checker_id);
		$this->db->delete($_ENV['DB_CHECKER_TABLE_NAME']);

		$checker_path = $this->setting->get('checker_path') . '/' . $res['problem_id'];
		unlink($checker_path . '/' . $res['checker']);
		unlink($checker_path . '/check');
	}

	/**
	 * Retrieves the checker for a particular problem
	 *
	 * This function returns the checker for the problem whose ID is $problem_id.
	 * 
	 * @param int $problem_id The problem ID.
	 *
	 * @return array The checker.
	 */
	public function get_checker_on_problem($problem_id)
	{
		$this->db->from($_ENV['DB_CHECKER_TABLE_NAME']);
		$this->db->where('problem_id', $problem_id);
		$q = $this->db->get();
		return $q->row_array();
	}

	/**
	 * Retrieves a checker based on checker_id
	 *
	 * This function returns a checker which ID is $checker_id.
	 * 
	 * @param int $checker_id The checker ID.
	 *
	 * @return array The checker.
	 */
	public function get_checker($checker_id)
	{
		$this->db->from($_ENV['DB_CHECKER_TABLE_NAME']);
		$this->db->where('id', $checker_id);
		$q = $this->db->get();
		return $q->row_array();
	}

	/**
	 * Retrieves a particular checker file content
	 *
	 * This function returns the content of the checker whose ID is $checker_id.
	 *
	 * 
	 * @param int $checker_id The checker ID.
	 *
	 * @return string The content of the checker file will be returned.
	 */
	public function get_checker_content($checker_id)
	{
		$this->db->select('problem_id, checker');
		$this->db->from($_ENV['DB_CHECKER_TABLE_NAME']);
		$this->db->where('id', $checker_id);
		$this->db->limit(1);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return '';
		$res = $q->row_array();

		$checker_path = $this->setting->get('checker_path') . '/' . $res['problem_id'];
		return file_get_contents($checker_path . '/' . $res['checker']);
	}
}

/* End of file problem_manager.php */
/* Location: ./application/models/contestant/problem_manager.php */