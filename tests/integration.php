<?php

/*
 * End-to-end tests against a real server, through the same client the plugin uses on a Vito server.
 *
 *   php tests/integration.php <mysql|mariadb|postgresql> "<client command>" <database> [<exec prefix>]
 *
 * e.g. php tests/integration.php mariadb "docker exec -i t-maria mariadb" vdbm "docker exec t-maria"
 * The exec prefix runs shell commands where the client runs (to check no file was created by an injection).
 * The database should be empty and disposable: the tests create and drop tables in it.
 */

require __DIR__.'/bootstrap.php';

use VitoDbManager\Engines\Engine;
use VitoDbManager\Support\ArrayStore;
use VitoDbManager\Support\DbmException;
use VitoDbManager\Transport\ProcessTransport;

[$_, $flavor, $client, $database] = $argv + [null, null, null, null];
$exec = $argv[4] ?? '';
if ($flavor === null || $client === null || $database === null) {
    fwrite(STDERR, "usage: php tests/integration.php <flavor> <client> <database> [<exec prefix>]\n");
    exit(2);
}

$store = new ArrayStore;
$db = Engine::make($flavor, new ProcessTransport, $database, $store, $client);
$pg = $flavor === 'postgresql';
$maria = $flavor === 'mariadb';
$h = static fn (string $op, array $args = []) => $db->handle($op, $args);
$t = static fn (string $name) => $pg ? ['schema' => 'public', 'name' => $name] : $name;

$started = microtime(true);
$ov = $h('overview');
$version = (string) $ov['version'];
fwrite(STDOUT, "{$flavor} {$version}\n");
T::ok('overview has a version', $version !== '');
T::ok('overview database name', $ov['database']['name'] === $database, $ov['database']);

// clean slate
foreach ($ov['tables'] as $tb) {
    if (str_starts_with($tb['name'], 'vdbm_') || str_starts_with($tb['name'], 'جدول')) {
        $h('ddl', ['action' => 'drop_table', 'table' => $pg ? ['schema' => $tb['schema'], 'name' => $tb['name']] : $tb['name'], 'cascade' => true]);
    }
}
if ($pg) {
    try {
        $h('ddl', ['action' => 'drop_schema', 'name' => 'vdbm_s', 'cascade' => true]);
    } catch (DbmException) {
    }
}

// ── create a table from the UI's spec ───────────────────────────────────────────────────
$columns = $pg ? [
    ['name' => 'id', 'type' => 'bigint', 'identity' => 'by_default'],
    ['name' => 'name', 'type' => 'varchar(200)', 'nullable' => false],
    ['name' => 'bio', 'type' => 'text', 'nullable' => true],
    ['name' => 'data', 'type' => 'jsonb', 'nullable' => true],
    ['name' => 'flag', 'type' => 'boolean', 'nullable' => false, 'default' => ['kind' => 'literal', 'value' => 'false']],
    ['name' => 'amount', 'type' => 'numeric(10,2)', 'nullable' => true],
    ['name' => 'created', 'type' => 'timestamptz', 'nullable' => false, 'default' => ['kind' => 'expr', 'value' => 'now()']],
    ['name' => 'blob', 'type' => 'bytea', 'nullable' => true],
    ['name' => 'tags', 'type' => 'text[]', 'nullable' => true],
    ['name' => 'uid', 'type' => 'uuid', 'nullable' => true, 'default' => ['kind' => 'expr', 'value' => 'gen_random_uuid()']],
    ['name' => 'note', 'type' => 'text', 'nullable' => true, 'comment' => "It's a note"],
] : [
    ['name' => 'id', 'type' => 'bigint unsigned', 'auto_increment' => true],
    ['name' => 'name', 'type' => 'varchar(200)', 'nullable' => false],
    ['name' => 'bio', 'type' => 'text', 'nullable' => true],
    ['name' => 'data', 'type' => 'json', 'nullable' => true],
    ['name' => 'flag', 'type' => 'tinyint(1)', 'nullable' => false, 'default' => ['kind' => 'literal', 'value' => '0']],
    ['name' => 'amount', 'type' => 'decimal(10,2)', 'nullable' => true],
    [
        'name' => 'created',
        'type' => 'datetime(3)',
        'nullable' => false,
        'default' => ['kind' => 'expr', 'value' => 'CURRENT_TIMESTAMP(3)'],
        'on_update' => true,
    ],
    ['name' => 'blob', 'type' => 'varbinary(255)', 'nullable' => true],
    [
        'name' => 'status',
        'type' => 'enum',
        'options' => ['new', "it's", 'done'],
        'nullable' => false,
        'default' => ['kind' => 'literal', 'value' => 'new'],
    ],
    ['name' => 'bits', 'type' => 'bit(8)', 'nullable' => true],
    ['name' => 'note', 'type' => 'text', 'nullable' => true, 'comment' => "It's a note", 'default' => ['kind' => 'literal', 'value' => 'none yet']],
];
$create = ['action' => 'create_table', 'table' => $t('vdbm_t1'), 'columns' => $columns, 'primary' => ['id'], 'comment' => 'Test table ✓'];
$dry = $h('ddl', $create + ['dry' => true]);
T::ok('create table: dry run returns SQL', str_contains($dry['sql'][0], 'CREATE TABLE'), $dry);
$h('ddl', $create);
$meta = $db->meta($pg ? $t('vdbm_t1') : ['schema' => null, 'name' => 'vdbm_t1'], true);
T::eq('create table: key', ['pk', ['id']], [$meta['key']['mode'], $meta['key']['cols']]);
$by = array_column($meta['columns'], null, 'name');
T::eq('column categories', $pg
    ? ['int', 'str', 'text', 'json', 'bool', 'num', 'datetime', 'bin', 'array', 'str', 'text']
    : ['int', 'str', 'text', 'json', 'bool', 'num', 'datetime', 'bin', 'enum', 'bit', 'text'],
    array_column($meta['columns'], 'cat'));
