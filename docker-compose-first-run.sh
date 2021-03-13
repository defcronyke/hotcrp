#!/bin/bash

hotcrp_docker_compose_first_run() {
    source ".hotcrp-docker-conf.env" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "error: No \".hotcrp-docker-conf.env\" file found. Please run the \"./docker-compose-up.sh\" script first."
        return 1
    fi

    hotcrp_docker_compose_missing_env_vars
    res_missing_env_vars=$?
    if [ $res_missing_env_vars -ne 0 ]; then
        return $res_missing_env_vars
    fi

    docker exec -i -t ${HOTCRP_DBHOST} /bin/bash -c \
        "apt-get update && apt-get install -y procps"

    # Only on first run: Initialize database
    # create database
    # select no when asked for database creation, only fill database with scheme!!!!!
    # ok -> hotcrp -> n -> Y
    docker exec -i -t ${HOTCRP_DBHOST} /bin/bash -c \
        "./lib/createdb.sh --dbuser=${HOTCRP_DBUSER},${HOTCRP_DBPASSWD} --user=${HOTCRP_DBROOTUSER} --password=${HOTCRP_DBROOTPASSWD}"

    # control conf/options.php may add:
    sudo chown root:33 conf/options.php

    sudo cat conf/options.php | grep -P "\\\$Opt\[\"dbHost\"\] = \"" >/dev/null
    if [ $? -eq 0 ]; then
        sudo sed -i -E "s#(\\\$Opt\[\"dbHost\"\] \= \")(.*)(\"\;)#\1${HOTCRP_DBHOST}\3#g" conf/options.php >/dev/null
    else
        echo "\$Opt[\"dbHost\"] = \"${HOTCRP_DBHOST}\";" | \
            sudo tee -a conf/options.php >/dev/null
    fi
}

hotcrp_docker_compose_first_run "$@"
