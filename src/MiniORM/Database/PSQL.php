<?php

namespace MiniORM\Database;

class PSQL {

	protected $database;
	protected $connection;

	public function __construct($database) {
		$this->database = $database;
	}

	public function get_param_query($query, $parameters, $columns = NULL, $force_return = FALSE) {
		$this->connect();

		if ($force_return) {
			$query .= ' RETURNING *';
		}

		$result = pg_query_params($this->connection, $query, $parameters);
		$rows = [];

		while ($row = pg_fetch_assoc($result)) {
			$rows []= $row;
		}

		$this->disconnect();
		return $rows;
	}

	protected function connect() {
		$database = $this->database;
		unset($database['vendor']);
		$string = array_reduce(array_keys($database), function ($carry, $key) use ($database) {
			return $carry . ' ' . $key . '=' . $database[$key];
		}, '');

		if (($this->connection = pg_connect($string)) === FALSE) {
			throw new ConnectionError('Connection error: '.pg_last_error());
		}
	}

	protected function disconnect() {
		pg_close($this->connection);
	}
}

class ConnectionError extends \Exception {
}