T::eq('default kinds', $pg
    ? ['auto', 'none', 'null', 'null', 'literal', 'null', 'expr', 'null', 'null', 'expr', 'null']
    : ['auto', 'none', 'null', 'null', 'literal', 'null', 'expr', 'null', 'literal', 'null', 'literal'],
    array_map(static fn ($c) => $c['default']['kind'], $meta['columns']));
T::eq('comment kept', "It's a note", $by['note']['comment']);
if (! $pg) {
    T::eq('enum options', ['new', "it's", 'done'], $by['status']['options']);
    T::eq('on update', 'CURRENT_TIMESTAMP(3)', $by['created']['on_update']);
    T::eq('literal default of a text column', 'none yet', $by['note']['default']['value']);
    T::eq('numeric default reads back as a literal', ['literal', '0'], [$by['flag']['default']['kind'], $by['flag']['default']['value']]);
    $preview = $h('ddl', [
        'action' => 'add_column',
        'table' => $t('vdbm_t1'),
        'column' => ['name' => 'n', 'type' => 'decimal(6,2)', 'nullable' => false, 'default' => ['kind' => 'literal', 'value' => '-1.50']],
        'dry' => true,
    ]);
    T::ok('numeric default is written unquoted', str_contains(implode(';', $preview['sql']), 'DEFAULT -1.50'));
}

// ── tricky values round-trip ───────────────────────────────────────────────────────────
$tricky = "O'Reilly \\ back\\slash \"q\" `bt` ; -- c /* x */ \$\$ \n line2 \r\n crlf \t tab 🙂 عربي \x01 ctl";
$big = str_repeat('ابجد هوز ', 3000);
$rows = [
    ['name' => $tricky, 'bio' => 'NULL', 'data' => '{"a": [1, 2, "x\"y"], "ar": "عربي"}', 'amount' => '12.50', 'blob' => ['x' => '00ff10007f']],
    ['name' => 'plain', 'bio' => null, 'data' => null, 'amount' => null, 'blob' => null],
    ['name' => '', 'bio' => '', 'data' => '[]', 'amount' => '0', 'blob' => ['x' => '']],
    ['name' => 'big', 'bio' => $big, 'data' => null, 'amount' => '-1.25', 'blob' => null],
];
$ids = [];
foreach ($rows as $k => $r) {
    $res = $h('insert', ['table' => $t('vdbm_t1'), 'values' => $r]);
    T::eq("insert {$k}: affected", 1, $res['affected']);
    $ids[] = $pg ? null : $res['insert_id'];
}
if (! $pg) {
    T::eq('insert ids', ['1', '2', '3', '4'], $ids);
}
$res = $h('insert', [
    'table' => $t('vdbm_t1'),
    'values' => ['name' => 'fn', 'note' => ['default' => true], 'created' => ['expr' => $pg ? 'now()' : 'NOW()']],
]);
T::eq('insert with DEFAULT and a function', 1, $res['affected']);
T::throws(
    'insert: unknown function refused',
    'input',
    fn () => $h('insert', ['table' => $t('vdbm_t1'), 'values' => ['name' => ['expr' => 'sleep(5)']]]),
);
if ($pg) {
    T::throws('pg: NUL in text refused', 'input', fn () => $h('insert', ['table' => $t('vdbm_t1'), 'values' => ['name' => "a\0b"]]));
} else {
    $res = $h('insert', ['table' => $t('vdbm_t1'), 'values' => ['name' => "nul\0inside", 'bits' => '5']]);
    T::eq('mysql: NUL in text kept', 1, $res['affected']);
}

