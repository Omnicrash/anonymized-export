<?php
namespace Evoweb\AnonymizedExport;

class Anonymizer extends \Arrilot\DataAnonymization\Anonymizer
{
    /**
     * @var \Evoweb\AnonymizedExport\Database\SqlDatabase
     */
    protected $database;

    /**
     * Perform export with anonymized tables
     */
    public function run()
    {
        $this->openExportFile();
        $tables = $this->prepareTables();
        foreach ($tables as $table) {
            $this->exportTable($table);
        }
    }

    /**
     * Describe a table with a given callback.
     *
     * @param string $name
     * @param callable $callback
     *
     * @return void
     */
    public function table($name, callable $callback)
    {
        $blueprint = new Blueprint($name, $callback);

        $this->blueprints[$name] = $blueprint->build();
    }

    /**
     * @var string
     */
    protected $exportPath = '';

    /**
     * @var resource
     */
    protected $exportFile;

    /**
     * @var array
     */
    protected $tablesToExport = [];

    /**
     * @param string $exportPath
     *
     * @return void
     */
    public function setExportPath($exportPath)
    {
        $this->exportPath = $exportPath;
    }

    /**
     * @return array
     */
    protected function prepareTables()
    {
        if (empty($this->tablesToExport)) {
            $query = $this->database->query('SHOW TABLES;');
            $tables = $query->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $tables = $this->tablesToExport;
        }

        return $tables;
    }

    /**
     * @return void
     */
    protected function openExportFile()
    {
        $exportPath = $this->getExportPath();

        $fileNameAndPath = rtrim($exportPath, '/') . '/dump_' . mktime() . '.sql';
        if (@file_exists($fileNameAndPath)) {
            unlink($fileNameAndPath);
        }

        touch($fileNameAndPath);
        chmod($fileNameAndPath, 0644);
        $this->exportFile = fopen($fileNameAndPath, 'w+');
    }

    /**
     * @return string
     *
     * @throws \Exception
     */
    protected function getExportPath()
    {
        if ($this->exportPath === '') {
            $exportPath = realpath(__DIR__ . '/../') . '/dump/';
            if (!@file_exists($exportPath)) {
                mkdir($exportPath, 0755, true);
            }
        } else {
            $exportPath = $this->exportPath;
            if (!@file_exists(realpath($exportPath))) {
                throw new \Exception('Export dump folder ' . $exportPath . ' does not exists.');
            }
        }

        return $exportPath;
    }

    /**
     * @return void
     */
    protected function closeExportFile()
    {
        if (is_resource($this->exportFile)) {
            fclose($this->exportFile);
        }
    }

    /**
     * @param string $table
     *
     * @return void
     */
    public function addTableToExport($table)
    {
        if (!isset($this->tablesToExport[$table])) {
            $this->tablesToExport[] = $table;
        }
    }

    /**
     * @param array $tables
     *
     * @return void
     *
     * @throws \Exception
     */
    public function addTablesToExport(array $tables)
    {
        if (empty($tables)) {
            throw new \Exception('addTablesToExport argument needs to be a non-empty array');
        }

        foreach ($tables as $table) {
            $this->addTableToExport($table);
        }
    }

    /**
     * @param string $table
     *
     * @return void
     */
    protected function exportTable($table)
    {
        $this->writeDropTable($table);
        $this->writeCreateTable($table);

        $tableContent = $this->getTableContent($table);
        if ($tableContent->rowCount()) {
            $this->writeInsertBegin($table);
            $this->writeTableContent($tableContent, $table);
            $this->writeInsertEnd();
            $this->writeToExportFile('');
        }
    }

    /**
     * @param string $table
     *
     * @return void
     */
    protected function writeDropTable($table)
    {
        $this->writeToExportFile('DROP TABLE IF EXISTS `' . $table . '`;');
    }

    /**
     * @param string $table
     *
     * @return void
     */
    protected function writeCreateTable($table)
    {
        $tableDefinition = $this->database->query('SHOW CREATE TABLE ' . $table);
        $this->writeToExportFile($tableDefinition->fetch()['Create Table'] . ';');
    }

    /**
     * @param string $table
     *
     * @return \PDOStatement
     */
    protected function getTableContent($table)
    {
        $sql = "SELECT * FROM {$table}";

        return $this->database->query($sql);
    }

    /**
     * @param string $table
     *
     * @return void
     */
    protected function writeInsertBegin($table)
    {
        $this->writeToExportFile('LOCK TABLES `' . $table . '` WRITE;');
        $this->writeToExportFile('INSERT INTO `' . $table . '` VALUES ');
    }

    /**
     * @param \PDOStatement $tableContent
     * @param string $table
     *
     * @return void
     */
    protected function writeTableContent($tableContent, $table)
    {
        $blueprint = $this->getBluePrintByTable($table);

        $rowNum = 0;
        $contentCount = $tableContent->rowCount();
        foreach ($tableContent as $row) {
            $rowNum++;
            $this->writeInsertRow($row, $rowNum, $rowNum < $contentCount, $blueprint);
        }
    }

    /**
     * @param string $table
     *
     * @return Blueprint|null
     */
    protected function getBluePrintByTable($table)
    {
        return isset($this->blueprints[$table]) ? $this->blueprints[$table] : null;
    }

    /**
     * @param array $cells
     * @param int $rowNum
     * @param bool $hasNextRow
     * @param Blueprint $blueprint
     *
     * @return void
     */
    protected function writeInsertRow($cells, $rowNum, $hasNextRow, $blueprint)
    {
        $lineEnd = $hasNextRow ? ',' : ';';
        $values = [];

        foreach ($cells as $columnName => $value) {
            if (!is_null($blueprint) && isset($blueprint->columns[$columnName])) {
                $column = $blueprint->columns[$columnName];
                $value = $this->calculateNewValue($column['replace'], $rowNum);
            }

            if (is_string($value)) {
                $values[] = '\'' . $value . '\'';
            } elseif (is_bool($value)) {
                $values[] = $value ? 'TRUE' : 'FALSE';
            } elseif (is_null($value)) {
                $values[] = 'NULL';
            } else {
                $values[] = $value;
            }
        }

        $this->writeToExportFile('(' . implode(',', $values) . ')' . $lineEnd);
    }

    /**
     * @return void
     */
    protected function writeInsertEnd()
    {
        $this->writeToExportFile('UNLOCK TABLES;');
    }

    /**
     * @param string $content
     *
     * @return void
     */
    protected function writeToExportFile($content)
    {
        fwrite($this->exportFile, $content . chr(10));
    }
}
