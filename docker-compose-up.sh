#!/bin/bash

hotcrp_docker_compose_up() {
    source ".hotcrp-docker-conf.env" 2>/dev/null
    if [ $? -ne 0 ]; then
        echo "error: No \".hotcrp-docker-conf.env\" file found. Creating one now. \
You need to edit this file or at least set the environment variables found in it, \
and then run this script again."

        touch ".hotcrp-docker-conf.env" && \
        chmod 640 ".hotcrp-docker-conf.env"

        printf "%b\n" "hotcrp_docker_compose_missing_env_vars() { \n\
    if [ -z \"\$HOTCRP_DBUSER\" ] || \n\
       [ -z \"\$HOTCRP_DBPASSWD\" ] || \n\
       [ -z \"\$HOTCRP_DBROOTUSER\" ] || \n\
       [ -z \"\$HOTCRP_DBROOTPASSWD\" ] || \n\
       [ -z \"\$HOTCRP_LOCAL_MYSQL_DIR\" ] || \n\
       [ -z \"\$HOTCRP_DBHOST\" ] || \n\
       [ -z \"\$HOTCRP_DBPORT\" ] || \n\
       [ -z \"\$HOTCRP_WEBHOST\" ] || \n\
       [ -z \"\$HOTCRP_WEBPORT\" ] || \n\
       [ -z \"\$HOTCRP_WEBSPORT\" ] || \n\
       [ -z \"\$HOTCRP_PHPHOST\" ] || \n\
       [ -z \"\$HOTCRP_PHPPORT\" ] || \n\
       [ -z \"\$HOTCRP_PHPMYADMINHOST\" ] || \n\
       [ -z \"\$HOTCRP_PHPMYADMINPORT\" ] || \n\
       [ -z \"\$HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR\" ] || \n\
       [ -z \"\$HOTCRP_DOCKER_COMPOSE_SUBNET\" ]; then \n\
        echo \"error: Some values are missing in \\".hotcrp-docker-conf.env\\". \n\
If you'd like to generate a new default version of that file, rename your old \n\
version and run this script again.\" \n\
\n\
        return 2 \n\
    fi \n\
} \n\
\n\
hotcrp_docker_compose_set_env_vars() { \n\
    HOTCRP_DBUSER=\${HOTCRP_DBUSER:-\"hotcrp\"}\n\
    HOTCRP_DBPASSWD=\${HOTCRP_DBPASSWD:-\"hotcrppwd\"}\n\
    HOTCRP_DBROOTUSER=\${HOTCRP_DBROOTUSER:-\"root\"}\n\
    HOTCRP_DBROOTPASSWD=\${HOTCRP_DBROOTPASSWD:-\"rootpwd\"}\n\
    HOTCRP_DBHOST=\${HOTCRP_DBHOST:-\"mysql-hotcrp\"}\n\
    HOTCRP_DBPORT=\${HOTCRP_DBPORT:-\"9002\"}\n\
    HOTCRP_LOCAL_MYSQL_DIR=\${HOTCRP_LOCAL_MYSQL_DIR:-\"/var/lib/mysql-hotcrp\"}\n\
    HOTCRP_WEBHOST=\${HOTCRP_WEBHOST:-\"webserver-hotcrp\"}\n\
    HOTCRP_WEBPORT=\${HOTCRP_WEBPORT:-\"9000\"}\n\
    HOTCRP_WEBSPORT=\${HOTCRP_WEBSPORT:-\"9443\"}\n\
    HOTCRP_PHPHOST=\${HOTCRP_PHPHOST:-\"php-fpm-hotcrp\"}\n\
    HOTCRP_PHPPORT=\${HOTCRP_PHPPORT:-\"9001\"}\n\
    HOTCRP_PHPMYADMINHOST=\${HOTCRP_PHPMYADMINHOST:-\"phpmyadmin-hotcrp\"}\n\
    HOTCRP_PHPMYADMINPORT=\${HOTCRP_PHPMYADMINPORT:-\"9005\"}\n\
    HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR=\${HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR:-\"10.0.10.0/24\"}\n\
    HOTCRP_DOCKER_COMPOSE_SUBNET=\${HOTCRP_DOCKER_COMPOSE_SUBNET:-\"hotcrp-docker-compose-net\"}\n\
} \n\
\n\
hotcrp_docker_compose_set_env_vars \$@" | \
        tee ".hotcrp-docker-conf.env" >/dev/null

        return 1
    fi
    
    hotcrp_docker_compose_missing_env_vars
    res_missing_env_vars=$?
    if [ $res_missing_env_vars -ne 0 ]; then
        return $res_missing_env_vars
    fi

    docker network create "${HOTCRP_DOCKER_COMPOSE_SUBNET}" --subnet "${HOTCRP_DOCKER_COMPOSE_SUBNET_ADDR}"

    sudo touch phpdocker/nginx/nginx.conf
    sudo chmod 640 phpdocker/nginx/nginx.conf
    sudo chown root:33 phpdocker/nginx/nginx.conf

    sudo cat "phpdocker/nginx/nginx.conf.tmpl" | \
        sed "s#\${HOTCRP_PHPHOST}#${HOTCRP_PHPHOST}#g" | \
        sed "s#\${HOTCRP_PHPPORT}#${HOTCRP_PHPPORT}#g" | \
            sudo tee "phpdocker/nginx/nginx.conf" >/dev/null

    touch docker-compose.yml
    chmod 640 docker-compose.yml

    cat "docker-compose.yml.tmpl" | \
        sed "s#\${HOTCRP_DOCKER_COMPOSE_SUBNET}#${HOTCRP_DOCKER_COMPOSE_SUBNET}#g" | \
        sed "s#\${HOTCRP_DBUSER}#${HOTCRP_DBUSER}#g" | \
        sed "s#\${HOTCRP_DBPASSWD}#${HOTCRP_DBPASSWD}#g" | \
        sed "s#\${HOTCRP_DBROOTUSER}#${HOTCRP_DBROOTUSER}#g" | \
        sed "s#\${HOTCRP_DBROOTPASSWD}#${HOTCRP_DBROOTPASSWD}#g" | \
        sed "s#\${HOTCRP_LOCAL_MYSQL_DIR}#${HOTCRP_LOCAL_MYSQL_DIR}#g" | \
        sed "s#\${HOTCRP_DBHOST}#${HOTCRP_DBHOST}#g" | \
        sed "s#\${HOTCRP_DBPORT}#${HOTCRP_DBPORT}#g" | \
        sed "s#\${HOTCRP_WEBHOST}#${HOTCRP_WEBHOST}#g" | \
        sed "s#\${HOTCRP_WEBPORT}#${HOTCRP_WEBPORT}#g" | \
        sed "s#\${HOTCRP_WEBSPORT}#${HOTCRP_WEBSPORT}#g" | \
        sed "s#\${HOTCRP_PHPHOST}#${HOTCRP_PHPHOST}#g" | \
        sed "s#\${HOTCRP_PHPPORT}#${HOTCRP_PHPPORT}#g" | \
        sed "s#\${HOTCRP_PHPMYADMINHOST}#${HOTCRP_PHPMYADMINHOST}#g" | \
        sed "s#\${HOTCRP_PHPMYADMINPORT}#${HOTCRP_PHPMYADMINPORT}#g" | \
            tee "docker-compose.yml" >/dev/null

    docker-compose up --build -d "$@"
}

hotcrp_docker_compose_up "$@"