$page = $h('rows', ['table' => $t('vdbm_t1'), 'sort' => [['id', 'asc']], 'per' => 50]);
T::eq('rows: total', $pg ? 5 : 6, $page['total']);
T::eq('rows: approx', false, $page['approx']);
$col = array_flip(array_column($page['columns'], 'name'));
$cell = static fn (int $row, string $name) => $page['rows'][$row][$col[$name]];
T::eq('tricky text round-trips', $tricky, $cell(0, 'name'));
T::eq("'NULL' text is not NULL", 'NULL', $cell(0, 'bio'));
T::eq('NULL is null', null, $cell(1, 'bio'));
T::eq('empty string is empty', '', $cell(2, 'name'));
T::eq('binary round-trips', ['b' => base64_encode(hex2bin('00ff10007f')), 'n' => 5], $cell(0, 'blob'));
T::eq('empty binary', ['b' => '', 'n' => 0], $cell(2, 'blob'));
T::ok('json kept', str_contains((string) $cell(0, 'data'), 'عربي'), $cell(0, 'data'));
T::eq('decimal', '12.50', $cell(0, 'amount'));
$bigCell = $cell(3, 'bio');
T::ok(
    'long text truncated in the grid',
    is_array($bigCell) && $bigCell['n'] === mb_strlen($big) && mb_strlen($bigCell['t']) === 300,
    is_array($bigCell) ? ['n' => $bigCell['n'], 'len' => mb_strlen($bigCell['t'])] : $bigCell,
);
if (! $pg) {
    T::eq('note default applied', 'none yet', $cell(4, 'note'));
}
T::eq('row keys', $pg ? [['1'], ['2'], ['3'], ['4'], ['5']] : [['1'], ['2'], ['3'], ['4'], ['5'], ['6']], $page['keys']);
if (! $pg) {
    T::eq('bit value', '5', $cell(5, 'bits'));
    T::eq('NUL round-trips', "nul\0inside", $cell(5, 'name'));
}
$full = $h('row', ['table' => $t('vdbm_t1'), 'key' => ['4']]);
T::eq('row(): full long value', $big, $full['row'][$col['bio']]);

// filters, search, sort, paging
$count = static fn (array $args) => $h('rows', ['table' => $t('vdbm_t1')] + $args)['total'];
T::eq('filter =', 1, $count(['filters' => [['col' => 'name', 'op' => '=', 'value' => 'plain']]]));
T::eq('filter contains (escapes % _ !)', 0, $count(['filters' => [['col' => 'name', 'op' => 'contains', 'value' => '%_!']]]));
T::eq('filter contains', 1, $count(['filters' => [['col' => 'name', 'op' => 'contains', 'value' => "o'rei"]]]));
T::eq('filter null', $pg ? 3 : 4, $count(['filters' => [['col' => 'data', 'op' => 'null']]]));
T::eq('filter empty', 1, $count(['filters' => [['col' => 'name', 'op' => 'empty']]]));
T::eq('filter in', 2, $count(['filters' => [['col' => 'id', 'op' => 'in', 'value' => '1, 2']]]));
T::eq('filter between', 3, $count(['filters' => [['col' => 'id', 'op' => 'between', 'value' => '2', 'value2' => '4']]]));
T::eq('filter regexp', 1, $count(['filters' => [['col' => 'name', 'op' => 'regexp', 'value' => '^pl.in$']]]));
T::eq('filter on binary', 1, $count(['filters' => [['col' => 'blob', 'op' => '=', 'value' => '00ff10007f']]]));
T::eq('search', 1, $count(['search' => 'عربي']));
T::eq('two filters', 1, $count(['filters' => [['col' => 'id', 'op' => '>', 'value' => '1'], ['col' => 'name', 'op' => 'starts', 'value' => 'pl']]]));
T::throws('filter on unknown column', 'input', fn () => $count(['filters' => [['col' => 'nope', 'op' => '=', 'value' => '1']]]));
$p2 = $h('rows', ['table' => $t('vdbm_t1'), 'per' => 2, 'page' => 2, 'sort' => [['id', 'asc']]]);
T::eq('paging', [['3'], ['4']], $p2['keys']);
$desc = $h('rows', ['table' => $t('vdbm_t1'), 'per' => 1]);
T::eq('default order: newest first', [$pg ? '5' : '6'], $desc['keys'][0]);

// update / delete
$res = $h('update', ['table' => $t('vdbm_t1'), 'key' => ['2'], 'values' => ['name' => 'changed', 'bio' => "multi\nline", 'amount' => null]]);
T::eq('update affected', 1, $res['affected']);
T::eq('update visible', 'changed', $h('row', ['table' => $t('vdbm_t1'), 'key' => ['2']])['row'][$col['name']]);
$res = $h('update', ['table' => $t('vdbm_t1'), 'key' => ['2'], 'values' => ['name' => 'changed']]);
T::eq('update without a change', $pg ? 1 : 0, $res['affected']);
T::throws('update a missing row', 'stale', fn () => $h('update', ['table' => $t('vdbm_t1'), 'key' => ['999'], 'values' => ['name' => 'x']]));
T::throws('row(): missing row', 'stale', fn () => $h('row', ['table' => $t('vdbm_t1'), 'key' => ['999']]));
$res = $h('delete', ['table' => $t('vdbm_t1'), 'keys' => [['3'], ['999']]]);
T::eq('delete', [1, 2], [$res['deleted'], $res['requested']]);

// ── a table without a key ──────────────────────────────────────────────────────────────
$h('sql', ['sql' => $pg
    ? "CREATE TABLE vdbm_nokey (a int, b text, c bytea);\nINSERT INTO vdbm_nokey VALUES (1, 'x', '\\x00ff'), (1, 'x', '\\x00ff'), (2, NULL, NULL);"
    : "CREATE TABLE vdbm_nokey (a int, b text, c blob);\nINSERT INTO vdbm_nokey VALUES (1, 'x', X'00ff'), (1, 'x', X'00ff'), (2, NULL, NULL);"]);
