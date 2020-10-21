<?php

class db
{

	private static $now = null;
	// Configuration
	private $link = null;
	private $db_host;
	private $db_user;
	private $db_pass;
	private $db_name;
	private $db_time_zone;
	// Debug
	private $debug = false;
	private $debug_html = "";
	private $sql_time = 0;
	private $query_count = 0;
	// Transactions and locks
	private $transaction_started = false;
	private $table_lock = false;
	private $unlock_on_transaction_end = true;
	// Testing
	private $test_started = false;
	private $test_transaction_started = false;
	private $test_commit_performed = false;
	private $test_allow_queries_beyond_transaction = false;
	private $test_allow_modifying_queries_beyond_transaction = false;

	public function __construct($db_host, $db_user, $db_pass, $db_name, $db_time_zone = "")
	{
		$this->db_host = $db_host;
		$this->db_user = $db_user;
		$this->db_pass = $db_pass;
		$this->db_name = $db_name;
		$this->db_time_zone = $db_time_zone;
	}

	public function __destruct()
	{
		$this->disconnect();
	}

	private function connect()
	{
		$this->link = mysqli_connect($this->db_host, $this->db_user, $this->db_pass);
		if ($this->link === false)
		{
			trigger_error("Can't connect to MySQL: [{$this->db_user}@{$this->db_host}] (" . mysqli_error($this->link) . ")");
		}
		if (!mysqli_select_db($this->link, $this->db_name))
		{
			trigger_error("Can't select DB: [{$this->db_name}] (" . mysqli_error($this->link) . ")");
		}
		$result = mysqli_query($this->link, "SET NAMES 'utf8'");
		if (!$result)
		{
			trigger_error("Can't SET NAMES 'utf8' (" . mysqli_error($this->link) . ")");
		}
		if ($this->db_time_zone)
		{
			$result = mysqli_query($this->link, "SET time_zone = '{$this->db_time_zone}'");
			if (!$result)
			{
				trigger_error("Can't SET time_zone = '{$this->db_time_zone}' (" . mysqli_error($this->link) . ")");
			}
		}
	}

	public function disconnect()
	{
		if ($this->link)
		{
			mysqli_close($this->link);
			$this->link = null;
		}
	}

	private function is_modifying_sql($sql)
	{
		return substr(trim(strtoupper($sql)), 0, 6) != "SELECT";
	}

	private function is_modifying_sql_full_test($sql)
	{
		$sql_no_spaces = preg_replace("/[\r\n\t\x20]/", "", strtoupper($sql));
		return $this->is_modifying_sql($sql) or strpos($sql_no_spaces, "FORUPDATE") or strpos($sql_no_spaces, "LOCKINSHAREMODE");
	}

