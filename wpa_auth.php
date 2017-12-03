#!/usr/local/bin/php-cgi -f
<?php
/*
Performs 802.1x authentication on a pfSense system.

# php wpa_auth.php --hash testpass123
1efb50581482d84f9e57737b846a32b0

# php wpa_auth.php --interface vmx2 --identity myusername --password 1efb50581482d84f9e57737b846a32b0

# wpa_cli status
Selected interface 'vmx2'
bssid=xx:xx:xx:xx:xx:xx
freq=0
ssid=
id=0
mode=station
pairwise_cipher=NONE
group_cipher=NONE
key_mgmt=IEEE 802.1X (no WPA)
wpa_state=COMPLETED
ip_address=1.2.3.4
address=xx:xx:xx:xx:xx:xx
Supplicant PAE state=AUTHENTICATED
suppPortStatus=Authorized
EAP state=SUCCESS
selectedMethod=25 (EAP-PEAP)
eap_tls_version=TLSv1.2
EAP TLS cipher=ECDHE-RSA-AES256-GCM-SHA384
tls_session_reused=0
EAP-PEAPv1 Phase2 method=MSCHAPV2
eap_session_id=<redacted>
uuid=<redacted>
*/

require_once('interfaces.inc');

$opts = get_options();
$interface = get_interface_by_name($opts['interface']);

// If a carp interface was provided, this system must be master on that interface.
if (array_key_exists('carp', $opts) && !is_carp_master($opts['carp'])) {
  if (!$opts['quiet'])
    echo('CARP is running on this system, but interface "' . $opts['carp'] . '" is not a master.' . PHP_EOL);
  if (get_supplicant_pid($interface)) {
    echo('System is not master, terminating supplicant.' . PHP_EOL);
    terminate_supplicant($interface);
  }
  exit();
}

// Stop here and do nothing if already authenticated, unless --force specified.
if (get_supplicant_status($interface) == 'SUCCESS') {
  if (!$opts['force']) {
    if (!$opts['quiet'])
      echo('Already authenticated. Use --force to restart authentication.' . PHP_EOL);
    exit();
  } else {
    echo('Forcing supplicant termination.' . PHP_EOL);
    terminate_supplicant($interface);
  }
}

// Ensure only one instance of this script is running from this point forward.
$lock = new Lock();

// Any supplicant running at this point isn't doing anything useful for us.
if (get_supplicant_pid($interface)) {
  echo('Supplicant running but not authenticated.' . PHP_EOL . 'Terminating supplicant.' . PHP_EOL);
  terminate_supplicant($interface);
}

init_supplicant($interface, $opts['identity'], $opts['password']);

echo('Waiting for authorization.' . PHP_EOL);
while (true) {
  switch (get_supplicant_status($interface)) {
    case 'SUCCESS':
      exit('Authorization completed.' . PHP_EOL);
    case 'FAILURE':
      terminate_supplicant($interface);
      die('Authorization FAILED.');
  }
  sleep(1);
}

function get_interface_by_name ($interface) {
  foreach (get_configured_interface_with_descr() as $name => $desc) {
    if (strtolower($interface) == strtolower($desc)) {
      $arr = get_interface_info($name);
      $arr['name'] = $name;
      $arr['desc'] = $interface;
    }
  }

  if (is_null($arr))
    die('Interface "' . $interface . '" is not valid.' . PHP_EOL);
  elseif ($arr['status'] != 'up')
    die('Interface "' . $interface . '" is not up.' . PHP_EOL);
  else
    return $arr;
}

function is_carp_master ($interface) {
  if (!get_carp_status())
    die('CARP interface "' . $interface . '" specified, but CARP is not running on this system.' . PHP_EOL);

  global $config;
  $carp_interface = get_interface_by_name($interface);
  foreach ($config['virtualip']['vip'] as $vip) {
    if ($carp_interface['name'] == $vip['interface'] && $vip['mode'] == 'carp')
      $vip_status = get_carp_interface_status("_vip{$vip['uniqid']}");
  }

  if (!$vip_status)
    die('CARP is not configured for interface "' . $interface . '".' . PHP_EOL);
  elseif ($vip_status == 'MASTER')
    return true;
}

function get_password_hash ($password) {
  if (strlen($password) == 32 && ctype_xdigit($password))
    return $password;
  else
    return hash('md4', mb_convert_encoding($password, 'UTF-16LE'));
}

function get_supplicant_pid($interface) {
  return exec('pgrep -f "wpa_supplicant .* ' . $interface['hwif'] . '"');
}

function get_supplicant_status($interface) {
  if (get_supplicant_pid($interface))
    return exec('wpa_cli status | grep "EAP state" | cut -d= -f2');
}

