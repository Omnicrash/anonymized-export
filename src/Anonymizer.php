<?php
namespace Evoweb\AnonymizedExport;

class Anonymizer extends \Arrilot\DataAnonymization\Anonymizer
{
    /**
     * @var \Evoweb\AnonymizedExport\Database\SqlDatabase
     */
    protected $database;

    /**
     * @var int
     */
    protected $batchSize = 10000;

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
     * @var array
     */
    protected $tablesToTruncate = [];

    /**
     * @var array
     */
    protected $tablesToSkip = [];

    /**
     * Write to console when dumping
     * @var bool
     */
    protected $verbose = false;

    /**
     * Compress the dump with gzip
     * @var bool
     */
    protected $compress = true;

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
        if ($this->compress) {
            $fileNameAndPath .= '.gz';
        }

        if (@file_exists($fileNameAndPath)) {
            unlink($fileNameAndPath);
        }

        touch($fileNameAndPath);
        chmod($fileNameAndPath, 0644);
        $fnOpen = ($this->compress ? 'gzopen' : 'fopen');
        $this->exportFile = $fnOpen($fileNameAndPath, 'w');
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
            $fnClose = ($this->compress ? 'gzclose' : 'fclose');
            $fnClose($this->exportFile);
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
     * @param string $table
     *
     * @return void
     */
    public function addTableToTruncate($table)
    {
        if (!isset($this->tablesToTruncate[$table])) {
            $this->tablesToTruncate[] = $table;
        }
    }

    /**
     * @param string $table
     *
     * @return void
     */
    public function addTableToSkip($table)
    {
        if (!isset($this->tablesToSkip[$table])) {
            $this->tablesToSkip[] = $table;
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
     * @param array $tables
     *
     * @return void
     *
     * @throws \Exception
     */
    public function addTablesToTruncate(array $tables)
    {
        if (empty($tables)) {
            throw new \Exception('addTablesToTruncate argument needs to be a non-empty array');
        }

        foreach ($tables as $table) {
            $this->addTableToTruncate($table);
        }
    }

    /**
     * @param array $tables
     *
     * @return void
     *
     * @throws \Exception
     */
    public function addTablesToSkip(array $tables)
    {
        if (empty($tables)) {
            throw new \Exception('addTablesToSkip argument needs to be a non-empty array');
        }

        foreach ($tables as $table) {
            $this->addTableToSkip($table);
        }
    }

    /**
     * @param bool $verbose
     *
     * @return void
     */
    public function setVerbose($verbose)
    {
        $this->verbose = $verbose;
    }

    /**
     * @return bool
     */
    public function getVerbose()
    {
        return $this->verbose;
    }

    /**
     * @param bool $verbose
     *
     * @return void
     */
    public function setCompress($compress)
    {
        $this->compress = $compress;
    }

    /**
     * @return bool
     */
    public function getCompress()
    {
        return $this->compress;
    }

    /**
     * @param string $table
     *
     * @return void
     */
    protected function exportTable($table)
    {
        $truncated = in_array($table, $this->tablesToTruncate);
        $skipped = in_array($table, $this->tablesToSkip);

        if ($this->verbose) {
            echo "Dumping table: ${table}";
            if ($skipped) {
                echo ' [SKIPPED]';
            } else if ($truncated) {
                echo ' [TRUNCATED]';
            }

            echo "\r\n";

        }

        if ($skipped) {
            return;
        } else {
            $this->writeDropTable($table);
            $this->writeCreateTable($table);
        }

        if ($truncated) {
            $this->writeToExportFile('');

            return;
        }

        $currentBatch = 0;

        $hasHeader = false;
        $hasContent = false;
        do {
            if ($this->verbose) {
                $memUsage = memory_get_usage();
                $start = ($currentBatch * $this->batchSize);
                $end = $start + $this->batchSize;
                echo "Current Batch: ${currentBatch} | Records ${start} - ${end} | Memory: ${memUsage}\r\n";
            }

            $tableContent = $this->getTableContent($table, ($currentBatch * $this->batchSize), $this->batchSize);

            $hasContent = ($tableContent->rowCount() > 0);

            if ($hasContent) {
                if ($currentBatch == 0) {
                    $this->writeInsertBegin($table);
                    $hasHeader = true;
                }

                $this->writeTableContent($tableContent, $table);

            }

            $currentBatch++;

            // Memory cleanup
            unset($tableContent);


        } while ($hasContent);

        if ($hasHeader) {
            $this->writeInsertEnd();
        }

        $this->writeToExportFile('');

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
     * @param int $offset
     * @param int $count
     *
     * @return \PDOStatement
     */
    protected function getTableContent($table, $offset = null, $count = null)
    {
        $sql = "SELECT * FROM {$table}";
        if ($count) {
            $sql .= " LIMIT ";
            if ($offset) {
                $sql .= "${offset}, ";
            }
            $sql .= "${count}";
        }

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
        $fnWrite = ($this->compress ? 'gzwrite' : 'fwrite');
        $fnWrite($this->exportFile, $content . chr(10));
    }

    /**
     * @return int
     */
    public function getBatchSize()
    {
        return $this->batchSize;
    }

    /**
     * @param int $batchSize
     */
    public function setBatchSize($batchSize)
    {
        $this->batchSize = $batchSize;
    }

}