$nk = $h('rows', ['table' => $t('vdbm_nokey'), 'sort' => [['a', 'asc']]]);
T::eq('keyless: mode', $pg ? 'ctid' : 'hash', $nk['key']['mode']);
T::ok(
    'keyless: duplicate rows share a hash (mysql) or not (ctid)',
    $pg ? $nk['keys'][0] !== $nk['keys'][1] : $nk['keys'][0] === $nk['keys'][1],
    $nk['keys'],
);
$res = $h('update', ['table' => $t('vdbm_nokey'), 'key' => $nk['keys'][0], 'values' => ['b' => 'y']]);
T::eq('keyless: update changes one of the duplicates', 1, $res['affected']);
T::eq(
    'keyless: one left unchanged',
    1,
    $h('rows', ['table' => $t('vdbm_nokey'), 'filters' => [['col' => 'b', 'op' => '=', 'value' => 'x']]])['total'],
);
$nk = $h('rows', ['table' => $t('vdbm_nokey'), 'filters' => [['col' => 'a', 'op' => '=', 'value' => '2']]]);
$res = $h('delete', ['table' => $t('vdbm_nokey'), 'keys' => [$nk['keys'][0]]]);
T::eq('keyless: delete the row with NULLs', 1, $res['deleted']);

// ── import ─────────────────────────────────────────────────────────────────────────────
$res = $h('insert_many', ['table' => $t('vdbm_t1'), 'columns' => ['id', 'name'], 'rows' => [['100', 'imp1'], ['101', 'imp2'], ['102', "imp'3"]]]);
T::eq('insert_many', 3, $res['affected']);
$res = $h('insert_many', ['table' => $t('vdbm_t1'), 'columns' => ['id', 'name'], 'rows' => [['100', 'dup'], ['103', 'imp4']], 'mode' => 'ignore']);
T::eq('insert_many ignore', 1, $res['affected']);
$h('insert_many', ['table' => $t('vdbm_t1'), 'columns' => ['id', 'name'], 'rows' => [['100', 'upserted']], 'mode' => 'upsert']);
T::eq('insert_many upsert', 'upserted', $h('row', ['table' => $t('vdbm_t1'), 'key' => ['100']])['row'][$col['name']]);
T::throws(
    'insert_many: plain insert of a duplicate fails',
    'sql',
    fn () => $h('insert_many', ['table' => $t('vdbm_t1'), 'columns' => ['id', 'name'], 'rows' => [['101', 'x']]]),
);
$bulk = [];
for ($i = 0; $i < 2000; $i++) {
    $bulk[] = [(string) (1000 + $i), "bulk {$i} ".str_repeat('x', $i % 50)];
}
$res = $h('insert_many', ['table' => $t('vdbm_t1'), 'columns' => ['id', 'name'], 'rows' => $bulk]);
T::eq('insert_many 2000 rows', 2000, $res['affected']);

// ── SQL console ────────────────────────────────────────────────────────────────────────
$sql = static fn (string $text, array $o = []) => $h('sql', ['sql' => $text] + $o);
$r = $sql("SELECT 1 AS a, 'x' AS b, NULL AS c;\n\nSELECT name FROM vdbm_t1 WHERE id = 1;\n"
    .'UPDATE vdbm_t1 SET flag = '.($pg ? 'true' : '1').' WHERE id < 3');
T::eq('console: three results', 3, $r['ran']);
T::eq('console: first set', [['columns' => ['a', 'b', 'c'], 'rows' => [['1', 'x', null]], 'count' => 1, 'more' => false]], $r['results'][0]['sets']);
T::eq('console: second statement line', 3, $r['results'][1]['line']);
T::eq('console: value', $tricky, $r['results'][1]['sets'][0]['rows'][0][0]);
T::eq('console: affected', 2, $r['results'][2]['affected']);
T::ok('console: timing', is_float($r['results'][0]['ms']) || is_int($r['results'][0]['ms']), $r['results'][0]['ms']);

$r = $sql("SELECT 1;\nSELECT * FROM vdbm_nope;\nSELECT 2;");
T::eq('console: stops at the error', 1, $r['ran']);
T::eq('console: error statement and line', [1, 2], [$r['error']['statement'], $r['error']['line']]);
T::ok('console: error message', str_contains((string) $r['error']['message'], 'vdbm_nope'), $r['error']);
T::eq('console: error code', $pg ? '42P01' : '42S02', $r['error']['sqlstate']);

$r = $sql("INSERT INTO vdbm_t1 (id, name) VALUES (500, 'tx');\nSELECT * FROM vdbm_nope;", ['tx' => true]);
T::eq('console tx: rolled back', [true, 0], [
    $r['rolled_back'],
    $h('rows', ['table' => $t('vdbm_t1'), 'filters' => [['col' => 'id', 'op' => '=', 'value' => '500']]])['total'],
]);
$r = $sql("INSERT INTO vdbm_t1 (id, name) VALUES (501, 'no tx');\nSELECT * FROM vdbm_nope;");
T::eq(
    'console without tx: first insert stays',
    1,
    $h('rows', ['table' => $t('vdbm_t1'), 'filters' => [['col' => 'id', 'op' => '=', 'value' => '501']]])['total'],
);