	public function sql($sql)
	{
		if ($this->test_started and ! $this->test_transaction_started)
		{
			if (!$this->test_allow_queries_beyond_transaction)
			{
				trigger_error("SQL query beyond a transaction is deprecated [{$sql}]");
				return false;
			}
			elseif (!$this->test_allow_modifying_queries_beyond_transaction and $this->is_modifying_sql_full_test($sql))
			{
				trigger_error("SQL modifying query beyond a transaction is deprecated [{$sql}]");
				return false;
			}
		}
		if (is_null($this->link))
		{
			$this->connect();
		}
		$this->query_count++;
		if ($this->debug)
		{
			$start_time = microtime(true);
		}
		$result = mysqli_query($this->link, $sql);
		if (!$result)
		{
			trigger_error("Can't execute SQL: [{$sql}] (" . mysqli_error($this->link) . ")");
		}
		if ($this->debug)
		{
			$end_time = microtime(true);
			$exec_time = ($end_time - $start_time);
			$exec_timeStr = sprintf("%.4f", $exec_time);

			if (preg_match("/^\s*select/i", $sql))
			{
				$eid = mysqli_query($this->link, "EXPLAIN $sql");

				$this->debug_html .= "
					<table width='95%' border='1' cellpadding='6' cellspacing='0' bgcolor='#FFE8F3' align='center'>
						<tr>
							<td colspan='8' style='font-size:14px' bgcolor='#FFC5Cb'><b>Select Query</b></td>
						</tr>
						<tr>
							<td colspan='8' style='font-family:courier, monaco, arial;font-size:14px;color:black'>" . nl2br(trim($sql)) . "</td>
						</tr>
						<tr bgcolor='#FFC5Cb'>
							<td><b>table</b></td><td><b>type</b></td><td><b>possible_keys</b></td>
							<td><b>key</b></td><td><b>key_len</b></td><td><b>ref</b></td>
							<td><b>rows</b></td><td><b>Extra</b></td>
						</tr>\n
				";
				while ($array = mysqli_fetch_array($eid))
				{

					$type_col = '#FFFFFF';
					if ($array['type'] == 'ref' or $array['type'] == 'eq_ref' or $array['type'] == 'const')
					{
						$type_col = '#D8FFD4';
					}
					elseif ($array['type'] == 'ALL')
					{
						$type_col = '#FFEEBA';
					}

					$this->debug_html .= "
						<tr bgcolor='#FFFFFF'>
							<td>$array[table]&nbsp;</td>
							<td bgcolor='$type_col'>$array[type]&nbsp;</td>
							<td>$array[possible_keys]&nbsp;</td>
							<td>$array[key]&nbsp;</td>
							<td>$array[key_len]&nbsp;</td>
							<td>$array[ref]&nbsp;</td>
							<td>$array[rows]&nbsp;</td>
							<td>$array[Extra]&nbsp;</td>
							</tr>\n
					";
				}

				$this->sql_time += $exec_time;

				if ($exec_time > 0.05)
				{
					$exec_time = "<span style='color:red'><b>$exec_time</b></span>";
				}

				$this->debug_html .= "
						<tr>
							<td colspan='8' bgcolor='#FFD6DC' style='font-size:14px'><b>MySQL time</b>: $exec_time</b></td>
						</tr>
					</table>\n<br />\n
				";
			}
			else
			{
				$this->sql_time += $exec_time;

				if ($exec_time > 0.05)
				{
					$exec_time = "<span style='color:red'><b>$exec_time</b></span>";
				}

				$this->debug_html .= "
					<table width='95%' border='1' cellpadding='6' cellspacing='0' bgcolor='#FEFEFE'  align='center'>
						<tr>
							<td style='font-size:14px' bgcolor='#EFEFEF'><b>Non Select Query</b></td>
						</tr>
						<tr>
							<td style='font-family:courier, monaco, arial;font-size:14px'>" . nl2br(trim($sql)) . "</td>
						</tr>
						<tr>
							<td style='font-size:14px' bgcolor='#EFEFEF'><b>MySQL time</b>: $exec_time</span></td>
						</tr>
					</table><br />\n\n
				";
			}
		}
		return $result;
	}

	public function is_db_result($var)
	{
		return is_object($var) && get_class($var) === "mysqli_result";
	}

	// @todo autoescape as default parameter
	public function update_by_array($table_name, $fields_update_array, $where)
	{
		$sql = "UPDATE `{$table_name}` SET ";
		$sql_set_parts = array();
		foreach ($fields_update_array as $idx => $val)
		{
			$sql_set_parts[] = "`" . $this->escape($idx) . "` = " . $val;
		}
		$sql .= join(", ", $sql_set_parts);
		$sql .= " WHERE {$where}";
		return $this->sql($sql);
	}

	// @todo autoescape as default parameter
	public function insert_by_array($table_name, $fields_update_array, $on_duplicate_key_update = false, $exclude_on_update_as_keys = array())
	{
		$sql = "INSERT INTO `{$table_name}` SET ";
		$sql_set_parts = array();
		foreach ($fields_update_array as $idx => $val)
		{
			$sql_set_parts[] = "`" . $this->escape($idx) . "` = " . $val;
		}
		$sql .= join(", ", $sql_set_parts);
		if ($on_duplicate_key_update)
		{
			$sql .= " ON DUPLICATE KEY UPDATE ";
			$sql_set_parts = array();
			foreach ($fields_update_array as $idx => $val)
			{
				if (!isset($exclude_on_update_as_keys[$idx]))
				{
					$sql_set_parts[] = "`" . $this->escape($idx) . "` = VALUES(`" . $this->escape($idx) . "`)";
				}
			}
			$sql .= join(", ", $sql_set_parts);
		}
		return $this->sql($sql);
	}

