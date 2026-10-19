<?php

// Unit tests that need no database: php tests/unit.php

require __DIR__.'/bootstrap.php';

use VitoDbManager\Sql\Splitter;

$M = Splitter::MYSQL;
$P = Splitter::PGSQL;

$sqls = static fn (string $sql, string $d): array => array_column(Splitter::split($sql, $d), 'sql');
$lines = static fn (string $sql, string $d): array => array_column(Splitter::split($sql, $d), 'line');

// statements and lines
T::eq('two statements', ['SELECT 1', 'SELECT 2'], $sqls("SELECT 1; SELECT 2;", $M));
T::eq('trailing statement without ;', ['SELECT 1', 'SELECT 2'], $sqls("SELECT 1;\nSELECT 2", $M));
T::eq('lines', [1, 3], $lines("SELECT 1;\n\nSELECT 2", $M));
T::eq('line of a statement starting with a comment', [1], $lines("-- note\nSELECT 1", $M));
T::eq('comment-only script', [], $sqls("-- only\n/* a comment */\n", $M));
T::eq('; inside strings', ["SELECT ';', \";\", `;`"], $sqls("SELECT ';', \";\", `;`;", $M));
T::eq('escaped quote (mysql)', ["SELECT 'a\\'; b'"], $sqls("SELECT 'a\\'; b';", $M));
T::eq('doubled quote', ["SELECT 'a''; b'"], $sqls("SELECT 'a''; b';", $M));
T::eq('# comment (mysql)', ['SELECT 1 # ;', 'SELECT 2'], $sqls("SELECT 1 # ;\n;SELECT 2", $M));
T::eq('-- needs a space in mysql', ['SELECT 1--1', 'SELECT 2'], $sqls("SELECT 1--1;SELECT 2", $M));
T::eq('-- needs no space in pg', ['SELECT 1--1;SELECT 2'], $sqls("SELECT 1--1;SELECT 2", $P));
T::eq('/*! is code (mysql)', ['/*!40101 SET NAMES utf8 */', 'SELECT 1'], $sqls("/*!40101 SET NAMES utf8 */;\nSELECT 1;", $M));
T::eq('DELIMITER', ["CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END", 'CALL p()'],
    $sqls("DELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END\$\$\nDELIMITER ;\nCALL p();", $M));
T::eq('DELIMITER // lines', [2, 3], $lines("DELIMITER //\nSELECT 1//\nSELECT 2//", $M));
T::eq('pg dollar quotes', ['DO $$ BEGIN RAISE NOTICE \'x;\'; END $$', 'SELECT 1'], $sqls("DO $$ BEGIN RAISE NOTICE 'x;'; END $$; SELECT 1;", $P));
T::eq('pg tagged dollar quotes', ['SELECT $fn$ a; $$ b $fn$'], $sqls('SELECT $fn$ a; $$ b $fn$;', $P));
T::eq('pg $1 is not a quote', ['SELECT $1', 'SELECT 2'], $sqls('SELECT $1; SELECT 2', $P));
T::eq('pg nested comments', ['SELECT /* a /* b; */ c; */ 1'], $sqls('SELECT /* a /* b; */ c; */ 1;', $P));
T::eq("pg standard strings don't escape", ["SELECT 'a\\'", 'SELECT 2'], $sqls("SELECT 'a\\'; SELECT 2", $P));
T::eq("pg E'' escapes", ["SELECT E'a\\'; b'"], $sqls("SELECT E'a\\'; b';", $P));

// refused input
T::throws('backslash command (mysql)', 'input', fn () => Splitter::split("SELECT 1 \\G", $M));
T::throws('backslash command (pg)', 'input', fn () => Splitter::split("SELECT 1;\n\\! id", $P));
T::throws('\\copy', 'input', fn () => Splitter::split("\\copy t from '/etc/passwd'", $P));
T::throws('unterminated string', 'input', fn () => Splitter::split("SELECT 'abc", $M));
T::throws('unterminated comment', 'input', fn () => Splitter::split('SELECT 1 /* x', $M));
T::throws('unterminated dollar quote', 'input', fn () => Splitter::split('SELECT $$ x', $P));
T::throws('backslash in a delimiter', 'input', fn () => Splitter::split("DELIMITER \\\\\nSELECT 1", $M));
T::ok('backslash inside a string is fine', count(Splitter::split("SELECT 'C:\\\\dir'", $M)) === 1);
T::ok('backslash inside a comment is fine', count(Splitter::split("SELECT 1 -- \\! id\n", $M)) === 1);

// read-only detection
foreach (['SELECT 1', 'select * from t where a = 1', 'WITH x AS (SELECT 1) SELECT * FROM x', 'SHOW TABLES', 'EXPLAIN SELECT 1',
    'DESCRIBE t', 'SELECT REPLACE(a, "x", "y") FROM t', "SELECT INSERT('abc', 1, 1, 'z')", 'SELECT * FROM t FOR UPDATE',
    'SELECT CAST(a AS CHAR CHARACTER SET utf8mb4) FROM t', 'TABLE t', 'VALUES (1), (2)', "SELECT 'delete from t'",
    'SHOW VARIABLES LIKE "char%"', 'SELECT update_count FROM t'] as $s) {
    T::ok("read: {$s}", Splitter::isRead($s, $M));
}
foreach (['DELETE FROM t', 'UPDATE t SET a = 1', 'INSERT INTO t VALUES (1)', 'WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x',
    'SELECT * INTO new_t FROM t', 'SELECT a INTO @v FROM t', "SELECT * FROM t INTO OUTFILE '/tmp/x'",
    'SET @a = 1', 'CALL p()', 'DROP TABLE t', 'CREATE TABLE t (a int)', 'TRUNCATE t', 'SELECT 1; DELETE FROM t',
    'EXPLAIN ANALYZE DELETE FROM t', 'LOCK TABLES t WRITE', 'GRANT ALL ON t TO u', 'DO 1'] as $s) {
    T::ok("not read: {$s}", ! Splitter::isRead($s, $M));
}
T::ok('SELECT INTO in a subquery is not top level', Splitter::isRead('SELECT (SELECT 1) AS x', $P));
T::ok('limitable SELECT', Splitter::isLimitable('SELECT * FROM t', $P));
T::ok('SHOW is not limitable', ! Splitter::isLimitable('SHOW search_path', $P));

// expressions for DDL
T::ok('expression', Splitter::isExpression("concat(a, ' ', b)", $M));
T::ok('expression with parens', Splitter::isExpression('(a + 1) * (b - 2)', $P));
T::ok('not: closes the clause', ! Splitter::isExpression('1) , ADD COLUMN x int, CHECK (1', $M));
T::ok('not: unbalanced', ! Splitter::isExpression('(a + 1', $M));
T::ok('not: statement delimiter', ! Splitter::isExpression('1; DROP TABLE t', $M));
T::ok('not: line comment', ! Splitter::isExpression('1 -- x', $M));
T::ok('not: hash comment', ! Splitter::isExpression('1 # x', $M));
T::ok('not: block comment', ! Splitter::isExpression('1 /* x */', $P));
T::ok('parens inside strings do not count', Splitter::isExpression("a = ')'", $M));

exit(T::done('unit'));
