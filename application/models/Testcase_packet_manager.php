<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Model class that wraps table testcase_packet
 *
 * This is the active record class wrapper for table 'testcase_packet' from the database.
 *
 * @package models
 * @author  Pusaka Kaleb Setyabudi <sokokaleb@gmail.com>
 */
class Testcase_packet_manager extends AR_Model
{
	/**
	 * Constructs a new model object
	 *
	 * This function constructs a new model.
	 */
	public function __construct()
	{
		parent::__construct($_ENV['DB_TESTCASE_PACKET_TABLE_NAME']);
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
		$testcase_packet_path = $this->setting->get('testcase_path') . '/' . $insert_id;
		if ( ! is_dir($testcase_packet_path))
		{
			mkdir($testcase_packet_path);
			chmod($testcase_packet_path, 0777);
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
			assert(false);
			$this->db->select('id, name, author, time_limit, memory_limit, alias');
			$this->db->join($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'], $_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] .'.problem_id=id');
			$this->db->where($_ENV['DB_CONTEST_PROBLEM_TABLE_NAME'] .'.contest_id', $criteria['contest_id']);
		}
		else
		{
			$this->db->select($_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] .'.id as id, packet_order_id, short_description, score, COUNT('. $_ENV['DB_TESTCASE_TABLE_NAME'] .'.id) AS tc_count');
			$this->db->join($_ENV['DB_TESTCASE_TABLE_NAME'], $_ENV['DB_TESTCASE_TABLE_NAME'] . '.testcase_packet_id=' . $_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] .'.id', 'left outer');
			$this->db->group_by($_ENV['DB_TESTCASE_PACKET_TABLE_NAME'] .'.id');
		}

