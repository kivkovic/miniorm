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
        $identified = FALSE;

        while ($row = pg_fetch_assoc($result)) {
            if (empty($identified) && !empty($columns)) {
                $identified = $this->identify_columns($result, $row, $columns);
            }
            if (!empty($identified)) {
                foreach ($row as $key => &$value) {
                    if (preg_match('/(int|serial)/i', $identified[$key]->type)) {
                        $value = (integer) $value;
                    } else if (preg_match('/(float|double|decimal)/i', $identified[$key]->type)) {
                        $value = (float) $value;
                    } else if(preg_match('/bool/', $identified[$key]->type)) {
                        $value = $value == 't' ? TRUE : FALSE;
                    }
                }
            }
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

    protected function identify_columns($result, $row, $columns = []) {
        $i = 0;
        foreach ($row as $key => $value) {
            if (!isset($columns[$key]) || !isset($columns[$key]->type)) {
                $columns[$key] = (object)['type' => pg_field_type($result, $i++)];
            }
        }
        return $columns;
    }
}

class ConnectionError extends \Exception {
}
