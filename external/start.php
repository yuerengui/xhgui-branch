<?php
/* Things you may want to tweak in here:
 *  - xhprof_enable() uses a few constants.
 *  - The values passed to rand() determine the the odds of any particular run being profiled.
 *  - The MongoDB collection and such.
 *
 * I use unsafe writes by default, let's not slow down requests any more than I need to. As a result you will
 * indubidubly want to ensure that writes are actually working.
 *
 * The easiest way to get going is to either include this file in your index.php script, or use php.ini's
 * auto_prepend_file directive http://php.net/manual/en/ini.core.php#ini.auto-prepend-file
 */

/* xhprof_enable()
 * See: http://php.net/manual/en/xhprof.constants.php
 *
 *
 * XHPROF_FLAGS_NO_BUILTINS
 *  Omit built in functions from return
 *  This can be useful to simplify the output, but there's some value in seeing that you've called strpos() 2000 times
 *  (disabled on PHP 5.5+ as it causes a segfault)
 *
 * XHPROF_FLAGS_CPU
 *  Include CPU profiling information in output
 *
 * XHPROF_FLAGS_MEMORY (integer)
 *  Include Memory profiling information in output
 *
 *
 * Use bitwise operators to combine, so XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY to profile CPU and Memory
 *
 */

/* uprofiler support
 * The uprofiler extension is a fork of xhprof.  See: https://github.com/FriendsOfPHP/uprofiler
 *
 * The two extensions are very similar, and this script will use the uprofiler extension if it is loaded,
 * or the xhprof extension if not.  At least one of these extensions must be present.
 *
 * The UPROFILER_* constants mirror the XHPROF_* ones exactly, with one additional constant available:
 *
 * UPROFILER_FLAGS_FUNCTION_INFO (integer)
 *  Adds more information about function calls (this information is not currently used by XHGui)
 */

/* Tideways support
 * The tideways extension is a fork of xhprof. See https://github.com/tideways/php-profiler-extension
 *
 * It works on PHP 5.5+ and PHP 7 and improves on the ancient timing algorithms used by XHProf using
 * more modern Linux APIs to collect high performance timing data.
 *
 * The TIDEWAYS_* constants are similar to the ones by XHProf, however you need to disable timeline
 * mode when using XHGui, because it only supports callgraphs and we can save the overhead. Use
 * TIDEWAYS_FLAGS_NO_SPANS to disable timeline mode.
 */
// this file should not - under no circumstances - interfere with any other application
if (!extension_loaded('xhprof') && !extension_loaded('uprofiler') && !extension_loaded('tideways') && !extension_loaded('tideways_xhprof')) {
    error_log('xhgui - either extension xhprof, uprofiler or tideways must be loaded');
    return;
}

// Use the callbacks defined in the configuration file
// to determine whether or not XHgui should enable profiling.
//
// Only load the config class so we don't pollute the host application's
// autoloaders.
$dir = dirname(__DIR__);
require_once $dir . '/src/Xhgui/Config.php';
Xhgui_Config::load($dir . '/config/config.default.php');
if (file_exists($dir . '/config/config.php')) {
    Xhgui_Config::load($dir . '/config/config.php');
}
unset($dir);
if(Xhgui_Config::read('debug'))
{
    ini_set('display_errors',1);
}
$filterPath = Xhgui_Config::read('profiler.filter_path');
if(is_array($filterPath)&&in_array($_SERVER['DOCUMENT_ROOT'],$filterPath)){
    return;
}

if ((!extension_loaded('mongo') && !extension_loaded('mongodb')) && Xhgui_Config::read('save.handler') === 'mongodb') {
    error_log('xhgui - extension mongo not loaded');
    return;
}

if (!Xhgui_Config::shouldRun()) {
    return;
}

if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

$extension = Xhgui_Config::read('extension');
// 停止收集
if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
    uprofiler_disable();
} else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
    tideways_xhprof_disable();
} else if ($extension == 'tideways' && extension_loaded('tideways')) {
    tideways_disable();
} else {
    xhprof_disable();
}

// 重新开始收集
if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
    uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
} else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
    tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_MEMORY_MU | TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU | TIDEWAYS_XHPROF_FLAGS_CPU);
} else if ($extension == 'tideways' && extension_loaded('tideways')) {
    tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY);
    tideways_span_create('sql');
} else if(function_exists('xhprof_enable')){
    if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
    } else {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }
}else{
    throw new Exception("Please check the extension name in config/config.default.php \r\n,you can use the 'php -m' command.", 1);
}

$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$_SERVER['REQUEST_TIME'] = time();