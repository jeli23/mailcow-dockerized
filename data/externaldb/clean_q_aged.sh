#!/bin/bash

source /source_env.sh

# External database over TCP + TLS
DBPORT=${DBPORT:-3306}
MYSQL_TLS_OPTS="-h ${DBHOST} -P ${DBPORT} --ssl-ca=${DBSSL_CA} --ssl-verify-server-cert"

MAX_AGE=$(redis-cli --raw -h redis-mailcow -a ${REDISPASS} --no-auth-warning GET Q_MAX_AGE)

if [[ -z ${MAX_AGE} ]]; then
  echo "Max age for quarantine items not defined"
  exit 1
fi

NUM_REGEXP='^[0-9]+$'
if ! [[ ${MAX_AGE} =~ ${NUM_REGEXP} ]] ; then
  echo "Max age for quarantine items invalid"
  exit 1
fi

TO_DELETE=$(mariadb ${MYSQL_TLS_OPTS} -u ${DBUSER} -p${DBPASS} ${DBNAME} -e "SELECT COUNT(id) FROM quarantine WHERE created < NOW() - INTERVAL ${MAX_AGE//[!0-9]/} DAY" -BN)
mariadb ${MYSQL_TLS_OPTS} -u ${DBUSER} -p${DBPASS} ${DBNAME} -e "DELETE FROM quarantine WHERE created < NOW() - INTERVAL ${MAX_AGE//[!0-9]/} DAY"
echo "Deleted ${TO_DELETE} items from quarantine table (max age is ${MAX_AGE//[!0-9]/} days)"
