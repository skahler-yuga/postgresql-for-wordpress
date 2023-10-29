<?php

// Autoload function to automatically require rewriter classes from the "rewriters" folder
spl_autoload_register(function ($className) {
    $filePath = PG4WP_ROOT . '/rewriters/' . $className . '.php';
    if (file_exists($filePath)) {
        require_once $filePath;
    }
});


function createSQLRewriter(string $sql): AbstractSQLRewriter
{
    $sql = trim($sql);
    if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|SHOW TABLES|OPTIMIZE TABLE|SET NAMES|SHOW FULL COLUMNS)\b/i', $sql, $matches)) {
        // Convert to a format suitable for class names (e.g., "SHOW TABLES" becomes "ShowTables")
        $type = str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($matches[1]))));
        $className = $type . 'SQLRewriter';

        if (class_exists($className)) {
            return new $className($sql);
        } else {
            throw new Exception("No class defined to handle SQL type: " . $type);
        }
    }
    throw new Exception("Invalid or unsupported SQL statement.");
}


function pg4wp_rewrite($sql)
{
    // Note:  Can be called from constructor before $wpdb is set
    global $wpdb;

    $initial = $sql;
    $logto = 'queries';
    // The end of the query may be protected against changes
    $end = '';

    $rewriter = createSQLRewriter(trim($sql));
    $sql = $rewriter->rewrite();
    $logto = strtoupper($rewriter->type());
    switch ($rewriter->type()) {
        case 'Update':
            // This will avoid modifications to anything following ' SET '
            list($sql, $end) = explode(' SET ', $sql, 2);
            $end = ' SET ' . $end;
            break;
        case 'Insert':
            // This will avoid modifications to anything following ' VALUES'
            list($sql, $end) = explode(' VALUES', $sql, 2);
            $end = ' VALUES' . $end;

            // When installing, the sequence for table terms has to be updated
            if(defined('WP_INSTALLING') && WP_INSTALLING && false !== strpos($sql, 'INSERT INTO `' . $wpdb->terms . '`')) {
                $end .= ';SELECT setval(\'' . $wpdb->terms . '_seq\', (SELECT MAX(term_id) FROM ' . $wpdb->terms . ')+1);';
            }
            break;
        case 'Insert':
            break;
        default:
    }

    $sql = loadInstallFunctions($sql, $logto);
    $sql = correctMetaValue($sql);
    $sql = handleInterval($sql);
    $sql = cleanAndCapitalize($sql);
    $sql = correctEmptyInStatements($sql);
    $sql = correctQuoting($sql);

    // Put back the end of the query if it was separated
    $sql .= $end;

    // For insert ID catching
    if($logto == 'INSERT') {
        $pattern = '/INSERT INTO (\w+)\s+\([ a-zA-Z_"]+/';
        preg_match($pattern, $sql, $matches);
        $GLOBALS['pg4wp_ins_table'] = $matches[1];
        $match_list = explode(' ', $matches[0]);
        if($GLOBALS['pg4wp_ins_table']) {
            $GLOBALS['pg4wp_ins_field'] = trim($match_list[3], ' ()	');
            if(!$GLOBALS['pg4wp_ins_field']) {
                $GLOBALS['pg4wp_ins_field'] = trim($match_list[4], ' ()	');
            }
        }
        $GLOBALS['pg4wp_last_insert'] = $sql;
    } elseif(isset($GLOBALS['pg4wp_queued_query'])) {
        pg_query($GLOBALS['pg4wp_queued_query']);
        unset($GLOBALS['pg4wp_queued_query']);
    }

    if(PG4WP_DEBUG) {
        if($initial != $sql) {
            error_log('[' . microtime(true) . "] Converting :\n$initial\n---- to ----\n$sql\n---------------------\n", 3, PG4WP_LOG . 'pg4wp_' . $logto . '.log');
        } else {
            error_log('[' . microtime(true) . "] $sql\n---------------------\n", 3, PG4WP_LOG . 'pg4wp_unmodified.log');
        }
    }
    return $sql;
}

/**
 * Load upgrade and install functions as required.
 *
 * @param string $sql SQL query string
 * @param string $logto Logging type
 * @return string Modified SQL query string
 */
function loadInstallFunctions($sql, &$logto)
{
    $begin = strtoupper(substr($sql, 0, 3));
    $search = array('SHO', 'ALT', 'DES', 'CRE', 'DRO');
    if (in_array($begin, $search)) {
        require_once(PG4WP_ROOT . '/driver_pgsql_install.php');
        $sql = pg4wp_installing($sql, $logto);
    }
    return $sql;
}

/**
 * Correct the meta_value field for WP 2.9.1 and add type cast.
 *
 * @param string $sql SQL query string
 * @return string Modified SQL query string
 */
function correctMetaValue($sql)
{
    // WP 2.9.1 uses a comparison where text data is not quoted
    $sql = preg_replace('/AND meta_value = (-?\d+)/', 'AND meta_value = \'$1\'', $sql);
    // Add type cast for meta_value field when it's compared to number
    $sql = preg_replace('/AND meta_value < (\d+)/', 'AND meta_value::bigint < $1', $sql);
    return $sql;
}

/**
 * Handle interval expressions in SQL query.
 *
 * @param string $sql SQL query string
 * @return string Modified SQL query string
 */
function handleInterval($sql)
{
    // Generic "INTERVAL xx YEAR|MONTH|DAY|HOUR|MINUTE|SECOND" handler
    $sql = preg_replace('/INTERVAL[ ]+(\d+)[ ]+(YEAR|MONTH|DAY|HOUR|MINUTE|SECOND)/', "'\$1 \$2'::interval", $sql);
    // DATE_SUB handling
    $sql = preg_replace('/DATE_SUB[ ]*\(([^,]+),([^\)]+)\)/', '($1::timestamp - $2)', $sql);
    return $sql;
}

/**
 * Clean SQL query from illegal characters and handle capitalization.
 *
 * @param string $sql SQL query string
 * @return string Modified SQL query string
 */
function cleanAndCapitalize($sql)
{
    // Remove illegal characters
    $sql = str_replace('`', '', $sql);
    // Field names with CAPITALS need special handling
    if (false !== strpos($sql, 'ID')) {
        $patterns = [
            '/ID([^ ])/' => 'ID $1',
            '/ID$/' => 'ID ',
            '/\(ID/' => '( ID',
            '/,ID/' => ', ID',
            '/[0-9a-zA-Z_]+ID/' => '"$0"',
            '/\.ID/' => '."ID"',
            '/[\s]ID /' => ' "ID" ',
            '/"ID "/' => ' "ID" '
        ];
        foreach ($patterns as $pattern => $replacement) {
            $sql = preg_replace($pattern, $replacement, $sql);
        }
    }
    return $sql;
}

/**
 * Correct empty IN statements in SQL query.
 *
 * @param string $sql SQL query string
 * @return string Modified SQL query string
 */
function correctEmptyInStatements($sql)
{
    $search = ['IN (\'\')', 'IN ( \'\' )', 'IN ()'];
    $replace = 'IN (NULL)';
    $sql = str_replace($search, $replace, $sql);
    return $sql;
}

/**
 * Correct quoting for PostgreSQL 9.1+ compatibility.
 *
 * @param string $sql SQL query string
 * @return string Modified SQL query string
 */
function correctQuoting($sql)
{
    $sql = str_replace("\\'", "''", $sql);
    $sql = str_replace('\"', '"', $sql);
    return $sql;
}