T::throws('read-only: DELETE refused', 'readonly', fn () => $sql('SELECT 1; DELETE FROM vdbm_t1', ['readonly' => true]));
$r = $sql('SELECT count(*) FROM vdbm_t1', ['readonly' => true]);
T::ok('read-only: SELECT runs', $r['error'] === null && $r['ran'] === 1, $r['error']);
$r = $sql($pg ? 'SELECT nextval(pg_get_serial_sequence(\'vdbm_t1\', \'id\'))' : 'SELECT * FROM vdbm_t1 LIMIT 1 FOR UPDATE', ['readonly' => true]);
T::ok('read-only: session is read only too', ! $pg || $r['error'] !== null, $r['error']);

$r = $sql($pg ? 'SELECT g FROM generate_series(1, 5000) AS g' : 'SELECT id FROM vdbm_t1', ['max_rows' => 100]);
T::eq('console: row limit', [100, true], [$r['results'][0]['sets'][0]['count'], $r['results'][0]['sets'][0]['more']]);
$r = $sql($pg ? 'SELECT g FROM generate_series(1, 3) AS g' : 'SELECT id FROM vdbm_t1 WHERE id < 3', ['max_rows' => 100]);
T::eq('console: under the limit', false, $r['results'][0]['sets'][0]['more']);

$r = $sql($pg ? "DO \$\$ BEGIN RAISE NOTICE 'hello %', 'there'; RAISE WARNING 'careful'; END \$\$;" : "SELECT CAST('12abc' AS SIGNED) AS n");
T::ok('console: warnings', count($r['results'][0]['warnings']) >= 1, $r['results'][0]);
if ($pg) {
    T::eq('console: notice text', 'hello there', $r['results'][0]['warnings'][0]['message'] ?? null);
}

$r = $sql(
    $pg ? 'SELECT pg_sleep(3)' : ($maria ? 'SELECT SLEEP(3)'
        : 'SELECT COUNT(*) FROM information_schema.COLUMNS a, information_schema.COLUMNS b, information_schema.COLUMNS c'),
    ['timeout' => 1],
);
T::ok('console: statement timeout', $r['error'] !== null && $r['error']['kind'] === 'timeout', $r['error'] ?? $r['results']);

if ($pg) {
    $r = $sql("CREATE FUNCTION vdbm_f(x int) RETURNS int LANGUAGE plpgsql AS \$fn\$ BEGIN RETURN x * 2; END; \$fn\$;\n"
        ."SELECT vdbm_f(21) AS v;\nDROP FUNCTION vdbm_f(int);");
} else {
    $r = $sql("DROP PROCEDURE IF EXISTS vdbm_p;\nDELIMITER //\n"
        ."CREATE PROCEDURE vdbm_p(IN x INT) BEGIN SELECT x * 2 AS v; SELECT 'two' AS w; END//\n"
        ."DELIMITER ;\nCALL vdbm_p(21);\nDROP PROCEDURE vdbm_p;");
}
T::ok('console: routines', $r['error'] === null, $r['error']);
$sets = array_merge(...array_map(static fn ($x) => $x['sets'], $r['results']));
T::eq('console: routine result', '42', $sets[0]['rows'][0][0] ?? null);
if (! $pg) {
    T::eq('console: CALL returns several result sets', 'two', $sets[1]['rows'][0][0] ?? null);
}

$r = $sql($pg ? "SELECT decode('00ff', 'hex') AS b" : "SELECT X'00ff41' AS b, 'text' AS t");
T::ok('console: binary shown', $r['error'] === null, $r);
fwrite(STDOUT, '  binary in console: '.json_encode($r['results'][0]['sets'][0]['rows'][0])."\n");

// the client's own commands must never run from the console
$probe = static function (string $file) use ($exec): bool {
    if ($exec === '') {
        return false;
    }
    exec($exec.' test -e '.escapeshellarg($file), $o, $code);

    return $code === 0;
};
$attempts = $pg ? [
    "SELECT 1;\n\\! touch /tmp/vdbm_pwn1" => '/tmp/vdbm_pwn1',
    "SET standard_conforming_strings = off;\nSELECT 'a\\'' \\! touch /tmp/vdbm_pwn2 --';" => '/tmp/vdbm_pwn2',
    "DO \$\$ BEGIN PERFORM set_config('standard_conforming_strings', 'off', false); END \$\$;\n"
        ."SELECT 'a\\'' \\! touch /tmp/vdbm_pwn3 --';" => '/tmp/vdbm_pwn3',
    "SET client_encoding = 'SJIS';\nSELECT E'\xC3\x83\\' \\! touch /tmp/vdbm_pwn4 --';" => '/tmp/vdbm_pwn4',
    "COPY (SELECT 1) TO PROGRAM 'touch /tmp/vdbm_pwn5'" => '/tmp/vdbm_pwn5',
] : [
    "SELECT 1;\nsystem touch /tmp/vdbm_pwn1" => '/tmp/vdbm_pwn1',
    "-- a comment\nsystem touch /tmp/vdbm_pwn2" => '/tmp/vdbm_pwn2',
    "SET sql_mode = CONCAT(@@sql_mode, ',NO_BACKSL', 'ASH_ESCAPES');\nSELECT 'a\\' \\! touch /tmp/vdbm_pwn3 #';" => '/tmp/vdbm_pwn3',
    "SET sql_mode = 'NO_BACKSLASH_ESCAPES'" => '/tmp/vdbm_pwn4',
    "/* x */\n\\! touch /tmp/vdbm_pwn5" => '/tmp/vdbm_pwn5',
    "SELECT 1\n;\nsource /etc/passwd" => '/tmp/vdbm_pwn6',
];
foreach ($attempts as $text => $file) {
    try {
        $sql($text);
    } catch (DbmException) {
    }
    T::ok('console: no client command ran ('.$file.')', ! $probe($file), $text);
}

