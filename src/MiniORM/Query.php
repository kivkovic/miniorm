<?php

namespace MiniORM;

class Query {

    public $_global   = [];
    public $_select   = [];
    public $_from     = [];
    public $_join     = [];
    public $_where    = [];
    public $_group_by = [];
    public $_having   = [];
    public $_order_by = [];
    public $_limit    = NULL;
    public $_offset   = NULL;

    public static function __callStatic($method, $arguments) {
        Table::initialize_columns();
        $new = new self;

        if (in_array($method, ['not', 'exists', 'cast', 'call', 'as'])) {
            if ($method === 'cast' || $method === 'as') {
                $arguments[1] = Table::delimiter . $arguments[1];
            }
            $new->_global []= ['method' => $method, 'arguments' => $arguments];
            return $new;
        }

        return $new->__call($method, $arguments);
    }

    public function __call($method, $arguments) {

        $new = clone $this;
        $value = ['method' => $method, 'arguments' => $arguments];

        $escape = function ($value, $key) {
            if (isset($value[$key])) {
                $value[$key] = Table::delimiter . $value[$key];
            }
            return $value;
        };

        if (in_array($method, ['select', 'count'])) {
            $new->_select []= $value;

        } else if ($method === 'from') {
            $class = $value['arguments'][0];
            $value['arguments'][0] = Table::delimiter . $class::path();
            $new->_from []= $value;

        } else if (in_array($method, ['join','left_join','right_join','left_outer_join','right_outer_join','inner_join'])) {
            $value['arguments'] = $escape($value['arguments'], 2);
            $new->_join []= $value;

        } else if (in_array($method, ['where', 'where_or', 'where_and', 'having', 'having_or', 'having_and'])) {

            if (!preg_match('/_(or|and)$/i', $method)) {
                $method .= '_and';
            }
            if (isset($value['arguments'][1]) && preg_match('#^[-+*/=!<>~.]+$#', $value['arguments'][1])) {
                $value['arguments'] = $escape($value['arguments'], 1);
            }
            $new->{"_" . preg_replace('/_(or|and)$/i', '', $method)} []= $value;

        } else if (in_array($method, ['group_by', 'order_by'])) {
            if ($method === 'order_by') {
                $value['arguments'] = $escape($value['arguments'], 1);
            }
            $new->{"_{$method}"} []= $value;

        } else if (in_array($method, ['limit', 'offset'])) {
            $new->{"_{$method}"} = $arguments[0];

        } else {
            trigger_error('Uncaught Error: Call to undefined method '.get_class($this).'::'.$method.'()', E_USER_ERROR);
        }

        return $new;
    }

    public static function insert($table, $values, $database, $columns = NULL) {
        Table::initialize_columns();

        $db_driver = self::get_db_driver($database);

        $columns = implode(',', array_map(function ($col) {
            return '"'.$col.'"';
        }, array_keys($values)));

        $counter = 0;
        $placeholders = implode(',', array_map(function ($value) use (&$counter) {
            $counter++;
            return '$'.$counter;
        }, array_values($values)));

        $query = "INSERT INTO {$table} ($columns) VALUES ($placeholders)";
        return $db_driver->get_param_query($query, array_values($values), $columns, TRUE);
    }

    public static function update($table, $values, $id, $database, $columns = NULL) {
        Table::initialize_columns();

        $db_driver = self::get_db_driver($database);
        $query = "UPDATE {$table} SET ";

        for ($i = 0; $i < count($values); $i++) {
            $query .= '"' . array_keys($values)[$i] . '" = $' . ($i+1);
        }

        $query .= " WHERE ".array_keys($id)[0]." = ".array_values($id)[0];
        return $db_driver->get_param_query($query, array_values($values), $columns, TRUE);
    }

    public static function literal() {
        Table::initialize_columns();

        $value = Table::delimiter;
        foreach (func_get_args() as $argument) {
            $value .= ' ' . preg_replace('/^'.preg_quote(Table::delimiter).'/', '', $argument);
        }
        return $value;
    }

    public function get(array $database = NULL, array $columns = NULL) {
        list($query, $parameters) = $this->compile();
        $db_driver = $this->get_db_driver($database);
        return $db_driver->get_param_query($query, $parameters, $columns);
    }

