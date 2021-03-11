#!/bin/bash

hotcrp_docker_compose_up() {
    source ".hotcrp-creds.env" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "error: No \".hotcrp-creds.env\" file found. Creating one now. \
You need to edit this file or at least set these environment variables, and then run this script again."

        touch ".hotcrp-creds.env" && \
        chmod 640 ".hotcrp-creds.env"

        printf "%b\n" "HOTCRP_DBUSER=\${HOTCRP_DBUSER:-\"hotcrp\"}\n\
HOTCRP_DBPASSWD=\${HOTCRP_DBPASSWD:-\"hotcrppwd\"}\n\
HOTCRP_DBROOTUSER=\${HOTCRP_DBROOTUSER:-\"root\"}\n\
HOTCRP_DBROOTPASSWD=\${HOTCRP_DBROOTPASSWD:-\"rootpwd\"}\n\
HOTCRP_DBHOST=\${HOTCRP_DBHOST:-\"hotcrp\"}" | \
                tee ".hotcrp-creds.env"

        return 1
    fi

    HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR=${HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR:-"10.0.10.0/24"}
    HOTCRP_DOCKER_COMPOSE_SUBNET=${HOTCRP_DOCKER_COMPOSE_SUBNET:-"hotcrp-docker-compose-net"}
    HOTCRP_LOCAL_MYSQL_DIR=${HOTCRP_LOCAL_MYSQL_DIR:-"/var/lib/mysql-hotcrp"}

    if [ $# -ge 1 ]; then
        HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR="$1"
    fi

    if [ $# -ge 2 ]; then
        HOTCRP_DOCKER_COMPOSE_SUBNET="$2"
    fi

    docker network create "${HOTCRP_DOCKER_COMPOSE_SUBNET}" --subnet "${HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR}"

    touch docker-compose.yml
    chmod 640 docker-compose.yml

    cat "docker-compose.yml.tmpl" | \
        sed "s#\${HOTCRP_DOCKER_COMPOSE_SUBNET}#${HOTCRP_DOCKER_COMPOSE_SUBNET}#g" | \
        sed "s#\${HOTCRP_LOCAL_MYSQL_DIR}#${HOTCRP_LOCAL_MYSQL_DIR}#g" | \
        sed "s#\${HOTCRP_DBUSER}#${HOTCRP_DBUSER}#g" | \
        sed "s#\${HOTCRP_DBPASSWD}#${HOTCRP_DBPASSWD}#g" | \
        sed "s#\${HOTCRP_DBROOTUSER}#${HOTCRP_DBROOTUSER}#g" | \
        sed "s#\${HOTCRP_DBROOTPASSWD}#${HOTCRP_DBROOTPASSWD}#g" | \
        sed "s#\${HOTCRP_DBHOST}#${HOTCRP_DBHOST}#g" | \
        tee "docker-compose.yml" >/dev/null

    docker-compose up --build -d "$@"
}

hotcrp_docker_compose_up "$@"
