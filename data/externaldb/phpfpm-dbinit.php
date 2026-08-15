<?php
// External-DB helper for the php-fpm entrypoint.
// Replaces the entrypoint's mariadb CLI calls, which cannot authenticate
// against MySQL 8.4 (caching_sha2_password) from this image; PHP's mysqlnd can.
// Modes: wait | tzcheck | domains | apikeys | events

$mode = $argv[1] ?? '';

function db($withDb = true) {
  $host = getenv('DBHOST');
  $port = getenv('DBPORT') ?: 3306;
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 10,
  ];
  if (getenv('DBSSL_CA')) {
    $opt[PDO::MYSQL_ATTR_SSL_CA] = getenv('DBSSL_CA');
    $opt[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (getenv('DBSSL_VERIFY') == 'y');
  }
  $dsn = "mysql:host=" . $host . ";port=" . $port . ($withDb ? ";dbname=" . getenv('DBNAME') : "");
  return new PDO($dsn, getenv('DBUSER'), getenv('DBPASS'), $opt);
}

switch ($mode) {
  case 'wait':
    try {
      db()->query('SELECT 1');
      exit(0);
    } catch (Exception $e) {
      fwrite(STDERR, $e->getMessage() . PHP_EOL);
      exit(1);
    }

  case 'tzcheck':
    try {
      $tz = db()->query("SELECT CONVERT_TZ('2019-11-02 23:33:00','Europe/Berlin','UTC')")->fetchColumn();
    } catch (Exception $e) {
      $tz = null;
    }
    if (empty($tz)) {
      echo "WARNING: time zone tables are not loaded in the external database (CONVERT_TZ returned NULL)" . PHP_EOL;
    } else {
      echo "External DB time zone tables OK (CONVERT_TZ -> " . $tz . ")" . PHP_EOL;
    }
    exit(0);

  case 'domains':
    $pdo = db();
    foreach ($pdo->query('SELECT domain FROM domain') as $row) {
      echo $row['domain'] . "\n";
    }
    foreach ($pdo->query('SELECT alias_domain FROM alias_domain') as $row) {
      echo $row['alias_domain'] . "\n";
    }
    exit(0);

  case 'apikeys':
    $allow_from = getenv('API_ALLOW_FROM');
    if ($allow_from == 'invalid' || empty($allow_from)) exit(0);
    $validated = [];
    foreach (explode(',', $allow_from) as $ip) {
      $ip = trim($ip);
      if (preg_match('/^([0-9a-fA-F]{0,4}:){1,7}[0-9a-fA-F]{0,4}(\/([0-9]|[1-9][0-9]|1[0-1][0-9]|12[0-8]))?$/', $ip) ||
          preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/([0-9]|[1-2][0-9]|3[0-2]))?$/', $ip)) {
        $validated[] = $ip;
      }
    }
    if (empty($validated)) exit(0);
    $ips = implode(',', $validated);
    $pdo = db();
    foreach (['rw' => getenv('API_KEY'), 'ro' => getenv('API_KEY_READ_ONLY')] as $access => $key) {
      if ($key == 'invalid' || empty($key)) continue;
      $pdo->prepare("DELETE FROM api WHERE access = ?")->execute([$access]);
      $pdo->prepare("INSERT INTO api (api_key, active, allow_from, access) VALUES (?, '1', ?, ?)")->execute([$key, $ips, $access]);
    }
    exit(0);

  case 'events':
    $pdo = db();
    $events = [
      'clean_spamalias' => "CREATE EVENT clean_spamalias ON SCHEDULE EVERY 1 DAY DO BEGIN
        DELETE FROM spamalias WHERE validity < UNIX_TIMESTAMP() AND permanent = 0;
      END",
      'clean_oauth2' => "CREATE EVENT clean_oauth2 ON SCHEDULE EVERY 1 DAY DO BEGIN
        DELETE FROM oauth_refresh_tokens WHERE expires < NOW();
        DELETE FROM oauth_access_tokens WHERE expires < NOW();
        DELETE FROM oauth_authorization_codes WHERE expires < NOW();
      END",
      'clean_sasl_log' => "CREATE EVENT clean_sasl_log ON SCHEDULE EVERY 1 DAY DO BEGIN
        DELETE sasl_log.* FROM sasl_log
          LEFT JOIN (
            SELECT username, service, MAX(datetime) AS lastdate
            FROM sasl_log
            GROUP BY username, service
          ) AS last ON sasl_log.username = last.username AND sasl_log.service = last.service
          WHERE datetime < DATE_SUB(NOW(), INTERVAL 31 DAY) AND datetime < lastdate;
        DELETE FROM sasl_log
          WHERE username NOT IN (SELECT username FROM mailbox) AND
          datetime < DATE_SUB(NOW(), INTERVAL 31 DAY);
      END",
    ];
    foreach ($events as $name => $create) {
      try {
        $pdo->exec("DROP EVENT IF EXISTS " . $name);
        $pdo->exec($create);
        echo "Event " . $name . " created" . PHP_EOL;
      } catch (Exception $e) {
        echo "WARNING: could not create event " . $name . ": " . $e->getMessage() . PHP_EOL;
      }
    }
    exit(0);

  default:
    fwrite(STDERR, "Usage: phpfpm-dbinit.php wait|tzcheck|domains|apikeys|events" . PHP_EOL);
    exit(2);
}
