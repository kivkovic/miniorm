<?php

namespace MiniORM;

class Table {

	public static $___database;

	public $___values = [];
	public $___write = [];

	const delimiter = "\0\0\0*\0\0\0";

	public function __construct($schema = NULL, $table = NULL) {
		self::initialize_columns();
	}

	public static function set_database($database) {
		self::$___database = $database;
	}

	protected static function get_database() {
		return (empty(self::$___database) && get_parent_class() !== FALSE)
			? parent::get_database()
			: self::$___database;
	}

	public static function load($query) {
		$results = [];
		$class = get_called_class();
		$external_types = [];

		foreach ($query->get(self::get_database()) as $row) {
			$object = new $class;
			$columns = $class::columns();

			foreach ($row as $key => $value) {
				if ($value !== NULL) {
					if (isset($columns[$key])) {
						if (preg_match('/(int|serial)/i', $columns[$key]->type)) {
							$value = (integer) $value;
						} else if (preg_match('/(float|double|decimal)/i', $columns[$key]->type)) {
							$value = (float) $value;
						} else if(preg_match('/bool/', $columns[$key]->type)) {
							$value = $value == 't' ? TRUE : FALSE;
						}
					}
				}
				$object->___values[$key] = $value;
			}
			$results []= $object;
		}

		return $results;
	}

	public function save() {
		$result = NULL;
		if (empty($this->___write)) {
			return $this;
		}

		$class = get_called_class();
		foreach ($class::columns() as $key => $column) {
			if(!empty($column->primary_key) && !empty($this->___values[$key])) {
				$result = Query::update(self::path(), $this->___write, [$key => $this->___values[$key]], self::get_database());
				break;
			}
		}

		if ($result === NULL) {
			$result = Query::insert(self::path(), $this->___write, self::get_database());
		}

		$object = new $class;
		foreach ($result[0] as $key => $value) {
			$object->___values[$key] = $value;
		}

		return $object;
	}

	public function __set($name, $value) {
		$columns = $this::columns();
		if (!isset($columns[$name])) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		if (!empty($columns[$name]->read_only)) {
			throw new ReadOnlyPropertyException('Read-only property: '.get_class($this).'::$'.$name);
		}
		$this->___write[$name] = $value;
		$this->___values[$name] = $value;
	}

	public function __get($name) {
		$columns = $this::columns();
		if (!isset($columns[$name])) {
			throw new UndefinedPropertyException('Undefined property: '.get_class($this).'::$'.$name);
		}
		return $this->___values[$name];
	}

	public static function path() {
		return '"' . (defined(get_called_class().'::schema') ? constant(get_called_class().'::schema') : 'public') . '"."' . constant(get_called_class().'::table') . '"';
	}

	public static function initialize_columns() {
		$models = array_filter(get_declared_classes(), function ($class) {
			return defined("{$class}::table") && is_subclass_of($class, self::class);
		});

		foreach ($models as $model) {
			foreach ($model::columns() as $property => $value) {
				if ($value instanceof Column) continue;
				$value['name'] =  $model::path() . '."' . $property . '"';
				$model::$$property = new Column($value);
			}
		}
	}

	public static function columns() {
		$reflection = new \ReflectionClass(get_called_class());
		$static_properties = $reflection->getStaticProperties();
		$columns = [];

		foreach ($static_properties as $property => $value) {
			if (stripos($property, '___') === 0) continue;
			$columns[$property] = $value;
		}

		return $columns;
	}

	public function __toString() {
		return json_encode($this->___values);
	}
}

class UndefinedPropertyException extends \Exception {

}

class ReadOnlyPropertyException extends \Exception {

}
