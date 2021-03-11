#!/bin/bash

hotcrp_docker_compose_destroy_database() {
    if [ $# -lt 1 ] || [ "$1" != "-f" ]; then
        echo "Are you sure? Force-delete all the volumes by passing this flag: -f"
        return 1
    fi

    docker rm $(docker ps -a -q) -f

    # docker volume prune

    docker-compose rm -sfv mysql webserver php-fpm phpmyadmin
}

hotcrp_docker_compose_destroy_database "$@"
