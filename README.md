# pfSense-enhancements

###### A collection of scripts for my pfSense environment that perform tasks not natively supported by pfSense.

### dyndyn_monitor.php
#### The problem:
pfSense doesn't appear to account for dynamic DNS in HA environments in any way. Dyndns config is not synced via XMLRPC, and dyndns hosts can't track master/backup status. In a real HA environment, where you have a static address from your ISP set as your carp vip, that's perfectly fine. Your public IP address doesn't change during a failover event, and you can set up identical, enabled dyndns entries on both pfSense systems.

Since I am limited to obtaining public IP addresses from my ISP via DHCP, I can't run carp on my WAN interface. However, I can get two public IP addresses from my ISP via DHCP, so I can at least run a second pfSense instance. Some redundancy is better than none. I have a carp vip address on my LAN, but not my WAN. If my primary pfSense instance fails, the backup takes over. States are invalidated, but service for the internal users is rapidly restored without any manual administrative intervention.

I still need to tell my DNS provider to update my external IP address to the one assigned to the backup WAN interface. It takes a few minutes for the DNS cache to catch up to my IP address change, but again some redunancy is better than nothing.

#### The solution:
I set up identical dyndns entries on both the primary and backup, with the one on the primary enabled and the one on the secondary disabled.

When this script fires, it checked the carp status on an interface you specify (i.e. lan). If carp status is master, it ensures the dyndns host you specify is enabled. If carp status is not master, it ensures the dyndns host is disabled. Thus, when failover occurs, if this script is run on both the primary and backup, it will disable dyndns on the failed primary, and enable it on the new master.

The script can be called from cron or devd on carp events, as you prefer.

```
Usage:  dyndns_monitor.php [-i interface] [-d dyndns hostname]

Watches CARP status on an interface. Disables specified service for the
backup, and enables it for the master.

        -i, --interface string   CARP interface to monitor
        -d, --dyndns string      Dynamin DNS hostname to enable/disable
        -q, --quiet              Suppress output
        -h, --help               Print usage
```

Example:
```
# php dyndns_monitor.php -i lan -d all.dnsomatic.com
```

### wpa_auth.php

#### The problem:

pfSense thinks you only need to use WPA credentials on wireless interfaces. However, some wired networks require 802.1x authentication, which also uses the WPA supplicant. The good news is pfSense has a WPA supplicant installed. The bad news is there's no method to configure a wired interface to use it.

#### The solution:

This script will initiate the WPA supplicant on a wired interface of your choosing. It will take either a md4 hash or plaintext password as input. You can also use it to just generate a md4 hash of your plaintext password.

My implementation uses PEAP and MSCHAPv2. You may need to modify the script if your provider uses different parameters. Please send me a pull request if you do.

It will exit silently if the supplicant is already running and authenticated, so you can call the script from cron. I don't call it from shellcmd, because the system will wait for the script to complete before resuming the startup process. Also, if you call it from cron, it will automatically reauthenticate as needed.

```
Usage:  wpa_auth.php [-i interface] [-u identity] [-p password]

Performs 802.1x authentication on a pfSense system.
Usage of a hashed password is highly recommended, but not mandatory.

        -i, --interface string   Interface to authenticate
        -c, --carp string        Run only if this system is a CARP master for this interface
        -u, --identity string    Identity (i.e. username)
        -p, --password string    Password
            --hash string        Converts plaintext password to a hash
            --force              Force run, even if already successfully authenticated
        -q, --quiet              Suppress output
        -h, --help               Print usage
```

Convert plaintext password to hash:
```
# php wpa_auth.php --hash testpass123
1efb50581482d84f9e57737b846a32b0
```

Start supplicant:
```
# php wpa_auth.php -i wan -u youridentity -p 1efb50581482d84f9e57737b846a32b0
```

Start supplicant and monitor carp so that it only runs on the master:
```
# php wpa_auth.php -i wan -c lan -u youridentity -p 1efb50581482d84f9e57737b846a32b0
```

Checking authentication status:
```
# wpa_cli status
Selected interface 'lan'
bssid=xx:xx:xx:xx:xx:xx
freq=0
ssid=
id=0
mode=station
pairwise_cipher=NONE
group_cipher=NONE
key_mgmt=IEEE 802.1X (no WPA)
wpa_state=COMPLETED
ip_address=x.x.x.x
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
```
