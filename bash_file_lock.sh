#!/bin/bash

# Define the lock file
lockfile="/tmp/locktest"

# Dynamically allocate a random file descriptor
exec {fd}>"$lockfile"

# Acquire the lock using the dynamically chosen file descriptor
flock -x "$fd" || {
  echo "Failed to acquire lock after 10 seconds" >&2
  exit 1
}

 

exec {fd}>&-
exit 0