function terminate_supplicant($interface) {
  if ($pid = get_supplicant_pid($interface)) {
    exec('wpa_cli -i ' . $interface['hwif'] . ' terminate');
    return true;
  } else {
    return false;
  }
}

function init_supplicant($interface, $identity, $password) {
  $res = shell_exec('wpa_supplicant -D wired -i ' . $interface['hwif'] . ' -C /var/run/wpa_supplicant -B');

  // Start wpa_supplicant
  if (strstr($res, 'Successfully initialized wpa_supplicant'))
    echo('Started supplicant on interface ' . $interface['hwif'] . ' (' . $interface['desc'] . '/' . $interface['name'] . ').' . PHP_EOL);
  else
    die('Error initiating the supplicant: ' . PHP_EOL . $res . PHP_EOL);

  $auth_params = array(
    'ap_scan 0',
    'add_network 0',
    'set_network 0 key_mgmt IEEE8021X',
    'set_network 0 eap PEAP',
    'set_network 0 eapol_flags 0',
    'set_network 0 phase2 \\"auth=MSCHAPV2\\"',
    'set_network 0 identity \\"' . $identity . '\\"',
    'set_network 0 password hash:' . $password,
    'enable_network 0',
  );

  echo('Loading parameters to supplicant.' . PHP_EOL);

  foreach ($auth_params as $param) {
    $res = shell_exec('wpa_cli -i ' . $interface['hwif'] . ' ' . $param);
    if (!preg_match('/^(OK|0)$/', $res)) {
      echo('Error loading parameter:' . PHP_EOL . $res . PHP_EOL);
      terminate_supplicant($interface);
      die('Supplicant terminated.' . PHP_EOL);
    }
  }

  echo('Finished loading parameters.' . PHP_EOL);

  return true;
}

function get_options () {
  $help = (PHP_EOL .
    'Usage:  ' . basename(__FILE__) . ' [-i interface] [-u identity] [-p password]' . PHP_EOL
    . PHP_EOL
    . 'Performs 802.1x authentication on a pfSense system.' . PHP_EOL
    . 'Usage of a hashed password is highly recommended, but not mandatory.' . PHP_EOL
    . PHP_EOL
    . '        -i, --interface string   Interface to authenticate' . PHP_EOL
    . '        -c, --carp string        Run only if this system is a CARP master for this interface' . PHP_EOL
    . '        -u, --identity string    Identity (i.e. username)' . PHP_EOL
    . '        -p, --password string    Password' . PHP_EOL
    . '            --hash string        Converts plaintext password to a hash' . PHP_EOL
    . '            --force              Force run, even if already successfully authenticated' . PHP_EOL
    . '        -q, --quiet              Suppress output' . PHP_EOL
    . '        -h, --help               Print usage' . PHP_EOL
    . PHP_EOL
  );

  $opts = getopt(
    'i:c:u:p:qh',
    array(
      'interface:',
      'carp:',
      'identity:',
      'password:',
      'hash:',
      'force',
      'quiet',
      'help',
    )
  );

  if (array_key_exists('h', $opts) || array_key_exists('help', $opts))
    exit($help);

  if (array_key_exists('hash', $opts))
    exit(get_password_hash($opts['hash']) . PHP_EOL);

  if (array_key_exists('i', $opts))
    $opts['interface'] = $opts['i'];

  if (array_key_exists('c', $opts))
    $opts['carp'] = $opts['c'];

  if (array_key_exists('u', $opts))
    $opts['identity'] = $opts['u'];

  if (array_key_exists('p', $opts))
    $opts['password'] = $opts['p'];

  if (array_key_exists('q', $opts) || array_key_exists('quiet', $opts))
    $opts['quiet'] = true;

  if (array_key_exists('force', $opts))
    $opts['force'] = true;

  if (!array_key_exists('interface', $opts))
    die('ERROR: interface is a required parameter.' . PHP_EOL . $help);

  if (!array_key_exists('identity', $opts))
    die('ERROR: identity is a required parameter.' . PHP_EOL . $help);

  if (!array_key_exists('password', $opts))
    die('ERROR: password is a required parameter.'  . PHP_EOL . $help);
  else {
    $opts['password'] = get_password_hash($opts['password']);
  }

  // We don't need these anymore.
  unset (
    $opts['i'],
    $opts['c'],
    $opts['u'],
    $opts['p'],
    $opts['q']
  );

  return $opts;
}

class Lock {
  private $fp;
  function __construct() {
    $this->fp = fopen(__FILE__, 'r');
    if (!flock($this->fp, LOCK_EX|LOCK_NB))
      die('Another instance of ' . basename(__FILE__) . ' is already running.' . PHP_EOL);
  }
  function __destruct() {
    flock($this->fp, LOCK_UN);
    fclose($this->fp);
  }
}
?>
