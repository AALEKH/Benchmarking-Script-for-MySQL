// Script has been taken from: http://blog.ulf-wendel.de/2013/toying-with-peclmysqlnd_memcache-and-mysql-5-6-memcache-innodb/
// with some minor tweaks.

<?php
define("MYSQL_HOST",  /*add mysql host*/);
define("MYSQL_USER",  /*add mysql user */);
define("MYSQL_PWD",  /*add mysql password*/);
define("MYSQL_DB",  /*add database name*/);
define("MYSQL_PORT",  /*add port number*/);
define("MYSQL_MEMC_PORT", /*add memcached port number*/);

// To obtain value of MySQL HOST , MySQL PORT use this : netstat -tln

define("NUM_VALUES", 10000);
define("REPEAT_READS", 10);

/* Wait time e.g. for background commit */
define("REST_TIME_AFTER_LOAD", 30);

/* Make sure the schema matches! */
define("KEY_LEN", 10);
define("VALUE_LEN", 100);

/* match MySQL config to be fair... */
define("WRITE_COMMIT_BATCH_SIZE", 1000);

/* number of parallel fetch worker (processes) */
define("FETCH_WORKER", 2);




function store_fetch_results_in_mysql($run_id, $pid, $results, $num_values = NUM_VALUES, $repeat = REPEAT_READS) {
  $mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PWD, MYSQL_DB, MYSQL_PORT);
  if ($mysqli->errno) {
    printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
    return false;
  }
  if (!$mysqli->query("CREATE TABLE IF NOT EXISTS php_bench(
       run_id INT, pid INT UNSIGNED,
       label VARCHAR(60),
       runtime DECIMAL(10, 6) UNSIGNED, ops INT UNSIGNED)")) {

     printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
     return false;
  }
  foreach ($results as $label => $time) {

     $sql = sprintf("INSERT INTO php_bench(run_id, pid, label, runtime, ops)
              VALUES (%d, %d, '%s', %10.6f, %d)",
             $run_id,
             $pid,
             $mysqli->real_escape_string($label),
             $time,
             ($time > 0) ? ($num_values * $repeat / $time) : 0);
     if (!$mysqli->query($sql)) {
       printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
       return false;
     }
  }
  return true;
}

function generate_pairs($num = NUM_VALUES, $key_len = KEY_LEN, $value_len = VALUE_LEN) {
  $pairs = array();
  $anum = "0123456789ABCDEFGHIJKLMNOPQRSTWXYZabcdefghijklmnopqrstuvwxyz";
  $anum_len = strlen($anum) - 1;

  for ($i = 0; $i < $num; $i++) {
    $key = "";
    for ($j = 0; $j < $key_len; $j++) {
      $key .= substr($anum, mt_rand(0, $anum_len), 1);
    }
    $value = $key . strrev($key) . $key . strrev($key);
    $pairs[] = array($key, $value);
  }

  return $pairs;
}

function load_pairs_memc($memc, $pairs) {
  $inserted = 0;
  foreach ($pairs as $k => $pair) {
    if (false == $memc->add($pair[0], $pair[1])) {
      printf("[%d] Memc error\n", $memc->getResultCode());
      break;
    }
    $inserted++;
  }
  return $inserted;
}

function load_pairs_sql($mysqli, $pairs) {
  $inserted = 0;
  $mysqli->autocommit = false;
  foreach ($pairs as $k => $pair) {
    if (!$mysqli->query(sprintf("INSERT INTO demo_test(c1, c2) VALUES ('%s', '%s')", $pair[0], $pair[1]))) {
      printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
      break;
    }
    $inserted++;
    if ($inserted % WRITE_COMMIT_BATCH_SIZE == 0) {
      $mysqli->commit();
    }
  }
  $mysqli->commit();
  $mysqli->autocommit = true;
  return $inserted;
}

function timer($label = '') {
  static $times = array();
  if (!$label)
    return $times;

  my_timer($label, $times);
  return $times;
}

