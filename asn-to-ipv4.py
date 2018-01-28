#!/usr/bin/env python2

import socket
import re

## https://tools.ietf.org/html/rfc3912
def whois_request(domain, server, port=43):
    _sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    _sock.connect((server, port))
    _sock.send("%s\r\n" % domain)
    _result = ""
    while True:
        _data = _sock.recv(1024)
        if not _data:
            break
        _result += _data
    return _result

def get_AS(AS,IPV4=True,IPV6=True):
    ## sanitize ASN
    _asn = re.search("(?:AS)?(\d{1,10})",AS,re.IGNORECASE)
    if not _asn:
        return ""
    _asn = "AS{0}".format(_asn.group(1))
    is6 = ""
    if IPV6:
        is6 = "[6]?" if IPV4 else "6"
    _raw = whois_request("-i origin {0}".format(_asn),"whois.radb.net")
    if _raw:
        _ips= re.findall("^route{0}:\s+(.*?)$".format(is6),_raw,re.MULTILINE)
        return "\n".join(_ips)
    return ""

if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1:
      for i in range(1,len(sys.argv)):
        AS=sys.argv[i]
        print get_AS(AS,IPV6=False)
    else:
      print "Usage:",sys.argv[0],"as32934"
