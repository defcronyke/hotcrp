#!/bin/bash

hotcrp_docker_compose_down() {
    docker-compose down "$@"
}

hotcrp_docker_compose_down "$@"