// ── export ─────────────────────────────────────────────────────────────────────────────
$csv = $h('export', ['table' => $t('vdbm_t1'), 'format' => 'csv', 'sort' => [['id', 'asc']]]);
T::ok('export csv: BOM + header', str_starts_with($csv['data'], "\xEF\xBB\xBFid,name,"), substr($csv['data'], 0, 40));
T::eq('export csv: rows', $h('rows', ['table' => $t('vdbm_t1')])['total'], $csv['rows']);
$json = $h('export', ['table' => $t('vdbm_t1'), 'format' => 'json', 'sort' => [['id', 'asc']], 'limit' => 3]);
$decoded = json_decode($json['data'], true);
T::eq('export json: tricky value', $tricky, $decoded[0]['name'] ?? null);
T::eq('export json: binary', ['$hex' => '00ff10007f'], $decoded[0]['blob'] ?? null);
T::eq('export json: number', 1, $decoded[0]['id'] ?? null);
$dump = $h('export', ['table' => $t('vdbm_t1'), 'format' => 'sql', 'structure' => true]);
$restore = str_replace(
    [$pg ? '"public"."vdbm_t1"' : '`vdbm_t1`', 'vdbm_t1_pkey'],
    [$pg ? '"public"."vdbm_t1_copy"' : '`vdbm_t1_copy`', 'vdbm_t1_copy_pkey'],
    $dump['data'],
);
if ($pg) {
    $restore = preg_replace('/^COMMENT ON .*$/m', '', $restore);
}
$r = $sql($restore);
T::ok('export sql: restores', $r['error'] === null, $r['error']);
$copy = $h('rows', ['table' => $t('vdbm_t1_copy'), 'sort' => [['id', 'asc']], 'per' => 5]);
T::eq('export sql: same row count', $csv['rows'], $copy['total']);
T::eq('export sql: tricky value survives', $tricky, $copy['rows'][0][0 + 1]);
T::eq(
    'export sql: binary survives',
    ['b' => base64_encode(hex2bin('00ff10007f')), 'n' => 5],
    $copy['rows'][0][array_search('blob', array_column($copy['columns'], 'name'), true)],
);

// ── structure and changes ──────────────────────────────────────────────────────────────
$h('ddl', ['action' => 'create_table', 'table' => $t('vdbm_t2'), 'columns' => [
    ['name' => 'id', 'type' => 'int', 'nullable' => false],
    ['name' => 't1_id', 'type' => $pg ? 'bigint' : 'bigint unsigned', 'nullable' => true],
    ['name' => 'qty', 'type' => 'int', 'nullable' => false, 'default' => ['kind' => 'literal', 'value' => '1']],
], 'primary' => ['id']]);
$h('ddl', [
    'action' => 'add_fk',
    'table' => $t('vdbm_t2'),
    'name' => 'vdbm_fk',
    'columns' => ['t1_id'],
    'ref_table' => $t('vdbm_t1'),
    'ref_columns' => ['id'],
    'on_delete' => 'set null',
]);
$h('ddl', ['action' => 'add_index', 'table' => $t('vdbm_t2'), 'name' => 'vdbm_ix', 'columns' => [['name' => 'qty', 'order' => 'desc'], 't1_id']]);
$h('ddl', ['action' => 'add_check', 'table' => $t('vdbm_t2'), 'name' => 'vdbm_chk', 'clause' => 'qty > 0']);
$st = $h('structure', ['table' => $t('vdbm_t2')]);
T::eq('structure: fk', ['vdbm_fk', ['t1_id'], 'vdbm_t1', ['id'], 'SET NULL'], [
    $st['foreign_keys'][0]['name'] ?? null,
    $st['foreign_keys'][0]['columns'] ?? null,
    $st['foreign_keys'][0]['ref_table'] ?? null,
    $st['foreign_keys'][0]['ref_columns'] ?? null,
    $st['foreign_keys'][0]['on_delete'] ?? null,
]);
T::ok('structure: index', in_array('vdbm_ix', array_column($st['indexes'], 'name'), true), array_column($st['indexes'], 'name'));
T::ok('structure: check', in_array('vdbm_chk', array_column($st['checks'], 'name'), true), $st['checks']);
T::ok('structure: ddl', str_contains($st['ddl'], 'vdbm_fk'), $st['ddl']);
$st1 = $h('structure', ['table' => $t('vdbm_t1')]);
T::eq('structure: incoming reference', 'vdbm_t2', $st1['references'][0]['table'] ?? null);
T::throws('check enforced', 'sql', fn () => $h('insert', ['table' => $t('vdbm_t2'), 'values' => ['id' => '1', 'qty' => '0']]));
T::throws(
    'ddl: expression that escapes its clause refused',
    'input',
    fn () => $h('ddl', ['action' => 'add_check', 'table' => $t('vdbm_t2'), 'clause' => '1) , ADD COLUMN evil int, CHECK (1']),
);
T::throws(
    'ddl: bad type refused',
    'input',
    fn () => $h('ddl', ['action' => 'add_column', 'table' => $t('vdbm_t2'), 'column' => ['name' => 'x', 'type' => "int; DROP TABLE vdbm_t1"]]),
);

