#!/bin/bash

hotcrp_docker_compose_destroy_database() {
    source ".hotcrp-docker-conf.env" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "error: No \".hotcrp-docker-conf.env\" file found. Please run the \"./docker-compose-up.sh\" script first."
        return 1
    fi

    if [ $# -lt 1 ] || [ "$1" != "-f" ]; then
        echo "Are you sure? Force-delete all the volumes by passing this flag: -f"
        return 2
    fi

    docker rm $(docker ps -a -q) -f

    docker-compose rm -sfv ${HOTCRP_DBHOST} ${HOTCRP_WEBHOST} ${HOTCRP_PHPHOST} ${HOTCRP_PHPMYADMINHOST}
}

hotcrp_docker_compose_destroy_database "$@"