function my_timer($label, &$times) {
  if (!$label)
    return;

  if (!isset($times[$label])) {
    $times[$label] = microtime(true);
  } else {
    $times[$label] = microtime(true) - $times[$label];
  }
}


function fetch_sql($mysqli, $pairs, $repeat = REPEAT_READS) {
  $fetched = 0;
  for ($i = 0; $i < $repeat; $i++) {
    $fetched += _fetch_sql($mysqli, $pairs);
  }
  return $fetched;
}
function _fetch_sql($mysqli, $pairs) {
  $fetched = 0;
  $num = count($pairs);
  while (count($pairs)) {
    do {
     $idx = mt_rand(0, $num);
    } while (!isset($pairs[$idx]));

    $res = $mysqli->query($sql = "SELECT c2 FROM demo_test WHERE c1='" . $pairs[$idx][0] . "'");
    if (!$res) {
      printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
      break;
    }
    $row = $res->fetch_row();
    $res->free();
    assert($pairs[$idx][1] == $row[0]);
    $fetched++;
    unset($pairs[$idx]);
  }
  return $fetched;
}

function fetch_memc($memc, $pairs, $repeat = REPEAT_READS) {
  $fetched = 0;
  for ($i = 0; $i < $repeat; $i++) {
    $fetched += _fetch_memc($memc, $pairs);
  }
  return $fetched;
}
function _fetch_memc($memc, $pairs, $repeat = 1) {
  $fetched = 0;
  $num = count($pairs);
  while (count($pairs)) {
    do {
      $idx = mt_rand(0, $num);
    } while (!isset($pairs[$idx]));

    if (false == ($value = $memc->get($pairs[$idx][0]))) {
      printf("[%d] Memc error\n", $memc->getResultCode());
      break;
    }
    assert($pairs[$idx][1] == $value);
    $fetched++;
    unset($pairs[$idx]);
  }
  return $fetched;
}


function generate_and_load_pairs($num = NUM_VALUES, $key_len = KEY_LEN, $value_len = VALUE_LEN) {

  $mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PWD, MYSQL_DB, MYSQL_PORT);
  if ($mysqli->errno) {
    printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
    return array();
  }
  $memc = new Memcached();
  if (!$memc->addServer(MYSQL_HOST, MYSQL_MEMC_PORT)) {
    printf("[%d] Memc connect error\n",  $memc->getResultCode());
    return array();
  }

  timer("generate pairs");
  printf("\tGenerating pairs...\n");
  $pairs = generate_pairs($num, $key_len, $value_len);
  timer("generate pairs");


  timer("load pairs using SQL");
  printf("\tLoading %d pairs using SQL...\n", load_pairs_sql($mysqli, $pairs));
  timer("load pairs using SQL");

  $mysqli->query("DELETE from demo_test");

  /* server think and commit time */
  sleep(REST_TIME_AFTER_LOAD);

  timer("load pairs using Memcache");
  printf("\tLoading %d pairs using Memcache...\n", load_pairs_memc($memc, $pairs));
  timer("load pairs using Memcache");

  sleep(REST_TIME_AFTER_LOAD);

  return $pairs;
}


