#!/bin/sh
# Runs tests/integration.php against throwaway Docker containers, one image at a time, and removes them.
#
#   sh tests/run-docker.sh mysql:8.4 mysql:9.7 mariadb:10.11 mariadb:11.8 postgres:15 postgres:18
#
# Needs docker and PHP 8.3+ on the machine that runs it.
set -u
cd "$(dirname "$0")/.." || exit 2
failed=''
for image in "$@"; do
    name="vdbm-test-$(echo "$image" | tr ':./' '---')"
    docker rm -f "$name" >/dev/null 2>&1
    case "$image" in
        mysql:*)
            flavor=mysql; bin=mysql; ready='init process done'
            docker run -d --name "$name" --memory 1g -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=vdbm "$image" >/dev/null ;;
        mariadb:*)
            flavor=mariadb; bin=mariadb; ready='init process done'
            docker run -d --name "$name" --memory 1g -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=vdbm "$image" >/dev/null ;;
        postgres:*)
            flavor=postgresql; bin=psql; ready='init process complete'
            docker run -d --name "$name" --memory 1g -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=vdbm "$image" >/dev/null ;;
        *) echo "unknown image $image"; continue ;;
    esac
    if [ "$flavor" = postgresql ]; then
        client="docker exec -i -u postgres $name psql"
        probe="docker exec -u postgres $name psql -X -q -d vdbm -c 'SELECT 1'"
    else
        client="docker exec -i $name $bin"
        probe="docker exec $name $bin --database=vdbm -e 'SELECT 1'"
    fi
    i=0
    # the image's entrypoint runs a temporary server first: wait for the real one
    until docker logs "$name" 2>&1 | grep -q "$ready" && sh -c "$probe" >/dev/null 2>&1; do
        i=$((i + 1))
        if [ $i -ge 180 ]; then
            echo "$image did not start"; docker logs --tail 20 "$name"; break
        fi
        sleep 1
    done
    echo "=== $image"
    if ! php tests/integration.php "$flavor" "$client" vdbm "docker exec $name"; then
        failed="$failed $image"
    fi
    docker rm -f "$name" >/dev/null 2>&1
done
if [ -n "$failed" ]; then
    echo "FAILED:$failed"
    exit 1
fi
echo 'all passed'