	public function multi_insert($table_name, $fields_array, $data, $autoescape = true)
	{
		$sql = "INSERT INTO `{$table_name}` ";
		$fields_sql_parts = array();
		foreach ($fields_array as $field_name)
		{
			$fields_sql_parts[] = "`" . $this->escape($field_name) . "`";
		}
		$sql .= "(" . join(", ", $fields_sql_parts) . ") VALUES ";
		$data_sql_parts = array();
		foreach ($data as $row)
		{
			$row_sql_parts = array();
			foreach ($row as $cell)
			{
				$row_sql_parts[] = is_null($cell) ? "NULL" : ($autoescape ? "'" . $this->escape($cell) . "'" : $cell);
			}
			$data_sql_parts[] = "(" . join(", ", $row_sql_parts) . ")";
		}
		$sql .= join(", ", $data_sql_parts);
		return $this->sql($sql);
	}

	public function fetch_object(mysqli_result $db_result)
	{
		return mysqli_fetch_object($db_result);
	}

	public function fetch_array(mysqli_result $db_result)
	{
		return mysqli_fetch_assoc($db_result);
	}

	public function fetch_row(mysqli_result $db_result)
	{
		return mysqli_fetch_row($db_result);
	}

	public function fetch_all($sql_or_db_result, $key_column_name = null)
	{
		$db_result = is_string($sql_or_db_result) ? $this->sql($sql_or_db_result) : $sql_or_db_result;
		$result = array();
		while ($row = $this->fetch_array($db_result))
		{
			if ($key_column_name)
			{
				$result[$row[$key_column_name]] = $row;
			}
			else
			{
				$result[] = $row;
			}
		}
		return $result;
	}

	public function fetch_column_values($sql_or_db_result, $column_name = null, $key_column_name = null)
	{
		$db_result = is_string($sql_or_db_result) ? $this->sql($sql_or_db_result) : $sql_or_db_result;
		$result = array();
		while ($row = mysqli_fetch_array($db_result))
		{
			if ($key_column_name)
			{
				if (!is_null($column_name))
				{
					$result[$row[$key_column_name]] = !is_string($column_name) ? $column_name : $row[$column_name];
				}
				else
				{
					$result[$row[$key_column_name]] = $row[0];
				}
			}
			else
			{
				if (!is_null($column_name))
				{
					$result[] = !is_string($column_name) ? $column_name : $row[$column_name];
				}
				else
				{
					$result[] = $row[0];
				}
			}
		}
		return $result;
	}

	public function get_value($sql_or_db_result)
	{
		$db_result = is_string($sql_or_db_result) ? $this->sql($sql_or_db_result) : $sql_or_db_result;
		if (!$db_row = mysqli_fetch_array($db_result))
		{
			return false;
		}
		return (array_key_exists(0, $db_row) ? $db_row[0] : false);
	}

	public function get_row($sql_or_db_result)
	{
		$db_result = is_string($sql_or_db_result) ? $this->sql($sql_or_db_result) : $sql_or_db_result;
		if (!$db_row = mysqli_fetch_assoc($db_result))
		{
			return false;
		}
		return $db_row;
	}

	public function get_selected_row_count(mysqli_result $db_result)
	{
		return mysqli_num_rows($db_result);
	}

	public function get_affected_row_count()
	{
		return mysqli_affected_rows($this->link);
	}

	public function row_exists($sql)
	{
		$db_result = $this->sql($sql);
		return ($this->get_selected_row_count($db_result) > 0);
	}

	public function get_last_id()
	{
		return mysqli_insert_id($this->link);
	}

	public function escape($str)
	{
		if (is_null($this->link))
		{
			$this->connect();
		}
		return mysqli_real_escape_string($this->link, $str);
	}

	public function get_datetime($time, $with_quotes = false)
	{
		return ($with_quotes ? "'" : "") . date("Y-m-d H:i:s", $time) . ($with_quotes ? "'" : "");
	}

	public function get_now($with_quotes = false)
	{
		if (is_null(self::$now))
		{
			self::$now = date("Y-m-d H:i:s");
		}
		return ($with_quotes ? "'" : "") . self::$now . ($with_quotes ? "'" : "");
	}

