#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${OLD_POSTS_ENV_FILE:-$SCRIPT_DIR/old-posts.env}"

if [[ -f "$ENV_FILE" ]]; then
	# shellcheck source=/dev/null
	source "$ENV_FILE"
fi

: "${OLD_POSTS_WP_ROOT:?Set OLD_POSTS_WP_ROOT in old-posts.env or in the current shell.}"

export OLD_POSTS_TOOL_DIR="${OLD_POSTS_TOOL_DIR:-$SCRIPT_DIR}"
export OLD_POSTS_OUTPUT_DIR="${OLD_POSTS_OUTPUT_DIR:-$SCRIPT_DIR/output}"

mkdir -p "$OLD_POSTS_OUTPUT_DIR"

WP_ARGS=( "--path=$OLD_POSTS_WP_ROOT" )

if [[ "${OLD_POSTS_SKIP_THEMES:-1}" == "1" ]]; then
	WP_ARGS+=( "--skip-themes" )
fi

if [[ -n "${OLD_POSTS_SKIP_PLUGINS:-}" ]]; then
	WP_ARGS+=( "--skip-plugins=$OLD_POSTS_SKIP_PLUGINS" )
fi

CLI_ARGS=( "$@" )

if [[ "${CLI_ARGS[0]:-}" == "eval-file" && -n "${CLI_ARGS[1]:-}" && "${CLI_ARGS[1]}" != /* ]]; then
	if [[ -f "$SCRIPT_DIR/${CLI_ARGS[1]}" ]]; then
		CLI_ARGS[1]="$SCRIPT_DIR/${CLI_ARGS[1]}"
	fi
fi

exec wp "${WP_ARGS[@]}" "${CLI_ARGS[@]}"