		return parent::get_rows($criteria, $conditions);
	}

	/**
	 * Adds a new testcase for a particular problem
	 *
	 * This function adds a new test case with input file $args['input'] and output file $args['output'] to the problem
	 * whose ID is $args['problem_id'].
	 *
	 * @param  array $args The parameter.
	 *
	 * @return string An empty string if there is no error, or the current language representation of:
	 * 				  - 'input_output_must_differ'
	 *                - 'input_has_been_used'
	 * 				  - 'output_has_been_used'
	 */
	public function add_testcase($args)
	{
		if ($args['input'] == $args['output'])
			return $this->lang->line('input_output_must_differ');

		$q = $this->db->query('SELECT * FROM '. $_ENV['DB_TESTCASE_TABLE_NAME'] .' WHERE testcase_packet_id=' . $args['testcase_packet_id'] . ' AND (input="' . $args['input'] . '" OR output="' . $args['input'] . '")');
		if ($q->num_rows() > 0)
			return $this->lang->line('input_has_been_used');

		$q = $this->db->query('SELECT * FROM '. $_ENV['DB_TESTCASE_TABLE_NAME'] .' WHERE testcase_packet_id=' . $args['testcase_packet_id'] . ' AND (input="' . $args['output'] . '" OR output="' . $args['output'] . '")');
		if ($q->num_rows() > 0)
			return $this->lang->line('output_has_been_used');

		$testcase_packet_path = $this->setting->get('testcase_path') . '/' . $args['testcase_packet_id'];

		$this->load->library('upload');
		$config['upload_path'] = $testcase_packet_path;
		$config['allowed_types'] = '*';
		$config['file_name'] = $args['input'];
		$config['remove_spaces'] = FALSE;

		$this->upload->initialize($config);
		$this->upload->do_upload('new_input');

		if ($this->upload->display_errors() != '')
			return $this->upload->display_errors();

		$config['upload_path'] = $testcase_packet_path;
		$config['allowed_types'] = '*';
		$config['file_name'] = $args['output'];
		$config['remove_spaces'] = FALSE;

		$this->upload->initialize($config);
		$this->upload->do_upload('new_output');

		if ($this->upload->display_errors() != '')
			return $this->upload->display_errors();

		$this->db->set('testcase_packet_id', $args['testcase_packet_id']);
		$this->db->set('input', $args['input']);
		$this->db->set('input_size', filesize($testcase_packet_path . '/' . $args['input']));
		$this->db->set('output', $args['output']);
		$this->db->set('output_size', filesize($testcase_packet_path . '/' . $args['output']));
		$this->db->insert($_ENV['DB_TESTCASE_TABLE_NAME']);
		return '';
	}

	/**
	 * Deletes a particular testcase packet
	 *
	 * This function removes the testcase packet whose ID is $testcase_packet_id.
	 *
	 * @param int $testcase_packet_id The testcase packet ID.
	 */
	public function delete_testcase_packet($testcase_packet_id)
	{
		$this->db->from($_ENV['DB_TESTCASE_PACKET_TABLE_NAME']);
		$this->db->where('id', $testcase_packet_id);
		$this->db->limit(1);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return ;
		$res = $q->row_array();

		foreach ($this->get_testcases($testcase_packet_id) as $v)
			$this->delete_testcase($v['id']);

		$this->db->where('id', $testcase_packet_id);
		$this->db->delete($_ENV['DB_TESTCASE_PACKET_TABLE_NAME']);

		$testcase_packet_path = $this->setting->get('testcase_path') . '/' . $res['id'];
		rmdir($testcase_packet_path);
	}

	/**
	 * Deletes a particular testcase from a particular problem
	 *
	 * This function removes the testcase whose ID is $testcase_id.
	 *
	 * @param int $testcase_id The testcase ID.
	 */
	public function delete_testcase($testcase_id)
	{
		$this->db->from($_ENV['DB_TESTCASE_TABLE_NAME']);
		$this->db->where('id', $testcase_id);
		$this->db->limit(1);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return;
		$res = $q->row_array();

		$this->db->where('id', $testcase_id);
		$this->db->delete($_ENV['DB_TESTCASE_TABLE_NAME']);

		$testcase_packet_path = $this->setting->get('testcase_path') . '/' . $res['testcase_packet_id'];
		unlink($testcase_packet_path . '/' . $res['input']);
		unlink($testcase_packet_path . '/' . $res['output']);
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
	public function get_testcase_packets($problem_id)
	{
		return $this->get_rows(['problem_id' => $problem_id], []);
	}

	/**
	 * Retrieves the testcases for a particular problem
	 *
	 * This function returns the testcases for the testcase packet whose ID is $testcase_testcase_packet_id.
	 *
	 * @param int $testcase_packet_id The testcase packet ID.
	 *
	 * @return array The testcases.
	 */
	public function get_testcases($testcase_packet_id)
	{
		$this->db->from($_ENV['DB_TESTCASE_TABLE_NAME']);
		$this->db->where('testcase_packet_id', $testcase_packet_id);
		$q = $this->db->get();
		return $q->result_array();
	}

	/**
	 * Retrieves a particular testcase
	 *
	 * This function returns the testcase whose ID is $testcase_id.
	 *
	 * @param int $testcase_id The testcase ID.
	 *
	 * @return array The testcase.
	 */
	public function get_testcase($testcase_id)
	{
		$this->db->from($_ENV['DB_TESTCASE_TABLE_NAME']);
		$this->db->where('id', $testcase_id);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return FALSE;
		return $q->row_array();
	}

	/**
	 * Retrieves a particular testcase file content
	 *
	 * This function returns the content of the testcase whose ID is $testcase_id.
	 *
	 *
	 * @param int $testcase_id The testcase ID.
	 *
	 * @return string - If $direction is 'in', then the content of the input file will be returned.
	 *                - If $direction is 'out', then the content of the output file will be returned.
	 *                - Otherwise, an empty string is returned.
	 */
	public function get_testcase_content($testcase_id, $direction)
	{
		$this->db->select('testcase_packet_id, ' . $direction);
		$this->db->from($_ENV['DB_TESTCASE_TABLE_NAME']);
		$this->db->where('id', $testcase_id);
		$this->db->limit(1);
		$q = $this->db->get();
		if ($q->num_rows() == 0)
			return '';
		$res = $q->row_array();

		$testcase_packet_id = $this->setting->get('testcase_path') . '/' . $res['testcase_packet_id'];
		return file_get_contents($testcase_packet_id . '/' . $res[$direction]);
	}
}

/* End of file problem_manager.php */
/* Location: ./application/models/contestant/problem_manager.php */