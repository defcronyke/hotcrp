#!/bin/bash

hotcrp_docker_compose_first_run() {
    source ".hotcrp-creds.env" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "error: No \".hotcrp-creds.env\" file found. Please run the \"./docker-compose-up.sh\" script first."
        return 1
    fi

    docker exec -i -t hotcrp-database /bin/bash -c \
        "apt-get update && apt-get install -y procps"

    # Only on first run: Initialize database
    # create database
    # select no when asked for database creation, only fill database with scheme!!!!!
    # ok -> hotcrp -> n -> Y
    docker exec -i -t hotcrp-database /bin/bash -c \
        "./lib/createdb.sh --dbuser=hotcrp,hotcrppwd --user=root --password=rootpwd"

    # control conf/options.php may add:
    sudo cat conf/options.php | grep -P "\\\$Opt\[\"dbHost\"\] = \"" >/dev/null
    if [ $? -eq 0 ]; then
        sudo sed -i -E "s#(\\\$Opt\[\"dbHost\"\] \= \")(.*)(\")#\1mysql\3\;#g" conf/options.php >/dev/null
    else
        echo "\$Opt[\"dbHost\"] = \"mysql\";" | \
            sudo tee -a conf/options.php >/dev/null
    fi
    
    sudo chown root:33 conf/options.php
}

hotcrp_docker_compose_first_run "$@"