$h(
    'ddl',
    [
        'action' => 'add_column',
        'table' => $t('vdbm_t2'),
        'column' => ['name' => 'label', 'type' => 'varchar(20)', 'nullable' => true, 'default' => ['kind' => 'literal', 'value' => "a'b"]],
    ] + ($pg ? [] : ['after' => 'id']),
);
$m2 = $db->meta($pg ? $t('vdbm_t2') : ['schema' => null, 'name' => 'vdbm_t2'], true);
T::eq('add column', $pg ? ['id', 't1_id', 'qty', 'label'] : ['id', 'label', 't1_id', 'qty'], array_column($m2['columns'], 'name'));
T::eq('add column: default', "a'b", array_column($m2['columns'], null, 'name')['label']['default']['value']);
$h('ddl', [
    'action' => 'modify_column',
    'table' => $t('vdbm_t2'),
    'name' => 'label',
    'column' => [
        'name' => 'title',
        'type' => 'varchar(100)',
        'nullable' => false,
        'default' => ['kind' => 'literal', 'value' => 'untitled'],
        'comment' => 'Title',
    ],
]);
$c2 = array_column($db->meta($pg ? $t('vdbm_t2') : ['schema' => null, 'name' => 'vdbm_t2'], true)['columns'], null, 'name');
T::ok('modify column: renamed', isset($c2['title']) && ! isset($c2['label']), array_keys($c2));
T::eq('modify column: attributes', ['varchar(100)', false, 'untitled', 'Title'], [
    $pg ? str_replace('character varying', 'varchar', $c2['title']['type']) : $c2['title']['type'],
    $c2['title']['nullable'],
    $c2['title']['default']['value'],
    $c2['title']['comment'],
]);
$h('ddl', ['action' => 'drop_index', 'table' => $t('vdbm_t2'), 'name' => 'vdbm_ix']);
$h('ddl', ['action' => 'drop_fk', 'table' => $t('vdbm_t2'), 'name' => 'vdbm_fk']);
$h('ddl', ['action' => 'drop_check', 'table' => $t('vdbm_t2'), 'name' => 'vdbm_chk']);
$h('ddl', ['action' => 'drop_column', 'table' => $t('vdbm_t2'), 'columns' => ['title']]);
$st = $h('structure', ['table' => $t('vdbm_t2')]);
T::eq('drops', [[], [], false], [$st['foreign_keys'], $st['checks'], in_array('vdbm_ix', array_column($st['indexes'], 'name'), true)]);
$h('ddl', ['action' => 'rename_table', 'table' => $t('vdbm_t2'), 'to' => 'vdbm_t3']);
$h('ddl', ['action' => 'duplicate_table', 'table' => $t('vdbm_t1'), 'to' => 'vdbm_t1_dup', 'data' => true]);
T::eq('duplicate with data', $csv['rows'], $h('rows', ['table' => $t('vdbm_t1_dup')])['total']);
$h('ddl', ['action' => 'truncate_table', 'table' => $t('vdbm_t1_dup')]);
T::eq('truncate', 0, $h('rows', ['table' => $t('vdbm_t1_dup')])['total']);
$m = $h('ddl', ['action' => 'maintenance', 'table' => $t('vdbm_t1'), 'task' => 'analyze']);
T::ok('maintenance', isset($m['results']), $m);
if (! $pg) {
    $h('ddl', ['action' => 'table_options', 'table' => 'vdbm_t1', 'comment' => 'new comment', 'auto_increment' => 5000]);
    $info = $h('structure', ['table' => 'vdbm_t1'])['table'];
    T::eq('table options', ['new comment', 5000], [$info['comment'], $info['auto_increment']]);
} else {
    $h('ddl', ['action' => 'table_options', 'table' => $t('vdbm_t1'), 'comment' => 'new comment']);
    T::eq('table comment', 'new comment', $h('structure', ['table' => $t('vdbm_t1')])['table']['comment']);
}

// unusual names
$odd = "جدول 'x' \"y\" `z`";
$h('ddl', ['action' => 'create_table', 'table' => $t($odd), 'columns' => [['name' => "عمود \"1\" `a`", 'type' => 'int', 'nullable' => true]]]);
$h('insert', ['table' => $t($odd), 'values' => ["عمود \"1\" `a`" => '7']]);
$o = $h('rows', ['table' => $t($odd)]);
T::eq('odd names', [['7']], array_map(static fn ($r) => [$r[0]], $o['rows']));
$h('ddl', ['action' => 'drop_table', 'table' => $t($odd)]);