function fetch_and_bench($pairs, $pid, $indent = 1, $repeat = REPEAT_READS) {
 $times = array();

 $mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PWD, MYSQL_DB, MYSQL_PORT);
  if ($mysqli->errno) {
    printf("[%d] %s\n", $mysqli->errno, $mysqli->error);
    return $times;
  }
  $memc = new Memcached();
  if (!$memc->addServer(MYSQL_HOST, MYSQL_MEMC_PORT)) {
    printf("[%d] Memc connect error\n",  $memc->getResultCode());
    return $times;
  }
  $prefix = str_repeat("\t", $indent);

  my_timer("fetch using plain SQL", $times);
  printf("%s[pid = %d] Fetched %d pairs using plain SQL...\n", $prefix, $pid, fetch_sql($mysqli, $pairs, $repeat));
  my_timer("fetch using plain SQL", $times);

  mysqlnd_memcache_set($mysqli, $memc);
  my_timer("fetch using Memcache mapped SQL", $times);
  printf("%s[pid = %d] Fetched %d pairs using Memcache mapped SQL...\n", $prefix, $pid, fetch_sql($mysqli, $pairs, $repeat));
  my_timer("fetch using Memcache mapped SQL", $times);

  my_timer("fetch using Memcache", $times);
  printf("%s[pid = %d] Fetched %d pairs using Memcache...\n", $prefix, $pid, fetch_memc($memc, $pairs, $repeat));
  my_timer("fetch using Memcache", $times);

  return $times;;
}


$run_id = mt_rand(0, 1000);

$pairs = generate_and_load_pairs(NUM_VALUES, KEY_LEN, VALUE_LEN);
$load_times = timer();

$pids = array();
for ($fetch_worker = 1; $fetch_worker <= FETCH_WORKER; $fetch_worker++) {
   switch ($pid = pcntl_fork()) {
      case -1:
         printf("Fork failed!\n");
         break;

      case 0:
         printf("\t\tFetch worker %d (pid = %d) begins...\n", $fetch_worker, getmypid());
         $times = fetch_and_bench($pairs, getmypid(), 2);
         store_fetch_results_in_mysql($run_id, getmypid(), $times, NUM_VALUES, REPEAT_READS);
         printf("\t\tWorker %d (pid = %d) has recorded its results...\n", $fetch_worker, getmypid());
         exit(0);
         break;

      default:
         printf("\t\tParent has created worker [%d] (pid = %d)\n", $fetch_worker, $pid);
         $pids[] = $pid;
         pcntl_waitpid($pid, $status, WNOHANG);
         break;
   }
}

foreach ($pids as $pid) {
  pcntl_waitpid($pid, $status);
}



printf("\n\n");
printf("Key settings\n");
printf("\t%60s: %d\n", "Number of values", NUM_VALUES);
printf("\t%60s: %d\n", "Key length", KEY_LEN);
printf("\t%60s: %d\n", "Value length", VALUE_LEN);
printf("\t%60s: %d\n", "SQL write commit batch size", WRITE_COMMIT_BATCH_SIZE);
printf("\t%60s: %d\n", "Parallel clients (fetch)", FETCH_WORKER);
printf("\t%60s: %d\n", "Run ID used to record fetch times in MySQL", $run_id);

printf("\n\n");
printf("Load times\n");
foreach ($load_times as $label => $time) {
  printf("\t%60s: %.3fs (%d ops)\n", $label, $time, NUM_VALUES / $time);
}

printf("\n");
printf("Fetch times\n");

$mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PWD, MYSQL_DB, MYSQL_PORT);
if ($mysqli->errno) {
 die(sprintf("[%d] %s\n", $mysqli->errno, $mysqli->error));
}
$res = $mysqli->query("SELECT DISTINCT label FROM php_bench WHERE run_id = " . $run_id);
if (!$res)
  die(sprintf("[%d] %s\n", $mysqli->errno, $mysqli->error));

while ($row = $res->fetch_assoc()) {
  $sql = sprintf("SELECT AVG(runtime) as _time, AVG(ops) AS _ops FROM php_bench WHERE label = '%s' GROUP BY run_id HAVING run_id = %d",
    $mysqli->real_escape_string($row['label']),
    $run_id);
  if (!($res2 = $mysqli->query($sql)))
    die(sprintf("[%d] %s\n", $mysqli->errno, $mysqli->error));

  $row2 = $res2->fetch_assoc();
  printf("\t%60s: %.3fs (%d ops)\n", $row['label'], $row2['_time'], $row2['_ops']);
}
$mysqli->query("DELETE FROM php_bench");
printf("\n\n");
printf("\t\tTHE END\n");
