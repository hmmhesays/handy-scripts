#!/bin/bash

function check_ip4() {
ip="$1"

if [[ $ip =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] && \
   [[ ${BASH_REMATCH[0]} =~ ^(([01]?[0-9]?[0-9]|2[0-4][0-9]|25[0-5])\.){3}([01]?[0-9]?[0-9]|2[0-4][0-9]|25[0-5])$ ]]; then
    echo "Valid IPv4 address"
else
    echo "Invalid IPv4 address"
fi
}
