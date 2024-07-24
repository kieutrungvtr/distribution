<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateGenerateModels extends Command
{
    protected $signature = 'migrate:generate-models';

    protected $description = 'Migrate Models Generation';

    public function handle()
    {
        $path = SYS_PATH . '/app/Models/Sql';
        $this->info('Generating Models');
        self::generateModel(DB::connection('mysql'), $path);
        $this->info('Generate Models Completed');
    }

    private static function generateModel($connection, $path)
    {
        self::moveToTmpDir($path);
        self::removePath($path);
        mkdir($path);
        $allTable = $connection->select('SHOW TABLES WHERE tables_in_'.config('database.connections.mysql.database')." NOT IN ('migrations', 'password_resets', 'failed_jobs', 'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients', 'oauth_personal_access_clients', 'oauth_refresh_tokens')");
        foreach ($allTable as $table) {
            foreach ($table as $key => $tableName) {
                $schema = $connection->select("DESCRIBE `$tableName`");
                $idColumn = '';
                $autoIncrement = false;
                $constants = "";
                foreach ($schema as $column) {
                    if ($column->Key == 'PRI') {
                        $idColumn = $column->Field;
                        $autoIncrement = is_int(strpos($column->Extra, 'auto_increment'));
                    }
                    $constKey = 'COL_' . strtoupper($column->Field);
                    $constants .= "/**
     * @var string
     */
    const {$constKey} = '{$column->Field}';

    ";
                }
                $view = view('migrations');
                $view->className = self::snakeToPascal($tableName);

                $fileName = SYS_PATH.'/tmp/'.$view->className.'.php';
                $view->contentDevCode = '';
                $view->contentUseTrait = '';
                $view->contentUsed = '';

                if (file_exists($fileName)) {
                    $contentFromTmpFile = file_get_contents($fileName);
                    $contentCodeFromTmpFile = self::get_string_between($contentFromTmpFile, '#---- Begin custom code -----#', '#---- Ended custom code -----#');
                    $contentUseTraitFromTmpFile = self::get_string_between($contentFromTmpFile, '#---- Begin trait -----#', '#---- Ended trait -----#');
                    $contentUseFromTmpFile = self::get_string_between($contentFromTmpFile, '#---- Begin package usage -----#', '#---- Ended package usage -----#');
                    $view->contentDevCode = trim($contentCodeFromTmpFile) ?? '';
                    $view->contentUseTrait = trim($contentUseTraitFromTmpFile) ?? '';
                    $view->contentUsed = trim($contentUseFromTmpFile) ?? '';
                }

                $view->connection = $connection->getName();
                $view->tableName = $tableName;
                $view->primaryKey = $idColumn;
                $view->incrementing = $autoIncrement ? 'true' : 'false';
                $view->constants = $constants;
                $content = $view->render();
                file_put_contents($path . '/' . $view->className . '.php', $content);
            }
        }
    }

    private static function snakeToPascal($str)
    {
        return str_replace('_', '', ucwords($str, '_'));
    }

    /**
     * Remove folder
     * @param string $path
     */
    private static function removePath($path)
    {
        if (file_exists($path)) {
            if (is_dir($path)) {
                $arrDir = scandir($path);
                foreach ($arrDir as $dir) {
                    if (in_array($dir, array('.', '..'))) {
                        continue;
                    }
                    self::removePath($path . '/' . $dir);
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }

    private static function get_string_between($string, $start, $end)
    {
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) {
            return '';
        }
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    private static function moveToTmpDir($path)
    {
        @mkdir(SYS_PATH.'/tmp');
        if (file_exists($path)) {
            if (is_dir($path)) {
                $arrDir = scandir($path);
                $arrDir = array_filter($arrDir, function ($ele) {
                    return !in_array($ele, ['.', '..','tmp']);
                });
                foreach ($arrDir as $dir) {
                    copy($path.'/'.$dir, SYS_PATH.'/tmp/'.$dir);
                }
            }
        }
    }
}
