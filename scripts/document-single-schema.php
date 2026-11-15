<?php

$root = dirname(__DIR__);
$credentials = json_decode(file_get_contents($root.'/.runtime/mysql-test-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
echo "Read-only schema documentation: testing; mysql; 127.0.0.1:33078; bshop_testing_single.\n";
$pdo = new PDO('mysql:host=127.0.0.1;port=33078;dbname=bshop_testing_single;charset=utf8mb4', $credentials['user'], $credentials['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$effective = $pdo->query('SELECT DATABASE() AS db, @@port AS port, @@datadir AS data_directory')->fetch();
if ($effective['db'] !== 'bshop_testing_single' || (int) $effective['port'] !== 33078 || strcasecmp(rtrim(str_replace('\\', '/', $effective['data_directory']), '/'), str_replace('\\', '/', realpath($root.'/.runtime/mysql-data'))) !== 0) {
    throw new RuntimeException('Unexpected test server.');
}
$columns = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'bshop_testing_single' ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetchAll();
$foreign = $pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'bshop_testing_single' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION")->fetchAll();
$tables = [];
$relationships = [];
foreach ($columns as $column) {
    if ($column['COLUMN_NAME'] === 'tenant_id') {
        throw new RuntimeException('Obsolete company scope found.');
    } $tables[$column['TABLE_NAME']][] = $column;
}
foreach ($foreign as $relation) {
    $relationships[$relation['TABLE_NAME'].'.'.$relation['CONSTRAINT_NAME']][] = $relation;
}
$tableCount = count($tables);
$foreignCount = count($relationships);
$checkCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'bshop_testing_single' AND CONSTRAINT_TYPE = 'CHECK'")->fetchColumn();
$er = "# ER actual — un negocio y sus sucursales\n\nArquitectura vigente: una instalación para KeylaShop.\n$tableCount tablas incluyendo infraestructura; $foreignCount FK y $checkCount CHECK verificados en MySQL 8.4.13.\nNo hay gestión multiempresa ni columnas tenant_id. Las tablas no implican módulos terminados.\n\n```mermaid\nerDiagram\n";
$dictionary = "# Diccionario actual — negocio único\n\nGenerado desde bshop_testing_single en MySQL 8.4.13, 127.0.0.1:33078.\nPerfil único protegido en base de datos. Los usuarios y sucursales pertenecen a esta instalación.\n\n";
foreach ($tables as $name => $fields) {
    $er .= "    $name {\n";
    $dictionary .= "## $name\n\n| Campo | Tipo | NULL | Clave |\n|---|---|---|---|\n";
    foreach ($fields as $field) {
        $er .= "        {$field['DATA_TYPE']} {$field['COLUMN_NAME']}".($field['COLUMN_KEY'] === 'PRI' ? ' PK' : '')."\n";
        $dictionary .= "| {$field['COLUMN_NAME']} | `{$field['COLUMN_TYPE']}` | {$field['IS_NULLABLE']} | {$field['COLUMN_KEY']} |\n";
    }
    $er .= "    }\n";
    $dictionary .= "\n";
}
foreach ($relationships as $rows) {
    $relation = $rows[0];
    $er .= "    {$relation['REFERENCED_TABLE_NAME']} ||--o{ {$relation['TABLE_NAME']} : {$relation['CONSTRAINT_NAME']}\n";
}
$er .= "```\n\nLas líneas muestran referencias estructurales; nulabilidad y reglas de negocio se detallan en las migraciones.\n";
file_put_contents($root.'/docs/ER_BSHOP.md', $er);
file_put_contents($root.'/docs/DICCIONARIO_BSHOP.md', $dictionary);
file_put_contents($root.'/docs/SCHEMA_BSHOP.json', json_encode(['architecture' => 'single-business', 'columns' => $columns, 'foreign_keys' => $relationships], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Generated '.count($tables).' tables and '.count($relationships)." relationships; current ER/dictionary/schema manifest.\n";