    public function compile() {
        $query = [];
        $parameters = [];

        $flatten = function ($expression, $override = []) use (&$parameters) {

            foreach ($expression['arguments'] as $key => $value) {

                if ($value instanceof Query) {
                    list($subquery, $subparameters) = $value->compile();
                    $parameters = array_merge($parameters, $subparameters);
                    $expression['arguments'][$key] = $subquery;

                } else if ($value instanceof Column) {
                    $expression['arguments'][$key] = $value->name;

                } else if (strpos($value, Table::delimiter) === 0) {
                    $expression['arguments'][$key] = substr($expression['arguments'][$key], strlen(Table::delimiter));

                } else if ($key >= 1 && !in_array($value, $override)) {

                    $parameters []= $value;
                    $expression['arguments'][$key] = '$'.count($parameters);
                }
            }
            return $expression;
        };

        $select_from = function ($query, $this_select, $verb) use (&$flatten) {
            if (count($this_select)) {
                $select = [$verb];

                foreach ($this_select as $expression) {
                    $expression = $flatten($expression, ['*']);
                    $select = array_merge($select, $expression['method'] === 'count' ? ['COUNT', '('] : [], $expression['arguments'], $expression['method'] === 'count' ? [')'] : [], [',']);
                }
                array_pop($select);
                $query = array_merge($query, $select);
            }
            return $query;
        };

        $join = function($query, $this_join) use (&$flatten) {
            foreach ($this_join as $expression) {
                $table =& $expression['arguments'][0];

                if (stripos($table, 'Models\\') === 0) {
                    $table = Table::delimiter . $table::path();
                }

                $expression = $flatten($expression);
                $on = array_slice($expression['arguments'], 1);

                $query = array_merge($query, [strtoupper(str_replace('_', ' ', $expression['method']))], ['(', $table, ')'], ['ON', '('], $on, [')']);
            }
            return $query;
        };

        $where_having = function($query, $this_where, $verb) use (&$flatten) {
            if (count($this_where)) $query []= $verb.' TRUE';
            foreach ($this_where as $expression) {
                $expression = $flatten($expression);
                $query = array_merge($query, [stripos($expression['method'], '_or') !== FALSE ? 'OR' : 'AND'], ['('], $expression['arguments'], [')']);
            }
            return $query;
        };

        $simple = function ($query, $expressions) use (&$flatten) {
            $add_commas = function($array) {
                $result = [];
                foreach ($array as $value) {
                    $result []= $value;
                    $result []= ',';
                }
                array_pop($result);
                return $result;
            };

            foreach ($expressions as $expression) {
                $expression = $flatten($expression);

                if ($expression['method'] === 'call') {
                    $query = array_merge($query, [strtoupper($expression['arguments'][0])], ['('], $add_commas(array_slice($expression['arguments'], 1)), [')']);

                } else if ($expression['method'] === 'cast') {
                    $query = array_merge($query, [strtoupper($expression['method'])], ['('], [$expression['arguments'][0], 'AS', $expression['arguments'][1]], [')']);

                } else if ($expression['method'] === 'as') {
                    $query = array_merge($query, [$expression['arguments'][0], 'AS', $expression['arguments'][1]]);

                } else {
                    $query = array_merge($query, [strtoupper($expression['method'])], ['('], $expression['arguments'], [')']);
                }
            }
            return $query;
        };

        $query = $simple($query, $this->_global);
        $query = $select_from($query, $this->_select, 'SELECT');
        $query = $select_from($query, $this->_from, 'FROM');
        $query = $join($query, $this->_join);
        $query = $where_having($query, $this->_where, 'WHERE');
        $query = $select_from($query, $this->_group_by, 'GROUP BY');
        $query = $where_having($query, $this->_having, 'HAVING');
        $query = $select_from($query, $this->_order_by, 'ORDER BY');
        if (isset($this->_limit))  $query []= 'LIMIT '  . $this->_limit;
        if (isset($this->_offset)) $query []= 'OFFSET ' . $this->_offset;

        return [implode(' ', $query), $parameters];
    }

    protected static function get_db_driver($database) {
        if (empty($database)) {
            throw new DatabaseUndefinedException('Database object not defined');
        }

        $db_class = 'MiniORM\\Database\\'.(isset($database['vendor']) ? strtoupper($database['vendor']) : 'PSQL');
        return new $db_class($database);
    }
}

class DatabaseUndefinedException extends \Exception {

}
