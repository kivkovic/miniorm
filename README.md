# miniorm

A tiny ORM + Query Builder in PHP

## Example

### QueryBuilder

```php
$list = MiniORM\Query
	::select('*')
	->from(A::class)
	->where(MiniORM\Query::cast(A::$columnA, 'text'), '!=', B::$columnB)
	->where(A::$columnA, '!=', B::$columnB)
	->where_or(A::$columnA, '!=', 12625)
	->order_by(A::$id, 'ASC')
	->count('*')
	->join(B::class, B::$id, '=', A::$id)
	->where_and(
		MiniORM\Query::exists(
			MiniORM\Query::select(MiniORM\Query::as(MiniORM\Query::call('func1', A::$columnA, B::$columnB), 'xy'))
			->where('xy', '!=', 3)
			->limit(100)
		)
	)
	->limit(100)
	->offset(10)
	->compile();

var_dump($list);
// array(2) { // query
//   [0]=>
//   string(519) "SELECT * , COUNT ( * ) FROM "custom"."table_a" JOIN ( B ) ON ( "public"."table_b"."id" = "custom"."table_a"."id" ) WHERE TRUE AND ( CAST ( "custom"."table_a"."columnA" AS text ) != "public"."table_b"."columnB" ) AND ( "custom"."table_a"."columnA" != "public"."table_b"."columnB" ) OR ( "custom"."table_a"."columnA" != $1 ) AND ( EXISTS ( SELECT FUNC1 ( "custom"."table_a"."columnA" , "public"."table_b"."columnB" ) AS xy WHERE TRUE AND ( xy != $1 ) LIMIT 100 ) ) ORDER BY "custom"."table_a"."id" ASC LIMIT 100 OFFSET 10"
//   [1]=>
//   array(2) { // parameters
//     [0]=>
//     int(12625)
//     [1]=>
//     int(3)
//   }
// }
```

### ORM

```php
MiniORM\Table::set_database([
	'vendor' => 'psql',
	'host' => 'localhost',
	'port' => 5432,
	'dbname' => 'test',
	'user' => 'postgres',
	'password' => 'postgres'
]);

class X extends MiniORM\Table {

	public static $id = [
		'type' => 'integer'
	];
}

class A extends X {

	const table = 'table_a';
	const schema = 'custom';

	const relations = [
		'Models\B' => ['type' => 'one_to_many']
	];

	public static $id = [
		'primary_key' => TRUE,
		'type' => 'serial',
		'read_only' => TRUE,
	];

	public static $columnA = [
		'type' => 'integer'
	];
}

class B extends X {

	const table = 'table_b';

	const relations = [
		'Models\A' => ['type' => 'many_to_one']
	];

	public static $id;

	public static $columnB = [
		'type' => 'text'
	];
}
$list = A::load(
	MiniORM\Query::select('*')
	->from(A::class)
	->where(A::$columnA, '<', 500)
);

$a = $list[0];
$a->columnA = 1;
$a = $a->save(); // performs update, returns updated Table object

$b = new A();
$b->columnA = 55;
$b = $b->save(); // performs insert, returns inserted Table object

try {
	$a->id = 5; // throws MiniORM\ReadOnlyPropertyException
} catch (Exception $e) {
	echo "Caught MiniORM\ReadOnlyPropertyException\n";
}

try {
	$a->columnUndefined = 5; // throws MiniORM\UndefinedPropertyException
} catch (Exception $e) {
	echo "Caught MiniORM\UndefinedPropertyException\n";
}
```

