#!/usr/bin/env php
<?php

require_once('functions.inc');

$opts = get_options();

$dyndns = &$config['dyndnses']['dyndns'][get_dyndns_id_by_host($opts['dyndns'])];

// This system must be master to enable specified dyndns hostname.
if (!is_carp_master($opts['interface']) && array_key_exists('enable', $dyndns)) {
  if (!$opts['quiet'])
    echo('Interface "' . $opts['interface'] . '" is not the master. Disabling dyndns for host ' . $opts['dyndns'] . '.' . PHP_EOL);
  unset($dyndns['enable']);
  write_config('Dynamic DNS client disabled.');
  services_dyndns_configure();
}
elseif (is_carp_master($opts['interface']) && !array_key_exists('enable', $dyndns)) {
  if (!$opts['quiet'])
    echo('Enabling dyndns for host ' . $opts['dyndns'] . '.' . PHP_EOL);
  $dyndns['enable'] = true;
  $dyndns['force'] = true;
  write_config('Dynamic DNS client enabled.');
  services_dyndns_configure();
}

function get_dyndns_id_by_host ($host) {
  global $config;
  $dynarr = &$config['dyndnses']['dyndns'];

  for ($i = 0; $i < count($dynarr); $i++) {
    if (strtolower($dynarr[$i]['host']) == strtolower($host))
      $key_id = $i;
  }

  if (!is_null($key_id))
    return $key_id;
  else
    die('Could not find ' . $host . ' as a configured dyndns entry.' . PHP_EOL);
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
    die('CARP is not running on this system.' . PHP_EOL);

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

function get_options () {
  $help = (PHP_EOL .
    'Usage:  ' . basename(__FILE__) . ' [-i interface] [-d dyndns hostname]' . PHP_EOL
    . PHP_EOL
    . 'Watches CARP status on an interface. Disables specified service for the' . PHP_EOL
    . 'backup, and enables it for the master.' . PHP_EOL
    . PHP_EOL
    . '        -i, --interface string   CARP interface to monitor' . PHP_EOL
    . '        -d, --dyndns string      Dynamin DNS hostname to enable/disable' . PHP_EOL
    . '        -q, --quiet              Suppress output' . PHP_EOL
    . '        -h, --help               Print usage' . PHP_EOL
    . PHP_EOL
  );

  $opts = getopt(
    'i:d:h',
    array(
      'interface:',
      'dyndns:',
      'help',
    )
  );

  if (array_key_exists('h', $opts) || array_key_exists('help', $opts))
    exit($help);

  if (array_key_exists('i', $opts))
    $opts['interface'] = $opts['i'];

  if (array_key_exists('d', $opts))
    $opts['dyndns'] = $opts['d'];

  if (!array_key_exists('interface', $opts))
    die('ERROR: interface is a required parameter.' . PHP_EOL . $help);

  if (!array_key_exists('dyndns', $opts))
    die('ERROR: dyndns hostname is a required parameter.' . PHP_EOL . $help);

  // We don't need these anymore.
  unset (
    $opts['i'],
    $opts['d']
  );

  return $opts;
}
