# shellcheck shell=bash disable=SC2154  # $here is set by the sourcing script
# Sourced by bin/laya-*: `laya_compose <args>` runs docker compose against docker/compose.yaml
# (plus the CUDA override when LAYA_CUDA=1 and LAYA_COMPOSE_OVERRIDE when set). Expects $here to be the repository root.

if ! command -v docker >/dev/null 2>&1; then
    echo "laya: docker is not installed or not on PATH; install Docker to run the local Laya server." >&2
    exit 127
fi

laya_compose_files=(-f "$here/docker/compose.yaml")
if [[ "${LAYA_CUDA:-0}" == "1" ]]; then
    laya_compose_files+=(-f "$here/docker/compose.cuda.yaml")
fi
# One more override of your own (extra networks, resource limits, a proxy...).
if [[ -n "${LAYA_COMPOSE_OVERRIDE:-}" ]]; then
    laya_compose_files+=(-f "$LAYA_COMPOSE_OVERRIDE")
fi

laya_compose() {
    docker compose "${laya_compose_files[@]}" "$@"
}