	public function parse_datetime($datetime_string)
	{
		if (is_null($datetime_string))
		{
			$datetime_string = "0000-00-00 00:00:00";
		}
		// Splits YYYY-MM-DD HH:mm:ss and YYYY-MM-DD
		$datetime = explode(" ", $datetime_string);
		if (!isset($datetime[1]))
		{
			$datetime[1] = "00:00:00"; // For DATE (not DATETIME)
		}
		$date_arr = explode("-", $datetime[0]);
		$time_arr = explode(":", $datetime[1]);
		$out_date = mktime($time_arr[0], $time_arr[1], $time_arr[2], $date_arr[1], $date_arr[2], $date_arr[0]);
		return $out_date;
	}

	public function debug_get_html()
	{
		return $this->debug_html;
	}

	public function debug_get_sql_time()
	{
		return $this->sql_time;
	}

	public function debug_get_query_count()
	{
		return $this->query_count;
	}

	public function set_debug_mode($debug)
	{
		$this->debug = $debug;
	}

	public function get_link()
	{
		return $this->link;
	}

	public function select_db($db_name)
	{
		$this->db_name = $db_name;
		if (is_null($this->link))
		{
			$this->connect();
		}
		else
		{
			if (!mysqli_select_db($this->link, $this->db_name))
			{
				trigger_error("Can't select DB: [{$this->db_name}] (" . mysqli_error($this->link) . ")");
			}
		}
	}

	public function get_db_name()
	{
		return $this->db_name;
	}

	private function _begin()
	{
		$this->sql("BEGIN");
		$this->transaction_started = true;
	}

	private function _commit()
	{
		$this->sql("COMMIT");
		if ($this->table_lock and $this->unlock_on_transaction_end)
		{
			$this->unlock_tables();
		}
		$this->transaction_started = false;
	}

	private function _rollback()
	{
		$this->sql("ROLLBACK");
		if ($this->table_lock and $this->unlock_on_transaction_end)
		{
			$this->unlock_tables();
		}
		$this->transaction_started = false;
	}

	public function is_transaction_started()
	{
		return $this->transaction_started;
	}

	public function is_table_lock()
	{
		return $this->table_lock;
	}

	public function set_unlock_on_transaction_end($unlock_on_transaction_end)
	{
		$this->unlock_on_transaction_end = $unlock_on_transaction_end ? true : false;
	}

	public function get_unlock_on_transaction_end()
	{
		return $this->unlock_on_transaction_end;
	}

	public function lock_tables($tables)
	{
		$this->table_lock = true;
		$this->sql("LOCK TABLES " . $tables);
	}

	public function unlock_tables()
	{
		$this->sql("UNLOCK TABLES");
		$this->table_lock = false;
	}

	public function begin()
	{
		if ($this->test_started)
		{
			$this->test_transaction_started = true;
		}
		$this->_begin();
	}

	public function commit()
	{
		if ($this->test_started)
		{
			$this->test_transaction_started = false;
			$this->test_commit_performed = true;
		}
		else
		{
			$this->_commit();
		}
	}

	public function rollback()
	{
		$this->_rollback();
		if ($this->test_started)
		{
			$this->test_transaction_started = false;
		}
	}

	public function test_begin()
	{
		$this->_rollback();
		$this->test_started = true;
		$this->test_transaction_started = false;
		$this->test_commit_performed = false;
		$this->test_allow_queries_beyond_transaction = false;
		$this->test_allow_modifying_queries_beyond_transaction = false;
	}

	public function test_is_transaction_finished()
	{
		return !$this->test_transaction_started;
	}

	public function test_is_commit_performed()
	{
		return $this->test_commit_performed;
	}

	public function test_set_allow_queries_beyond_transaction($allow_queries_beyond_transaction = true)
	{
		$this->test_allow_queries_beyond_transaction = $allow_queries_beyond_transaction;
	}

	public function test_get_allow_queries_beyond_transaction()
	{
		$this->test_allow_queries_beyond_transaction;
	}

	public function test_set_allow_modifying_queries_beyond_transaction($allow_modifying_queries_beyond_transaction = true)
	{
		$this->test_allow_modifying_queries_beyond_transaction = $allow_modifying_queries_beyond_transaction;
	}

	public function test_get_allow_modifying_queries_beyond_transaction()
	{
		$this->test_allow_modifying_queries_beyond_transaction;
	}

	public function test_end()
	{
		$this->test_started = false;
		$this->test_transaction_started = false;
		$this->test_commit_performed = false;
		$this->test_allow_queries_beyond_transaction = false;
		$this->test_allow_modifying_queries_beyond_transaction = false;
		$this->_rollback();
	}

}

?>