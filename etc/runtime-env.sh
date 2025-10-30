#!/usr/bin/env bash
set -e

APP_IP="$(ip addr show eth0 | grep 'inet\b' | awk '{print $2}' | cut -d/ -f1)"

export WEBDRIVER_HOST="selenium-chrome"
export WEBDRIVER_URL="http://$APP_IP/"
