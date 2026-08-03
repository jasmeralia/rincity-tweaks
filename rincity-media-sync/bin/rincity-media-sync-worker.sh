#!/usr/bin/env bash

set -u

STATE=/var/tmp/rincity-media-sync
WP_CONTENT=/usr/local/lsws/wordpress/wp-content
AWS=/usr/local/bin/aws
PHP=/usr/local/lsws/lsphp83/bin/php

mkdir -p "$STATE/queue/put" "$STATE/queue/del" "$STATE/failed"
exec 9>"$STATE/lock"
/usr/bin/flock -n 9 || exit 0

[[ -e "$STATE/DISABLED" ]] && exit 0
[[ "${RINMS_ENABLED:-1}" == "0" ]] && exit 0

bucket=${RINMS_BUCKET:-}
distribution=${RINMS_DISTRIBUTION:-}
region=${RINMS_REGION:-}

if [[ -z "$bucket" || -z "$distribution" || -z "$region" ]]; then
    case "$(hostname)" in
        gelfling)
            bucket=windsofstorm-rincity-s3
            distribution=E2TEG3T8F8AC6S
            region=us-east-1
            ;;
        rinling)
            bucket=windsofstorm-rincity-test-s3
            distribution=EG4OSA4RQXBRS
            region=us-east-1
            ;;
        *)
            logger -p user.err -t rincity-media-sync "unknown hostname; sync disabled"
            exit 0
            ;;
    esac
fi

declare -a invalidation_keys=()
declare -A failed_this_drain=()
put_count=0
del_count=0
failure_count=0

key_for() {
    local path=$1
    local operation=$2
    local resolved component relative

    [[ "$path" == /* ]] || return 1
    [[ "/$path/" != *"/../"* ]] || return 1

    if [[ "$operation" == "put" ]]; then
        resolved=$(realpath -e -- "$path") || return 1
        [[ -f "$resolved" && -r "$resolved" ]] || return 1
    else
        resolved=$(realpath -m -- "$path") || return 1
    fi

    [[ "$resolved" == "$WP_CONTENT/"* ]] || return 1
    [[ "$resolved" != "$STATE" && "$resolved" != "$STATE/"* ]] || return 1
    relative=${resolved#"$WP_CONTENT"/}

    IFS=/ read -r -a components <<< "$relative"
    for component in "${components[@]}"; do
        [[ -n "$component" && "$component" != .* ]] || return 1
    done

    printf 'wp-content/%s' "$relative"
}

retry_or_fail() {
    local operation=$1
    local entry=$2
    local base attempt next

    base=$(basename -- "$entry")
    attempt=0
    if [[ "$base" =~ ^([[:xdigit:]]{40})\.([0-9]+)$ ]]; then
        base=${BASH_REMATCH[1]}
        attempt=${BASH_REMATCH[2]}
    fi
    next=$((attempt + 1))
    failed_this_drain["$operation:$base"]=1
    failure_count=$((failure_count + 1))

    if (( next >= 5 )); then
        mv -f -- "$entry" "$STATE/failed/$operation-$base.$next"
        logger -p user.err -t rincity-media-sync \
            "$operation failed permanently after $next attempts: $(<"$STATE/failed/$operation-$base.$next")"
    else
        mv -f -- "$entry" "$(dirname -- "$entry")/$base.$next"
    fi
}

process_put() {
    local entry=$1
    local path key existed=0

    IFS= read -r path < "$entry" || path=
    if ! key=$(key_for "$path" put); then
        retry_or_fail put "$entry"
        return
    fi

    if "$AWS" s3api head-object --bucket "$bucket" --key "$key" --region "$region" >/dev/null 2>&1; then
        existed=1
    fi
    if "$AWS" s3 cp --only-show-errors --region "$region" "$path" "s3://$bucket/$key"; then
        rm -f -- "$entry"
        put_count=$((put_count + 1))
        if (( existed )); then
            invalidation_keys+=("$key")
        fi
    else
        retry_or_fail put "$entry"
    fi
}

process_del() {
    local entry=$1
    local path key

    IFS= read -r path < "$entry" || path=
    if ! key=$(key_for "$path" del); then
        retry_or_fail del "$entry"
        return
    fi

    if "$AWS" s3 rm --only-show-errors --region "$region" "s3://$bucket/$key"; then
        rm -f -- "$entry"
        del_count=$((del_count + 1))
        invalidation_keys+=("$key")
    else
        retry_or_fail del "$entry"
    fi
}

shopt -s nullglob
while true; do
    processed=0
    for operation in put del; do
        for entry in "$STATE/queue/$operation"/*; do
            base=$(basename -- "$entry")
            [[ "$base" =~ ^([[:xdigit:]]{40})(\.[0-9]+)?$ ]] || continue
            identity="$operation:${BASH_REMATCH[1]}"
            [[ -z "${failed_this_drain[$identity]+x}" ]] || continue
            if [[ "$operation" == "put" ]]; then
                process_put "$entry"
            else
                process_del "$entry"
            fi
            processed=$((processed + 1))
        done
    done
    (( processed > 0 )) || break
done

invalidation_count=0
if (( ${#invalidation_keys[@]} > 0 )); then
    declare -A seen=()
    declare -a encoded_paths=()
    for key in "${invalidation_keys[@]}"; do
        [[ -z "${seen[$key]+x}" ]] || continue
        seen["$key"]=1
        encoded_paths+=("$(RINMS_KEY="/$key" "$PHP" -r \
            'echo implode("/", array_map("rawurlencode", explode("/", getenv("RINMS_KEY"))));')")
    done

    for (( offset = 0; offset < ${#encoded_paths[@]}; offset += 500 )); do
        batch=("${encoded_paths[@]:offset:500}")
        if "$AWS" cloudfront create-invalidation \
            --distribution-id "$distribution" --paths "${batch[@]}" --output json >/dev/null; then
            invalidation_count=$((invalidation_count + ${#batch[@]}))
        else
            logger -p user.err -t rincity-media-sync \
                "CloudFront invalidation failed for ${#batch[@]} exact path(s)"
        fi
    done
fi

if (( put_count + del_count + failure_count > 0 )); then
    logger -p user.info -t rincity-media-sync \
        "drain complete: put=$put_count del=$del_count invalidated=$invalidation_count failures=$failure_count"
fi

exit 0
