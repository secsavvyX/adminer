<?php
/**
* @author Jakub Cernohuby
* @author Vladimir Stastka
* @author Jakub Vrana
*/

$possible_drivers[] = "SQLSRV";
$possible_drivers[] = "MSSQL";
if (extension_loaded("sqlsrv") || extension_loaded("mssql")) {
	$drivers["mssql"] = "MS SQL";
}

if (isset($_GET["mssql"])) {
	define("DRIVER", "mssql");
	if (extension_loaded("sqlsrv")) {
		class Min_DB {
			var $extension = "sqlsrv", $_link, $_result, $server_info, $affected_rows, $error;

			function _get_error() {
				$this->error = "";
				foreach (sqlsrv_errors() as $error) {
					$this->error .= "$error[message]\n";
				}
				$this->error = rtrim($this->error);
			}

			function connect($server, $username, $password) {
				$this->_link = @sqlsrv_connect($server, array("UID" => $username, "PWD" => $password));
				if ($this->_link) {
					$info = sqlsrv_server_info($this->_link);
					$this->server_info = $info['SQLServerVersion'];
				} else {
					$this->_get_error();
				}
				return (bool) $this->_link;
			}

			function quote($string) {
				return "'" . str_replace("'", "''", $string) . "'";
			}

			function select_db($database) {
				return $this->query("USE $database");
			}

			function query($query, $unbuffered = false) {
				$result = sqlsrv_query($this->_link, $query); //! , array(), ($unbuffered ? array() : array("Scrollable" => "keyset"))
				if (!$result) {
					$this->_get_error();
					return false;
				}
				return $this->store_result($result);
			}

			function multi_query($query) {
				$this->_result = sqlsrv_query($this->_link, $query);
				if (!$this->_result) {
					$this->_get_error();
					return false;
				}
				return true;
			}

			function store_result($result = null) {
				if (!$result) {
					$result = $this->_result;
				}
				if (sqlsrv_field_metadata($result)) {
					return new Min_Result($result);
				}
				$this->affected_rows = sqlsrv_rows_affected($result);
				return true;
			}

			function next_result() {
				return sqlsrv_next_result($this->_result);
			}

			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!is_object($result)) {
					return false;
				}
				$row = $result->fetch_row();
				return $row[$field];
			}
		}

		class Min_Result {
			var $_result, $_offset = 0, $_fields, $num_rows;

			function Min_Result($result) {
				$this->_result = $result;
				// $this->num_rows = sqlsrv_num_rows($result); // available only in scrollable results
			}

			function _convert($row) {
				foreach ((array) $row as $key => $val) {
					if (is_a($val, 'DateTime')) {
						$row[$key] = $val->format("Y-m-d H:i:s");
					}
					//! stream
				}
				return $row;
			}
			
			function fetch_assoc() {
				return $this->_convert(sqlsrv_fetch_array($this->_result, SQLSRV_FETCH_ASSOC, SQLSRV_SCROLL_NEXT));
			}

			function fetch_row() {
				return $this->_convert(sqlsrv_fetch_array($this->_result, SQLSRV_FETCH_NUMERIC, SQLSRV_SCROLL_NEXT));
			}

			function fetch_field() {
				if (!$this->_fields) {
					$this->_fields = sqlsrv_field_metadata($this->_result);
				}
				$field = $this->_fields[$this->_offset++];
				$return = new stdClass;
				$return->name = $field["Name"];
				$return->orgname = $field["Name"];
				$return->type = ($field["Type"] == 1 ? 254 : 0);
				return $return;
			}
			
			function seek($offset) {
				for ($i=0; $i < $offset; $i++) {
					sqlsrv_fetch($this->_result); // SQLSRV_SCROLL_ABSOLUTE added in sqlsrv 1.1
				}
			}

			function __destruct() {
				sqlsrv_free_stmt($this->_result);
			}
		}
		
	} elseif (extension_loaded("mssql")) {
		class Min_DB {
			var $extension = "MSSQL", $_link, $_result, $server_info, $affected_rows, $error;

			function connect($server, $username, $password) {
				$this->_link = @mssql_connect($server, $username, $password);
				if ($this->_link) {
					$result = $this->query("SELECT SERVERPROPERTY('ProductLevel'), SERVERPROPERTY('Edition')");
					$row = $result->fetch_row();
					$this->server_info = $this->result("sp_server_info 2", 2) . " [$row[0]] $row[1]";
				} else {
					$this->error = mssql_get_last_message();
				}
				return (bool) $this->_link;
			}

			function quote($string) {
				return "'" . str_replace("'", "''", $string) . "'";
			}

			function select_db($database) {
				return mssql_select_db($database);
			}

			function query($query, $unbuffered = false) {
				$result = mssql_query($query, $this->_link); //! $unbuffered
				if (!$result) {
					$this->error = mssql_get_last_message();
					return false;
				}
				if ($result === true) {
					$this->affected_rows = mssql_rows_affected($this->_link);
					return true;
				}
				return new Min_Result($result);
			}

			function multi_query($query) {
				return $this->_result = $this->query($query);
			}

			function store_result() {
				return $this->_result;
			}

			function next_result() {
				return mssql_next_result($this->_result);
			}

			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!is_object($result)) {
					return false;
				}
				return mssql_result($result->_result, 0, $field);
			}
		}

		class Min_Result {
			var $_result, $_offset = 0, $_fields, $num_rows;

			function Min_Result($result) {
				$this->_result = $result;
				$this->num_rows = mssql_num_rows($result);
			}

			function fetch_assoc() {
				return mssql_fetch_assoc($this->_result);
			}

			function fetch_row() {
				return mssql_fetch_row($this->_result);
			}

			function num_rows() {
				return mssql_num_rows($this->_result);
			}

			function fetch_field() {
				$return = mssql_fetch_field($this->_result);
				$return->orgtable = $return->table;
				$return->orgname = $return->name;
				return $return;
			}

			function seek($offset) {
				mssql_data_seek($this->_result, $offset);
			}
			
			function __destruct() {
				mssql_free_result($this->_result);
			}
		}
		
	}

	function idf_escape($idf) {
		return "[" . str_replace("]", "]]", $idf) . "]";
	}

	function table($idf) {
		return ($_GET["ns"] != "" ? idf_escape($_GET["ns"]) . "." : "") . idf_escape($idf);
	}

	function connect() {
		global $adminer;
		$connection = new Min_DB;
		$credentials = $adminer->credentials();
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
			return $connection;
		}
		return $connection->error;
	}

	function get_databases() {
		return get_vals("EXEC sp_databases");
	}

	function limit($query, $where, $limit, $offset = 0, $separator = " ") {
		return (isset($limit) ? " TOP (" . ($limit + $offset) . ")" : "") . " $query$where"; // seek later
	}

	function limit1($query, $where) {
		return limit($query, $where, 1);
	}

	function db_collation($db, $collations) {
		global $connection;
		return $connection->result("SELECT collation_name FROM sys.databases WHERE name =  " . $connection->quote($db));
	}

	function engines() {
		return array();
	}

	function logged_user() {
		global $connection;
		return $connection->result("SELECT SUSER_NAME()");
	}

	function tables_list() {
		global $connection;
		return get_key_vals("SELECT name, type_desc FROM sys.all_objects WHERE schema_id = SCHEMA_ID(" . $connection->quote(get_schema()) . ") AND type IN ('S', 'U', 'V') ORDER BY name");
	}

	function count_tables($databases) {
		global $connection;
		$return = array();
		foreach ($databases as $db) {
			$connection->select_db($db);
			$return[$db] = $connection->result("SELECT COUNT(*) FROM information_schema.TABLES");
		}
		return $return;
	}
	
	function table_status($name = "") {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT name AS Name, type_desc AS Engine FROM sys.all_objects WHERE schema_id = SCHEMA_ID(" . $connection->quote(get_schema()) . ") AND type IN ('S', 'U', 'V')" . ($name != "" ? " AND name = " . $connection->quote($name) : ""));
		while ($row = $result->fetch_assoc()) {
			if ($name != "") {
				return $row;
			}
			$return[$row["Name"]] = $row;
		}
		return $return;
	}

	function is_view($table_status) {
		return $table_status["Engine"] == "VIEW";
	}
	
	function fk_support($table_status) {
		return true;
	}

	function fields($table, $hidden = false) {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT c.*, t.name type, d.definition [default]
FROM sys.all_columns c
JOIN sys.all_objects o ON c.object_id = o.object_id
JOIN sys.types t ON c.user_type_id = t.user_type_id
LEFT JOIN sys.default_constraints d ON c.default_object_id = d.parent_column_id
WHERE o.schema_id = SCHEMA_ID(" . $connection->quote(get_schema()) . ") AND o.type IN ('S', 'U', 'V') AND o.name = " . $connection->quote($table)
		);
		while ($row = $result->fetch_assoc()) {
			$type = $row["type"];
			$length = (ereg("char|binary", $type) ? $row["max_length"] : ($type == "decimal" ? "$row[precision],$row[scale]" : ""));
			$return[$row["name"]] = array(
				"field" => $row["name"],
				"full_type" => $type . ($length ? "($length)" : ""),
				"type" => $type,
				"length" => $length,
				"default" => $row["default"],
				"null" => $row["is_nullable"],
				"auto_increment" => $row["is_identity"],
				"collation" => $row["collation_name"],
				"privileges" => array("insert" => 1, "select" => 1, "update" => 1),
				"primary" => $row["is_identity"], //! or indexes.is_primary_key
			);
		}
		return $return;
	}

	function indexes($table, $connection2 = null) {
		global $connection;
		if (!is_object($connection2)) {
			$connection2 = $connection;
		}
		$return = array();
		// sp_statistics doesn't return information about primary key
		$result = $connection2->query("SELECT indexes.name, key_ordinal, is_unique, is_primary_key, columns.name AS column_name
FROM sys.indexes
INNER JOIN sys.index_columns ON indexes.object_id = index_columns.object_id AND indexes.index_id = index_columns.index_id
INNER JOIN sys.columns ON index_columns.object_id = columns.object_id AND index_columns.column_id = columns.column_id
WHERE OBJECT_NAME(indexes.object_id) = " . $connection2->quote($table)
		);
		if ($result) {
			while ($row = $result->fetch_assoc()) {
				$return[$row["name"]]["type"] = ($row["is_primary_key"] ? "PRIMARY" : ($row["is_unique"] ? "UNIQUE" : "INDEX"));
				$return[$row["name"]]["lengths"] = array();
				$return[$row["name"]]["columns"][$row["key_ordinal"]] = $row["column_name"];
			}
		}
		return $return;
	}

	function view($name) {
		global $connection;
		return array("select" => preg_replace('~^(?:[^`]|`[^`]*`)*\\s+AS\\s+~isU', '', $connection->result("SELECT view_definition FROM information_schema.views WHERE table_schema = SCHEMA_NAME() AND table_name = " . $connection->quote($name))));
	}
	
	function collations() {
		$return = array();
		foreach (get_vals("SELECT name FROM fn_helpcollations()") as $collation) {
			$return[ereg_replace("_.*", "", $collation)][] = $collation;
		}
		return $return;
	}

	function information_schema($db) {
		return false;
	}

	function error() {
		global $connection;
		return nl_br(h(preg_replace('~^(\\[[^]]*])+~m', '', $connection->error)));
	}
	
	function exact_value($val) {
		global $connection;
		return $connection->quote($val);
	}

	function create_database($db, $collation) {
		return queries("CREATE DATABASE " . idf_escape($db) . ($collation ? " COLLATE " . idf_escape($collation) : ""));
	}
	
	function drop_databases($databases) {
		return queries("DROP DATABASE " . implode(", ", array_map('idf_escape', $databases)));
	}
	
	function rename_database($name, $collation) {
		if ($collation) {
			queries("ALTER DATABASE " . idf_escape(DB) . " COLLATE " . idf_escape($collation));
		}
		queries("ALTER DATABASE " . idf_escape(DB) . " MODIFY NAME = " . idf_escape($name));
		return true; //! false negative "The database name 'test2' has been set."
	}

	function auto_increment() {
		return " IDENTITY" . ($_POST["Auto_increment"] != "" ? "(" . preg_replace('~\\D+~', '', $_POST["Auto_increment"]) . ",1)" : "");
	}
	
	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		global $connection;
		$alter = array();
		foreach ($fields as $field) {
			$column = idf_escape($field[0]);
			$val = $field[1];
			if (!$val) {
				$alter["DROP"][] = " COLUMN $field[0]";
			} else {
				$val[1] = preg_replace("~( COLLATE )'(\\w+)'~", "\\1\\2", $val[1]);
				if ($field[0] == "") {
					$alter["ADD"][] = "\n  " . implode("", $val);
				} else {
					unset($val[6]); //! identity can't be removed
					if ($column != $val[0]) {
						queries("EXEC sp_rename " . $connection->quote(table($table) . ".$column") . ", " . $connection->quote(idf_unescape($val[0])) . ", 'COLUMN'");
					}
					$alter["ALTER COLUMN " . implode("", $val)][] = "";
				}
			}
		}
		if ($table == "") {
			return queries("CREATE TABLE " . table($name) . " (" . implode(",", (array) $alter["ADD"]) . "\n)");
		}
		if ($table != $name) {
			queries("EXEC sp_rename " . $connection->quote(table($table)) . ", " . $connection->quote($name));
		}
		foreach ($alter as $key => $val) {
			if (!queries("ALTER TABLE " . idf_escape($name) . " $key" . implode(",", $val))) {
				return false;
			}
		}
		return true;
	}
	
	function alter_indexes($table, $alter) {
		$index = array();
		$drop = array();
		foreach ($alter as $val) {
			if ($val[2]) {
				if ($val[0] == "PRIMARY") { //! sometimes used also for UNIQUE
					$drop[] = $val[1];
				} else {
					$index[] = "$val[1] ON " . table($table);
				}
			} elseif (!queries(($val[0] != "PRIMARY"
				? "CREATE" . ($val[0] != "INDEX" ? " UNIQUE" : "") . " INDEX " . idf_escape(uniqid($table . "_")) . " ON " . table($table)
				: "ALTER TABLE " . table($table) . " ADD PRIMARY KEY"
			) . " $val[1]")) {
				return false;
			}
		}
		return (!$index || queries("DROP INDEX " . implode(", ", $index)))
			&& (!$drop || queries("ALTER TABLE " . table($table) . " DROP " . implode(", ", $drop)))
		;
	}
	
	function begin() {
		return queries("BEGIN TRANSACTION");
	}
	
	function insert_into($table, $set) {
		return queries("INSERT INTO " . table($table) . ($set ? " (" . implode(", ", array_keys($set)) . ")\nVALUES (" . implode(", ", $set) . ")" : "DEFAULT VALUES"));
	}
	
	function insert_update($table, $set, $primary) {
		$update = array();
		$where = array();
		foreach ($set as $key => $val) {
			$update[] = "$key = $val";
			if (isset($primary[idf_unescape($key)])) {
				$where[] = "$key = $val";
			}
		}
		// can use only one query for all rows with different API
		return queries("MERGE " . table($table) . " USING (VALUES(" . implode(", ", $set) . ")) AS source (c" . implode(", c", range(1, count($set))) . ") ON " . implode(" AND ", $where) //! source, c1 - possible conflict
			. " WHEN MATCHED THEN UPDATE SET " . implode(", ", $update)
			. " WHEN NOT MATCHED THEN INSERT (" . implode(", ", array_keys($set)) . ") VALUES (" . implode(", ", $set) . ");" // ; is mandatory
		);
	}
	
	function last_id() {
		global $connection;
		return $connection->result("SELECT SCOPE_IDENTITY()"); // @@IDENTITY can return trigger INSERT
	}
	
	function explain($connection, $query) {
		$connection->query("SET SHOWPLAN_ALL ON");
		$return = $connection->query($query);
		$connection->query("SET SHOWPLAN_ALL OFF"); // connection is used also for indexes
		return $return;
	}
	
	function foreign_keys($table) {
		global $connection;
		$result = $connection->query("EXEC sp_fkeys @fktable_name = " . $connection->quote($table));
		$return = array();
		while ($row = $result->fetch_assoc()) {
			$foreign_key = &$return[$row["FK_NAME"]];
			$foreign_key["table"] = $row["PKTABLE_NAME"];
			$foreign_key["source"][] = $row["FKCOLUMN_NAME"];
			$foreign_key["target"][] = $row["PKCOLUMN_NAME"];
		}
		return $return;
	}

	function truncate_tables($tables) {
		return apply_queries("TRUNCATE TABLE", $tables);
	}

	function drop_views($views) {
		return queries("DROP VIEW " . implode(", ", array_map('table', $views)));
	}

	function drop_tables($tables) {
		return queries("DROP TABLE " . implode(", ", array_map('table', $tables)));
	}

	function move_tables($tables, $views, $target) {
		return apply_queries("ALTER SCHEMA " . idf_escape($target) . " TRANSFER", array_merge($tables, $views));
	}
	
	function trigger($name) {
		global $connection;
		$result = $connection->query("SELECT s.name [Trigger],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(s.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(s.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(s.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing],
c.text
FROM sysobjects s
JOIN syscomments c ON s.id = c.id
WHERE s.xtype = 'TR' AND s.name = " . $connection->quote($name)
		); // triggers are not schema-scoped
		$row = $result->fetch_assoc();
		$row["Statement"] = preg_replace('~^.+\\s+AS\\s+~isU', '', $row["text"]); //! identifiers, comments
		return $row;
	}
	
	function triggers($table) {
		global $connection;
		$return = array();
		$result = $connection->query("SELECT sys1.name,
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsertTrigger') = 1 THEN 'INSERT' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsUpdateTrigger') = 1 THEN 'UPDATE' WHEN OBJECTPROPERTY(sys1.id, 'ExecIsDeleteTrigger') = 1 THEN 'DELETE' END [Event],
CASE WHEN OBJECTPROPERTY(sys1.id, 'ExecIsInsteadOfTrigger') = 1 THEN 'INSTEAD OF' ELSE 'AFTER' END [Timing]
FROM sysobjects sys1
JOIN sysobjects sys2 ON sys1.parent_obj = sys2.id
WHERE sys1.xtype = 'TR' AND sys2.name = " . $connection->quote($table)
		); // triggers are not schema-scoped
		while ($row = $result->fetch_assoc()) {
			$return[$row["name"]] = array($row["Timing"], $row["Event"]);
		}
		return $return;
	}
	
	function trigger_options() {
		return array(
			"Timing" => array("AFTER", "INSTEAD OF"),
			"Type" => array("AS"),
		);
	}
	
	function schemas() {
		return get_vals("SELECT name FROM sys.schemas");
	}
	
	function get_schema() {
		global $connection;
		if ($_GET["ns"] != "") {
			return $_GET["ns"];
		}
		return $connection->result("SELECT SCHEMA_NAME()");
	}
	
	function set_schema($schema) {
		return true; // ALTER USER is permanent
	}
	
	function use_sql($database) {
		return "USE " . idf_escape($database);
	}
	
	function show_variables() {
		return array();
	}
	
	function show_status() {
		return array();
	}

	function support($feature) {
		return ereg('^(scheme|trigger|view|drop_col)$', $feature); //! routine|
	}
	
	$jush = "mssql";
	$types = array();
	$structured_types = array();
	foreach (array( //! use sys.types
		lang('Numbers') => array("tinyint" => 3, "smallint" => 5, "int" => 10, "bigint" => 20, "bit" => 1, "decimal" => 0, "real" => 12, "float" => 53, "smallmoney" => 10, "money" => 20),
		lang('Date and time') => array("date" => 10, "smalldatetime" => 19, "datetime" => 19, "datetime2" => 19, "time" => 8, "datetimeoffset" => 10),
		lang('Strings') => array("char" => 8000, "varchar" => 8000, "text" => 2147483647, "nchar" => 4000, "nvarchar" => 4000, "ntext" => 1073741823),
		lang('Binary') => array("binary" => 8000, "varbinary" => 8000, "image" => 2147483647),
	) as $key => $val) {
		$types += $val;
		$structured_types[$key] = array_keys($val);
	}
	$unsigned = array();
	$operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL");
	$functions = array("len", "lower", "round", "upper");
	$grouping = array("avg", "count", "count distinct", "max", "min", "sum");
	$edit_functions = array(
		array(
			"date|time" => "getdate",
		), array(
			"int|decimal|real|float|money|datetime" => "+/-",
			"char|text" => "+",
		)
	);
}