// type-specific
if ($pg) {
    $h('sql', [
        'sql' => "CREATE SCHEMA vdbm_s;\n"
            .'CREATE TABLE vdbm_s.items (id int GENERATED ALWAYS AS IDENTITY PRIMARY KEY, arr int[], j jsonb, ts timestamptz,'
            ." iv interval, ip inet, g text GENERATED ALWAYS AS (upper(j->>'k')) STORED);",
    ]);
    $res = $h('insert', [
        'table' => ['schema' => 'vdbm_s', 'name' => 'items'],
        'values' => ['id' => '77', 'arr' => '{1,2,3}', 'j' => '{"k": "v"}', 'ts' => '2026-01-02 03:04:05+00', 'iv' => '1 day', 'ip' => '10.0.0.1'],
    ]);
    T::eq('pg: identity ALWAYS insert with value (OVERRIDING)', 1, $res['affected']);
    $it = $h('rows', ['table' => ['schema' => 'vdbm_s', 'name' => 'items']]);
    T::eq('pg: types', ['77', '{1,2,3}', '{"k": "v"}', '1 day', '10.0.0.1', 'V'], [
        $it['rows'][0][0],
        $it['rows'][0][1],
        $it['rows'][0][2],
        $it['rows'][0][4],
        $it['rows'][0][5],
        $it['rows'][0][6],
    ]);
    $ov2 = $h('overview');
    T::ok('pg: schemas listed', in_array('vdbm_s', $ov2['schemas'], true), $ov2['schemas']);
    $h('ddl', ['action' => 'drop_schema', 'name' => 'vdbm_s', 'cascade' => true]);
} else {
    $h('sql', ['sql' => 'CREATE TABLE vdbm_geo (id int PRIMARY KEY, p POINT, g GEOMETRY)']);
    $h('insert', ['table' => 'vdbm_geo', 'values' => ['id' => '1', 'p' => 'POINT(1 2)', 'g' => 'LINESTRING(0 0,1 1)']]);
    $g = $h('rows', ['table' => 'vdbm_geo']);
    T::eq('geometry round-trip', ['POINT(1 2)', 'LINESTRING(0 0,1 1)'], [$g['rows'][0][1], $g['rows'][0][2]]);
    $hasVector = $maria ? version_compare(preg_replace('/-.*/', '', $version), '11.7', '>=') : version_compare($version, '9.0', '>=');
    if ($hasVector) {
        $h('sql', ['sql' => 'CREATE TABLE vdbm_vec (id int PRIMARY KEY, v VECTOR(3)'.($maria ? ' NOT NULL' : '').')']);
        $h('insert', ['table' => 'vdbm_vec', 'values' => ['id' => '1', 'v' => '[1,2.5,3]']]);
        $v = $h('rows', ['table' => 'vdbm_vec']);
        T::ok('vector round-trip', is_string($v['rows'][0][1]) && str_contains($v['rows'][0][1], '2.5'), $v['rows'][0]);
    }
}

// ── monitor and kill ───────────────────────────────────────────────────────────────────
$sleep = $pg
    ? ' -X -q -d '.escapeshellarg($database).' -c "SELECT pg_sleep(20)"'
    : ' --database='.escapeshellarg($database).' -e "SELECT SLEEP(20) AS vdbm_sleeper"';
$sleeper = proc_open(['/bin/sh', '-c', $client.$sleep], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
usleep(1_500_000);
$mon = $h('monitor');
T::ok('monitor: own connection marked', in_array(true, array_column($mon['processes'], 'self'), true));
T::ok('monitor: stats', isset($mon['stats']['uptime']) && $mon['stats']['uptime'] > 0, $mon['stats']);
$target = null;
foreach ($mon['processes'] as $p) {
    if (str_contains((string) $p['info'], $pg ? 'pg_sleep(20)' : 'vdbm_sleeper')) {
        $target = $p['id'];
    }
}
T::ok('monitor: sees a running query', $target !== null, array_column($mon['processes'], 'info'));
if ($target !== null) {
    $k = $h('kill', ['id' => (string) $target]);
    T::eq('kill', $target, $k['killed']);
    $t0 = microtime(true);
    $st = proc_get_status($sleeper);
    while ($st['running'] && microtime(true) - $t0 < 5) {
        usleep(100_000);
        $st = proc_get_status($sleeper);
    }
    T::ok('kill: the query stopped', ! $st['running']);
}
foreach ($pp as $p) {
    fclose($p);
}
proc_terminate($sleeper);
proc_close($sleeper);
T::throws('kill: needs an id', 'input', fn () => $h('kill', ['id' => '0']));

// clean up
foreach (['vdbm_t3', 'vdbm_t1_dup', 'vdbm_t1_copy', 'vdbm_nokey', 'vdbm_geo', 'vdbm_vec', 'vdbm_t1'] as $name) {
    try {
        $h('ddl', ['action' => 'drop_table', 'table' => $t($name), 'cascade' => true]);
    } catch (DbmException) {
    }
}
T::eq('clean up', [], array_values(array_filter(array_column($h('overview')['tables'], 'name'), static fn ($n) => str_starts_with($n, 'vdbm_'))));

fwrite(STDOUT, sprintf("  %.1fs\n", microtime(true) - $started));
exit(T::done("{$flavor} {$version}"));
